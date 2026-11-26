"""HTTP application with explicit host checks and no extra runtime dependencies."""
import argparse
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
import json
from pathlib import Path
import secrets
import socket
from urllib.parse import parse_qs,unquote,urlsplit

from .app_service import AppService
from .app_store import MAX_UPLOAD,ingest
from .dynamic import DynamicConfig

WEB=Path(__file__).parent/'web'


class LocalServer(ThreadingHTTPServer):
    daemon_threads=True
    request_queue_size=16
    def __init__(self,address,service,allowed_host=None):
        self.service=service
        self.csrf=secrets.token_urlsafe(32)
        self.allowed_host=allowed_host
        super().__init__(address,Handler)
    def get_request(self):
        request,address=super().get_request()
        request.settimeout(30)
        return request,address


class Handler(BaseHTTPRequestHandler):
    server_version='VETO'
    def log_message(self,*args):pass
    def headers_common(self):
        self.send_header('Cache-Control','no-store')
        self.send_header('X-Content-Type-Options','nosniff')
        self.send_header('X-Frame-Options','DENY')
        self.send_header('Referrer-Policy','no-referrer')
        self.send_header('Cross-Origin-Resource-Policy','same-origin')
        self.send_header('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'")
    def reply(self,status,value,content_type='application/json; charset=utf-8',download_filename=None):
        data=json.dumps(value,ensure_ascii=True).encode() if not isinstance(value,bytes) else value
        self.send_response(status);self.headers_common()
        self.send_header('Content-Type',content_type);self.send_header('Content-Length',str(len(data)))
        if download_filename:self.send_header('Content-Disposition','attachment; filename="'+download_filename+'"')
        self.end_headers()
        try:self.wfile.write(data)
        except (BrokenPipeError,ConnectionResetError):pass
    def boundary(self,write=False):
        port=self.server.server_address[1]
        hosts={f'127.0.0.1:{port}',f'localhost:{port}'}
        if self.server.allowed_host:
            hosts.update((self.server.allowed_host,f'{self.server.allowed_host}:80'))
        if len(self.headers.get_all('Host',[]))!=1 or self.headers.get('Host') not in hosts:
            self.reply(403,{'error':'Use the application address'});return False
        origin=self.headers.get('Origin')
        if origin and origin not in {f'http://{host}' for host in hosts}:
            self.reply(403,{'error':'Cross-origin access is not allowed'});return False
        if self.headers.get('Sec-Fetch-Site')=='cross-site':
            self.reply(403,{'error':'Cross-site access is not allowed'});return False
        if write and not secrets.compare_digest(self.headers.get('X-VETO-Token','').encode('utf-8'),self.server.csrf.encode('ascii')):
            self.reply(403,{'error':'Refresh the page before submitting again'});return False
        return True
    def do_GET(self):
        if not self.boundary():return
        parsed=urlsplit(self.path)
        path=parsed.path
        service=self.server.service
        try:
            assets={'/':('index.html','text/html; charset=utf-8'),'/app.js':('app.js','text/javascript; charset=utf-8'),'/app.css':('app.css','text/css; charset=utf-8'),
                    '/investigation.js':('investigation.js','text/javascript; charset=utf-8'),'/investigation.css':('investigation.css','text/css; charset=utf-8')}
            assets['/reports.js']=('reports.js','text/javascript; charset=utf-8')
            assets['/dropdown.css']=('dropdown.css','text/css; charset=utf-8')
            if path in assets:
                filename,kind=assets[path]
                self.reply(200,(WEB/filename).read_bytes(),kind)
            elif path=='/api/bootstrap':
                self.reply(200,{'csrf_token':self.server.csrf,'max_upload_bytes':MAX_UPLOAD,'queue_paused':service.store.paused(),'service_error':service.error})
            elif path=='/api/jobs':
                query=parse_qs(parsed.query)
                limit=int(query.get('limit',['25'])[0]);offset=int(query.get('offset',['0'])[0])
                if not 1<=limit<=100 or not 0<=offset<=1000000:raise ValueError('Invalid history page')
                self.reply(200,{'jobs':service.store.history(limit,offset),'counts':service.store.counts(),
                                'queue_paused':service.store.paused(),'service_error':service.error})
            elif path=='/api/controls':
                self.reply(200,{'controls':service.store.controls()})
            elif path.startswith('/api/jobs/'):
                parts=path.strip('/').split('/')
                if len(parts) not in (3,4,5,6):self.reply(404,{'error':'Not found'});return
                job=service.detail(parts[2])
                if not job:self.reply(404,{'error':'Analysis not found'});return
                if len(parts)==6:
                    if parts[3]!='exports':self.reply(404,{'error':'Not found'});return
                    data,kind=service.reports.download(parts[2],parts[4],parts[5])
                    self.reply(200,data,kind,'veto-'+parts[2][:8]+'-'+parts[4][:8]+('.pdf' if kind=='application/pdf' else '.json'))
                    return
                if len(parts)==5:
                    if parts[3]!='records':self.reply(404,{'error':'Not found'});return
                    record_id=unquote(parts[4])
                    value=next((record for record in service.inspection(parts[2])['records'] if record['record_id']==record_id),None)
                    self.reply(200,value) if value else self.reply(404,{'error':'Evidence record not found'})
                    return
                if len(parts)==4:
                    if parts[3]=='evidence':self.reply(200,service.evidence(parts[2]))
                    elif parts[3]=='investigation':self.reply(200,service.inspection(parts[2])['overview'])
                    elif parts[3]=='exports':self.reply(200,{'exports':service.reports.list(parts[2])})
                    elif parts[3]=='records':
                        from .investigation import query_records
                        query=parse_qs(parsed.query)
                        if set(query)-{'source','category','severity','stage','q','offset','limit'}:raise ValueError('Unsupported evidence query')
                        options={key:values[0] for key,values in query.items()}
                        for key in ('offset','limit'):
                            if key in options:options[key]=int(options[key])
                        self.reply(200,query_records(service.inspection(parts[2]),**options))
                    elif parts[3]=='comparison':
                        from .investigation import compare_control
                        query=parse_qs(parsed.query)
                        if set(query)!={'control'} or len(query['control'])!=1:raise ValueError('Choose one control run')
                        self.reply(200,compare_control(service.inspection(parts[2]),service.inspection(query['control'][0])))
                    else:self.reply(404,{'error':'Not found'})
                else:self.reply(200,job)
            else:self.reply(404,{'error':'Not found'})
        except (ValueError,TypeError) as exc:self.reply(400,{'error':str(exc)[:300] or 'Invalid request'})
        except Exception:self.reply(500,{'error':'The service could not read this record. Saved files have not been removed.'})
    def do_POST(self):
        self.close_connection=True
        if not self.boundary(write=True):return
        parsed=urlsplit(self.path)
        service=self.server.service
        try:
            if parsed.path=='/api/jobs':
                if self.headers.get('Transfer-Encoding') or len(self.headers.get_all('Content-Length',[]))!=1:
                    self.reply(411,{'error':'Upload requires one explicit Content-Length'});return
                length=int(self.headers['Content-Length'])
                if not 0<length<=MAX_UPLOAD:self.reply(413,{'error':'Choose a non-empty ZIP no larger than 50 MiB'});return
                if self.headers.get_content_type() not in ('application/zip','application/octet-stream'):
                    self.reply(415,{'error':'Upload a ZIP file'});return
                query=parse_qs(parsed.query)
                if set(query)-{'mode'}:raise ValueError('Unsupported submission option')
                mode=query.get('mode',['full'])[0]
                if mode not in ('full','static'):raise ValueError('Choose full or static analysis')
                filename=unquote(self.headers.get('X-VETO-Filename',''),errors='strict')
                with service.submit_lock:
                    job=ingest(service.store,self.rfile,length,filename,mode)
                self.reply(202,{'job':job})
            elif parsed.path.startswith('/api/jobs/') and parsed.path.endswith('/exports'):
                parts=parsed.path.strip('/').split('/')
                if len(parts)!=4:raise ValueError('Invalid export route')
                length=int(self.headers.get('Content-Length','0'))
                if not 0<=length<=1024 or self.headers.get('Transfer-Encoding'):raise ValueError('Invalid export request size')
                options=json.loads(self.rfile.read(length)) if length else {}
                if not isinstance(options,dict) or set(options)-{'control_job_id'}:raise ValueError('Unsupported export option')
                if options.get('control_job_id') is not None and not isinstance(options['control_job_id'],str):raise ValueError('Control ID must be a UUID string')
                self.reply(201,{'export':service.reports.create(parts[2],options.get('control_job_id') or None)})
            elif parsed.path.startswith('/api/jobs/') and parsed.path.endswith('/control'):
                if self.headers.get('Content-Length','0')!='0':raise ValueError('This request must have an empty body')
                parts=parsed.path.strip('/').split('/')
                if len(parts)!=4:raise ValueError('Invalid control route')
                self.reply(202,{'job':service.create_control(parts[2])})
            elif parsed.path.startswith('/api/jobs/') and parsed.path.endswith('/retry-cleanup'):
                if self.headers.get('Content-Length','0')!='0':raise ValueError('This request must have an empty body')
                parts=parsed.path.strip('/').split('/')
                if len(parts)!=4:raise ValueError('Invalid job route')
                service.retry_cleanup(parts[2]);self.reply(202,{'accepted':True})
            else:self.reply(404,{'error':'Not found'})
        except (ValueError,UnicodeError) as exc:self.reply(400,{'error':str(exc)[:300]})
        except (socket.timeout,TimeoutError):self.reply(408,{'error':'Upload timed out. Select the ZIP and try again.'})
        except Exception:
            text='Report could not be generated. Existing evidence and previous reports are unchanged. Check available disk space.' if parsed.path.endswith('/exports') else 'Submission could not be saved. Check available disk space and try again.'
            self.reply(500,{'error':text})


def main(argv=None):
    parser=argparse.ArgumentParser(description='VETO application: uploads, background analysis and persistent history')
    parser.add_argument('--port',type=int,default=8765)
    parser.add_argument('--allowed-host',help='Additional HTTP Host header for a trusted reverse proxy')
    parser.add_argument('--data-dir',type=Path,default=Path(__file__).resolve().parents[1]/'.local/app')
    args=parser.parse_args(argv)
    if not 1<=args.port<=65535:parser.error('Port must be between 1 and 65535')
    service=AppService(args.data_dir)
    try:
        server=LocalServer(('127.0.0.1',args.port),service,args.allowed_host)
        print(f'VETO is available at http://127.0.0.1:{args.port}',flush=True)
        print('Press Ctrl+C to stop the web server. Active analysis workers finish independently.',flush=True)
        try:server.serve_forever(poll_interval=.3)
        except KeyboardInterrupt:pass
        finally:server.server_close()
    finally:service.close()


if __name__=='__main__':main()
