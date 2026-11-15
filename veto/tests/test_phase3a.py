"""Local workflow security, persistence, dispatch and restart acceptance."""
from contextlib import contextmanager
import io
import json
from pathlib import Path
import tempfile
import threading
import time
import unittest
from unittest.mock import patch
import urllib.error
import urllib.parse
import urllib.request
import uuid

from wp_spectra.app_server import LocalServer
from wp_spectra.app_service import AppService
from wp_spectra.app_store import ProcessLock,Store,atomic_json,ingest,read_json
from wp_spectra.scanner import analyze

BASE=Path(__file__).resolve().parents[1]
BENIGN=BASE/'fixtures/generated/benign.zip'


def wait_for(predicate,timeout=15):
    deadline=time.monotonic()+timeout
    while time.monotonic()<deadline:
        value=predicate()
        if value:return value
        time.sleep(.1)
    raise AssertionError('Timed out waiting for local workflow')


@contextmanager
def http_app(service):
    server=LocalServer(('127.0.0.1',0),service)
    thread=threading.Thread(target=server.serve_forever,daemon=True)
    thread.start()
    try:yield server,'http://127.0.0.1:'+str(server.server_address[1])
    finally:server.shutdown();server.server_close();thread.join()


class Phase3ATests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='v3a-')
        self.root=Path(self.temp.name)
        self.store=Store(self.root)
    def tearDown(self):self.temp.cleanup()
    def submit(self,store=None,name='benign.zip',mode='static',data=None):
        data=BENIGN.read_bytes() if data is None else data
        return ingest(store or self.store,io.BytesIO(data),len(data),name,mode)
    def test_generated_paths_and_parameterized_filename(self):
        name="../../a'); DROP TABLE jobs;--.zip"
        job=self.submit(name=name)
        self.assertEqual(job['filename'],name.split('/')[-1])
        self.assertTrue((self.store.directory(job['id'])/'submission.zip').exists())
        self.assertEqual(self.store.counts()['total'],1)
        self.assertEqual(len(list((self.root/'jobs').iterdir())),1)
    def test_truncated_upload_removes_partial(self):
        with self.assertRaises(ValueError):ingest(self.store,io.BytesIO(b'abc'),10,'test.zip','static')
        self.assertEqual(list((self.root/'jobs').iterdir()),[])
        self.assertEqual(self.store.counts()['total'],0)
    def test_upload_limits_mode_and_filename(self):
        cases=[(0,'a.zip','static'),(51*1024**2,'a.zip','static'),(1,'a.php','static'),(1,'a\n.zip','static'),(1,'a.zip','shell')]
        for size,name,mode in cases:
            with self.subTest(name=name,mode=mode),self.assertRaises(ValueError):
                ingest(self.store,io.BytesIO(b'x'),size,name,mode)
    def test_queue_capacity(self):
        for _ in range(8):self.submit()
        with self.assertRaisesRegex(ValueError,'queue is full'):self.submit()
    def test_ids_cannot_escape_storage(self):
        for value in ('../outside','../history.sqlite3','ABC','/tmp/job'):
            with self.subTest(value=value),self.assertRaises(ValueError):self.store.directory(value)
    def test_atomic_evidence_and_persisted_history(self):
        job=self.submit();path=self.store.directory(job['id'])/'static.json'
        atomic_json(path,{'value':1});atomic_json(path,{'value':2})
        self.assertEqual(read_json(path),{'value':2})
        self.assertEqual(Store(self.root).get(job['id'])['sha256'],job['sha256'])
        self.assertEqual(list(path.parent.glob('*.tmp')),[])
    def test_static_checkpoints_preserve_partial_contract(self):
        saved=[]
        result=analyze(BENIGN,on_checkpoint=lambda report:saved.append(json.loads(json.dumps(report))))
        self.assertGreaterEqual(len(saved),2)
        self.assertIsNone(saved[0]['finished_at'])
        self.assertEqual(saved[-1]['status'],'completed')
        self.assertEqual(saved[-1]['analysis_id'],result['analysis_id'])
    def test_process_lock_and_single_server(self):
        first=ProcessLock(self.root/'test.lock');second=ProcessLock(self.root/'test.lock')
        self.assertTrue(first.acquire());self.assertFalse(second.acquire());first.release()
        self.assertTrue(second.acquire());second.release()
        service=AppService(self.root,dispatch=False)
        try:
            with self.assertRaises(RuntimeError):AppService(self.root,dispatch=False)
        finally:service.close()
    def test_http_boundaries_and_unsafe_requests(self):
        service=AppService(self.root,dispatch=False)
        try:
            with http_app(service) as (server,base):
                self.assertEqual(urllib.request.urlopen(base).status,200)
                def call(path='/api/jobs?mode=static',**headers):
                    return urllib.request.urlopen(urllib.request.Request(base+path,data=BENIGN.read_bytes(),headers={'Content-Type':'application/zip','X-VETO-Filename':'test.zip',**headers}))
                for headers in ({},{'X-VETO-Token':server.csrf,'Origin':'https://attacker.invalid'},
                                {'X-VETO-Token':server.csrf,'Host':'attacker.invalid'},
                                {'X-VETO-Token':server.csrf,'Sec-Fetch-Site':'cross-site'}):
                    with self.subTest(headers=headers),self.assertRaises(urllib.error.HTTPError) as caught:call(**headers)
                    self.assertEqual(caught.exception.code,403)
                with self.assertRaises(urllib.error.HTTPError) as caught:
                    call('/api/jobs?mode=static&command=calc',**{'X-VETO-Token':server.csrf})
                self.assertEqual(caught.exception.code,400)
        finally:service.close()
    def test_http_upload_history_detail_and_evidence(self):
        service=AppService(self.root,dispatch=False)
        try:
            with http_app(service) as (server,base):
                request=urllib.request.Request(base+'/api/jobs?mode=static',data=BENIGN.read_bytes(),
                    headers={'Content-Type':'application/zip','X-VETO-Filename':urllib.parse.quote('<img onerror=alert(1)>.zip'),'X-VETO-Token':server.csrf})
                response=urllib.request.urlopen(request)
                self.assertEqual(response.status,202)
                job=json.load(response)['job']
                history=json.load(urllib.request.urlopen(base+'/api/jobs'))
                self.assertEqual(history['jobs'][0]['id'],job['id'])
                detail=json.load(urllib.request.urlopen(base+'/api/jobs/'+job['id']))
                self.assertEqual(detail['filename'],'<img onerror=alert(1)>.zip')
                self.assertFalse(detail['evidence']['static_saved'])
                self.assertEqual(response.headers['X-Content-Type-Options'],'nosniff')
                self.assertIn("script-src 'self'",response.headers['Content-Security-Policy'])
        finally:service.close()
    def test_real_static_worker_and_rejected_archive(self):
        service=AppService(self.root)
        try:
            good=self.submit(service.store)
            bad=self.submit(service.store,name='invalid.zip',data=b'not a zip')
            wait_for(lambda:service.store.get(good['id'])['status']=='completed')
            wait_for(lambda:service.store.get(bad['id'])['status']=='rejected')
            detail=service.detail(good['id'])
            self.assertTrue(detail['evidence']['static_saved'])
            self.assertEqual({item['stage'] for item in detail['progress']} & {'validating','scanning','reporting'},{'validating','scanning','reporting'})
            self.assertTrue(service.detail(bad['id'])['evidence']['static_saved'])
        finally:service.close()
    def test_queued_job_survives_service_restart(self):
        service=AppService(self.root,dispatch=False)
        job=self.submit(service.store);service.close()
        restarted=AppService(self.root)
        try:wait_for(lambda:restarted.store.get(job['id'])['status']=='completed')
        finally:restarted.close()
    def test_dead_worker_recovers_without_inventing_completion(self):
        job=self.submit();self.store.claim()
        static=analyze(BENIGN)
        atomic_json(self.store.directory(job['id'])/'partial-static.json',static)
        self.store.update(job['id'],'scanning','Simulated worker termination')
        service=AppService(self.root,dispatch=False)
        try:
            service.tick()
            self.assertEqual(service.store.get(job['id'])['status'],'interrupted')
            self.assertTrue((service.store.directory(job['id'])/'partial-static.json').exists())
        finally:service.close()
    def test_saved_final_is_recovered_after_worker_exit(self):
        job=self.submit();self.store.claim()
        atomic_json(self.store.directory(job['id'])/'static.json',analyze(BENIGN))
        self.store.update(job['id'],'reporting','Saved result before exit')
        service=AppService(self.root,dispatch=False)
        try:service.tick();self.assertEqual(service.store.get(job['id'])['status'],'completed')
        finally:service.close()
    def test_running_lease_is_adopted_and_never_replayed(self):
        job=self.submit();self.store.claim()
        self.store.update(job['id'],'scanning','Worker still holds lease')
        lease=ProcessLock(self.store.directory(job['id'])/'worker.lock');self.assertTrue(lease.acquire())
        service=AppService(self.root,dispatch=False)
        try:
            with patch('wp_spectra.app_service.subprocess.Popen') as spawn:
                service.tick();spawn.assert_not_called()
            self.assertEqual(service.store.get(job['id'])['status'],'running')
        finally:lease.release();service.close()
    def test_interrupted_dynamic_cleanup_retry(self):
        job=self.submit(mode='full');self.store.claim()
        self.store.update(job['id'],'activate','Stopped',analysis_id=str(uuid.uuid4()))
        service=AppService(self.root,dispatch=False)
        try:
            with patch('wp_spectra.app_service.cleanup_owned',return_value={'verified':False,'remaining':['Docker unavailable'],'removed':[]}):service.tick()
            self.assertTrue(service.store.paused())
            self.assertEqual(service.store.get(job['id'])['status'],'interrupted')
            service.retry_cleanup(job['id'])
            with patch('wp_spectra.app_service.cleanup_owned',return_value={'verified':True,'remaining':[],'removed':[]}):service.tick()
            self.assertFalse(service.store.paused())
        finally:service.close()


if __name__=='__main__':unittest.main()
