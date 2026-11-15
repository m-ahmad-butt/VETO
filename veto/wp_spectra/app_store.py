"""Durable local job queue, atomic artifacts, and process-lifetime leases."""
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import time
import uuid

from .scanner import now

TERMINAL={'completed','rejected','failed','interrupted'}
MAX_UPLOAD=50*1024*1024
MAX_ARTIFACT=64*1024*1024


class ProcessLock:
    def __init__(self,path):self.path=Path(path);self.stream=None
    def acquire(self):
        self.path.parent.mkdir(parents=True,exist_ok=True)
        stream=self.path.open('a+b')
        if self.path.stat().st_size==0:
            stream.write(b'0');stream.flush()
        stream.seek(0)
        try:
            if os.name=='nt':
                import msvcrt
                msvcrt.locking(stream.fileno(),msvcrt.LK_NBLCK,1)
            else:
                import fcntl
                fcntl.flock(stream.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
        except OSError:
            stream.close();return False
        self.stream=stream
        return True
    def release(self):
        if self.stream:
            self.stream.seek(0)
            if os.name=='nt':
                import msvcrt
                msvcrt.locking(self.stream.fileno(),msvcrt.LK_UNLCK,1)
            else:
                import fcntl
                fcntl.flock(self.stream.fileno(),fcntl.LOCK_UN)
            self.stream.close();self.stream=None


def atomic_json(path,value):
    path=Path(path)
    data=json.dumps(value,ensure_ascii=True,indent=2).encode()
    if len(data)>MAX_ARTIFACT:raise ValueError('Evidence exceeds the 64 MiB artifact limit')
    temporary=path.with_name(path.name+'.'+uuid.uuid4().hex+'.tmp')
    try:
        with temporary.open('xb') as stream:
            stream.write(data);stream.flush();os.fsync(stream.fileno())
        for attempt in range(15):
            try:os.replace(temporary,path);break
            except PermissionError:
                if attempt==14:raise
                time.sleep(.02)
    finally:temporary.unlink(missing_ok=True)


def read_json(path):
    path=Path(path)
    if not path.exists():return None
    if path.stat().st_size>MAX_ARTIFACT:raise ValueError('Evidence artifact exceeds size limit')
    return json.loads(path.read_text(encoding='utf-8'))


class Store:
    def __init__(self,root):
        self.root=Path(root).resolve()
        self.root.mkdir(parents=True,exist_ok=True)
        (self.root/'jobs').mkdir(exist_ok=True)
        with self.connection() as db:
            db.executescript('''
                PRAGMA journal_mode=WAL;
                CREATE TABLE IF NOT EXISTS jobs (
                  id TEXT PRIMARY KEY, filename TEXT NOT NULL, mode TEXT NOT NULL,
                  sha256 TEXT NOT NULL, size_bytes INTEGER NOT NULL,
                  status TEXT NOT NULL, stage TEXT NOT NULL, detail TEXT NOT NULL,
                  created_at TEXT NOT NULL, updated_at TEXT NOT NULL, finished_at TEXT,
                  analysis_id TEXT, plugin_name TEXT, worker_pid INTEGER,
                  cleanup_verified INTEGER, recovery_requested INTEGER NOT NULL DEFAULT 0, control_for TEXT);
                CREATE TABLE IF NOT EXISTS progress (
                  seq INTEGER PRIMARY KEY AUTOINCREMENT, job_id TEXT NOT NULL REFERENCES jobs(id),
                  stage TEXT NOT NULL, detail TEXT NOT NULL, observed_at TEXT NOT NULL);
                CREATE INDEX IF NOT EXISTS jobs_created ON jobs(created_at);
                CREATE INDEX IF NOT EXISTS progress_job ON progress(job_id,seq);
            ''')
            if 'control_for' not in {row[1] for row in db.execute('PRAGMA table_info(jobs)')}:
                db.execute('ALTER TABLE jobs ADD COLUMN control_for TEXT')
            db.execute('PRAGMA user_version=2')
    @contextmanager
    def connection(self):
        db=sqlite3.connect(self.root/'history.sqlite3',timeout=10,isolation_level=None)
        db.row_factory=sqlite3.Row
        db.execute('PRAGMA foreign_keys=ON')
        try:yield db
        finally:db.close()
    def directory(self,job_id):
        if str(uuid.UUID(job_id))!=job_id:raise ValueError('Invalid job ID')
        return self.root/'jobs'/job_id
    def get(self,job_id):
        self.directory(job_id)
        with self.connection() as db:
            row=db.execute('SELECT * FROM jobs WHERE id=?',(job_id,)).fetchone()
            return dict(row) if row else None
    def history(self,limit=100,offset=0):
        with self.connection() as db:
            return [dict(row) for row in db.execute('SELECT * FROM jobs ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?',(limit,offset))]
    def progress(self,job_id):
        with self.connection() as db:
            return [dict(row) for row in db.execute('SELECT seq,stage,detail,observed_at FROM progress WHERE job_id=? ORDER BY seq',(job_id,))]
    def counts(self):
        with self.connection() as db:
            counts={row[0]:row[1] for row in db.execute('SELECT status,COUNT(*) FROM jobs GROUP BY status')}
            return {'total':sum(counts.values()),'active':counts.get('running',0),'queued':counts.get('queued',0),**counts}
    def create(self,job_id,filename,mode,sha,size,control_for=None):
        timestamp=now()
        with self.connection() as db:
            db.execute('BEGIN IMMEDIATE')
            db.execute('INSERT INTO jobs(id,filename,mode,sha256,size_bytes,status,stage,detail,created_at,updated_at,control_for) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                       (job_id,filename,mode,sha,size,'queued','queued','Waiting for the local analysis worker',timestamp,timestamp,control_for))
            db.execute('INSERT INTO progress(job_id,stage,detail,observed_at) VALUES(?,?,?,?)',(job_id,'queued','Submission saved to the local queue',timestamp))
            db.execute('COMMIT')
    def update(self,job_id,stage,detail,**fields):
        allowed={'status','finished_at','analysis_id','plugin_name','worker_pid','cleanup_verified','recovery_requested'}
        if set(fields)-allowed:raise ValueError('Unsupported job update field')
        timestamp=now()
        with self.connection() as db:
            db.execute('BEGIN IMMEDIATE')
            values={'stage':stage,'detail':detail[:2000],'updated_at':timestamp,**fields}
            columns=','.join(key+'=?' for key in values)
            db.execute('UPDATE jobs SET '+columns+' WHERE id=?',(*values.values(),job_id))
            db.execute('INSERT INTO progress(job_id,stage,detail,observed_at) VALUES(?,?,?,?)',(job_id,stage,detail[:2000],timestamp))
            db.execute('COMMIT')
    def claim(self):
        with self.connection() as db:
            db.execute('BEGIN IMMEDIATE')
            row=db.execute("SELECT * FROM jobs WHERE status='queued' ORDER BY created_at,id LIMIT 1").fetchone()
            if row:
                db.execute("UPDATE jobs SET status='running',stage='starting',updated_at=? WHERE id=?",(now(),row['id']))
            db.execute('COMMIT')
            return dict(row) if row else None
    def active(self):
        with self.connection() as db:
            return [dict(row) for row in db.execute("SELECT * FROM jobs WHERE status='running' OR recovery_requested=1")]
    def paused(self):
        with self.connection() as db:
            return bool(db.execute('SELECT 1 FROM jobs WHERE cleanup_verified=0 LIMIT 1').fetchone())
    def controls(self):
        with self.connection() as db:
            return [dict(row) for row in db.execute("SELECT * FROM jobs WHERE mode='control' ORDER BY created_at DESC LIMIT 100")]


def ingest(store,source,length,filename,mode,*,control_for=None):
    """Caller serializes submissions. Never use a submitted name in a path."""
    if type(length) is not int or not 0<length<=MAX_UPLOAD:raise ValueError('Choose a non-empty ZIP no larger than 50 MiB')
    if mode not in ('full','static','control'):raise ValueError('Choose full or static analysis')
    if mode=='control' and (not control_for or not store.get(control_for)):raise ValueError('A clean control requires a source submission')
    # Strip path components for display only; generated IDs determine all paths.
    filename=filename.replace('\\','/').split('/')[-1]
    if not filename.lower().endswith('.zip') or len(filename)>160 or any(ord(c)<32 or ord(c)==127 for c in filename):
        raise ValueError('Choose a .zip filename with at most 160 printable characters')
    counts=store.counts()
    if counts['queued']+counts['active']>=8:raise ValueError('The local queue is full; wait for an analysis to finish')
    stored=sum(path.stat().st_size for path in store.root.rglob('*') if path.is_file())
    if stored+length>2*1024**3:raise ValueError('Local storage limit reached; archive old application data before submitting more')
    job_id=str(uuid.uuid4())
    directory=store.directory(job_id)
    directory.mkdir()
    target=directory/'submission.zip'
    digest=hashlib.sha256()
    try:
        with target.open('xb') as stream:
            remaining=length
            while remaining:
                data=source.read(min(65536,remaining))
                if not data:raise ValueError('Upload was interrupted before all bytes arrived')
                stream.write(data);digest.update(data);remaining-=len(data)
            stream.flush();os.fsync(stream.fileno())
        store.create(job_id,filename,mode,digest.hexdigest(),length,control_for)
    except BaseException:
        target.unlink(missing_ok=True)
        directory.rmdir()
        raise
    return store.get(job_id)
