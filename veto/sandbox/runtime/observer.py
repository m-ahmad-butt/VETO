"""Trusted external observer: inotify, SQL audit, snapshots, DNS/HTTP sink.

Evidence stays in this container and is never mounted into the plugin container.
WP HTTP telemetry is application-scope evidence, not tamper-proof syscall tracing.
"""
import ctypes
from datetime import datetime, timezone
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import re
import select
import socket
import struct
import subprocess
import threading
import time

LOCK = threading.RLock()
SNAPSHOT_LOCK = threading.Lock()
STATE = {'ready': False, 'stage': 'baseline', 'events': [], 'errors': [], 'snapshots': {}, 'heartbeats': {}, 'baseline': None}
STOP = threading.Event()
START = time.monotonic()
MAX_EVENTS = 12000

def timestamp():
    return datetime.now(timezone.utc).isoformat().replace('+00:00', 'Z')

def error(message):
    with LOCK:
        if message not in STATE['errors'] and len(STATE['errors']) < 50:
            STATE['errors'].append(message[:512])

def emit(category, action, resource, before=None, after=None, collector='observer', detail=''):
    with LOCK:
        if len(STATE['events']) >= MAX_EVENTS:
            error('Observer event limit exceeded; coverage incomplete')
            return
        STATE['events'].append({'time': timestamp(), 'stage': STATE['stage'], 'category': category,
            'action': action, 'resource': str(resource)[:2048], 'before': before, 'after': after,
            'collector': collector, 'detail': str(detail)[:2048]})

def guarded(name, function):
    def wrapped():
        try:
            function()
        except Exception as exc:
            error(f'{name}: {type(exc).__name__}: {exc}')
        finally:
            if not STOP.is_set():
                error(f'{name} collector stopped unexpectedly')
    thread = threading.Thread(target=wrapped, daemon=True)
    thread.start()
    return thread

def file_state():
    result = {}
    for directory, dirs, files in os.walk('/site', followlinks=False):
        dirs[:] = [d for d in dirs if not Path(directory, d).is_symlink()]
        for filename in files:
            path = Path(directory, filename)
            if path.is_symlink():
                result[str(path.relative_to('/site'))] = {'symlink': os.readlink(path)}
                continue
            if len(result) >= 10000:
                raise RuntimeError('Snapshot file-count limit exceeded')
            digest = hashlib.sha256()
            size = 0
            fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
            with os.fdopen(fd, 'rb') as stream:
                while chunk := stream.read(65536):
                    size += len(chunk)
                    if size > 16 * 1024 * 1024:
                        raise RuntimeError('Snapshot per-file byte limit exceeded')
                    digest.update(chunk)
            result[str(path.relative_to('/site'))] = {'sha256': digest.hexdigest(), 'bytes': size}
    return result

def snapshot(label):
    with SNAPSHOT_LOCK:
        proc = subprocess.run(['php', '/opt/veto/snapshot.php'], capture_output=True, timeout=15)
        if proc.returncode or len(proc.stdout) > 4 * 1024 * 1024:
            raise RuntimeError('Database snapshot failed: ' + proc.stderr.decode(errors='replace')[:300])
        database = json.loads(proc.stdout)
        # PHP encodes empty associative maps as []; normalize to dictionaries.
        for key in ('tables', 'accounts', 'cron'):
            database[key] = database[key] or {}
        current = {'time': timestamp(), 'files': file_state(), **database}
        with LOCK:
            previous = STATE.get('latest')
            if previous is not None:
                for section, category in (('files','file'), ('tables','database'), ('accounts','account'), ('cron','cron')):
                    for key in sorted(previous[section].keys() | current[section].keys()):
                        before, after = previous[section].get(key), current[section].get(key)
                        if before != after:
                            action = 'created' if before is None else 'deleted' if after is None else 'modified'
                            emit(category, action, key,
                                 json.dumps(before, sort_keys=True) if before is not None else None,
                                 json.dumps(after, sort_keys=True) if after is not None else None,
                                 'baseline-diff', f'{section}: compared with previous snapshot')
            STATE['latest'] = current
            STATE['snapshots'][label] = current
            if STATE['baseline'] is None:
                STATE['baseline'] = current
        return current

def files():
    libc = ctypes.CDLL(None, use_errno=True)
    fd = libc.inotify_init1(os.O_NONBLOCK | os.O_CLOEXEC)
    if fd < 0:
        raise OSError(ctypes.get_errno(), 'inotify_init1')
    watches = {}
    mask = 0x100 | 0x200 | 0x8 | 0x40 | 0x80 | 0x4 | 0x400 | 0x800
    def watch(directory):
        if len(watches) > 4096:
            raise RuntimeError('inotify watch limit exceeded')
        wd = libc.inotify_add_watch(fd, os.fsencode(directory), mask)
        if wd < 0:
            raise OSError(ctypes.get_errno(), 'inotify_add_watch')
        watches[wd] = directory
    try:
        for directory, dirs, names in os.walk('/site', followlinks=False):
            dirs[:] = [d for d in dirs if not Path(directory, d).is_symlink()]
            watch(directory)
        STATE['heartbeats']['inotify'] = timestamp()
        while not STOP.is_set():
            if not select.select([fd], [], [], .2)[0]:
                STATE['heartbeats']['inotify'] = timestamp()
                continue
            data = os.read(fd, 65536)
            offset = 0
            while offset < len(data):
                wd, event_mask, cookie, size = struct.unpack_from('iIII', data, offset)
                name = os.fsdecode(data[offset+16:offset+16+size].split(b'\0')[0])
                offset += 16 + size
                if event_mask & 0x4000:
                    error('inotify queue overflow; transient file coverage incomplete')
                    continue
                if wd not in watches:
                    continue
                path = Path(watches[wd], name)
                if event_mask & 0x40000000 and event_mask & (0x100 | 0x80) and not path.is_symlink():
                    for directory, dirs, names in os.walk(path, followlinks=False):
                        dirs[:] = [d for d in dirs if not Path(directory, d).is_symlink()]
                        watch(directory)
                actions = [(0x100,'created'),(0x8,'closed_after_write'),(0x200,'deleted'),(0x40,'moved_from'),(0x80,'moved_to'),(0x4,'attributes_changed')]
                for flag, action in actions:
                    if event_mask & flag:
                        emit('file', action, str(path.relative_to('/site')), collector='inotify', detail=f'kernel event; rename cookie={cookie}')
                if event_mask & 0x8000:
                    watches.pop(wd, None)
    finally:
        os.close(fd)

def sql_audit():
    path = Path('/mysql/general.log')
    for _ in range(100):
        if path.exists():
            break
        time.sleep(.1)
    with path.open(errors='replace') as stream:
        stream.seek(0, 2)
        STATE['heartbeats']['sql_audit'] = timestamp()
        while not STOP.is_set():
            line = stream.readline(16384)
            if not line:
                STATE['heartbeats']['sql_audit'] = timestamp()
                time.sleep(.1)
                continue
            match = re.search(r'\bQuery\s+(.+)', line)
            if match and re.match(r'\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|TRUNCATE)\b', match[1], re.I):
                emit('database','sql_mutation_attempt','veto database',collector='mysql-general-log',detail=match[1])

def telemetry():
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        sock.bind(('0.0.0.0',9009))
        sock.settimeout(.2)
        STATE['heartbeats']['http_hook'] = timestamp()
        while not STOP.is_set():
            try:
                data, peer = sock.recvfrom(8192)
            except socket.timeout:
                STATE['heartbeats']['http_hook'] = timestamp()
                continue
            try:
                event = json.loads(data)
                emit('network',str(event['action'])[:80],event['resource'],collector='wordpress-http-hook',detail=json.dumps(event.get('detail',{})))
            except (ValueError,KeyError,TypeError):
                error('Malformed application HTTP telemetry received')

def dns():
    with socket.socket(socket.AF_INET,socket.SOCK_DGRAM) as sock:
        sock.bind(('0.0.0.0',53))
        sock.settimeout(.2)
        STATE['heartbeats']['dns'] = timestamp()
        while not STOP.is_set():
            try:
                packet, peer = sock.recvfrom(4096)
            except socket.timeout:
                STATE['heartbeats']['dns'] = timestamp()
                continue
            try:
                pos = 12
                labels = []
                while pos < len(packet) and packet[pos]:
                    length = packet[pos]
                    if length > 63 or pos + 1 + length > len(packet):
                        raise ValueError('Invalid DNS label')
                    labels.append(packet[pos+1:pos+1+length].decode('ascii','replace'))
                    pos += length+1
                end = pos+5
                if end > len(packet):
                    raise ValueError('Truncated DNS question')
                emit('network','dns_blocked','.'.join(labels),collector='dns-sink',detail='NXDOMAIN; no external DNS forwarding')
                response = packet[:2]+b'\x81\x83'+b'\x00\x01\x00\x00\x00\x00\x00\x00'+packet[12:end]
                sock.sendto(response,peer)
            except ValueError:
                error('Malformed DNS packet received')

class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args):
        pass
    def reply(self,status,value):
        data=json.dumps(value).encode()
        self.send_response(status)
        self.send_header('Content-Type','application/json')
        self.send_header('Content-Length',str(len(data)))
        self.end_headers()
        self.wfile.write(data)
    def do_GET(self):
        if self.path == '/state':
            if self.client_address[0] != '127.0.0.1':
                self.reply(403,{'error':'local observer control only'})
                return
            with LOCK:
                self.reply(200,STATE)
        else:
            emit('network','controlled_http_received',self.path,collector='controlled-http-sink',detail='HTTP request reached internal test endpoint')
            self.reply(200,{'veto':'controlled endpoint'})
    def do_POST(self):
        if self.path in ('/stage','/snapshot'):
            if self.client_address[0] != '127.0.0.1':
                self.reply(403,{'error':'local observer control only'})
                return
            try:
                size=int(self.headers.get('Content-Length','0'))
                if size > 1024: raise ValueError('Oversized control request')
                value=json.loads(self.rfile.read(size))
                if self.path == '/stage':
                    with LOCK: STATE['stage']=value['stage']
                else:
                    snapshot(value['label'])
                self.reply(200,{'ok':True})
            except Exception as exc:
                error(f'control request: {exc}')
                self.reply(500,{'error':str(exc)})
        else:
            self.rfile.read(min(int(self.headers.get('Content-Length','0')),65536))
            self.do_GET()

threads=[guarded('inotify',files),guarded('sql_audit',sql_audit),guarded('dns',dns),guarded('http_hook',telemetry)]
server=ThreadingHTTPServer(('0.0.0.0',8081),Handler)
server.daemon_threads=True
STATE['ready']=True
server.serve_forever(poll_interval=.2)
