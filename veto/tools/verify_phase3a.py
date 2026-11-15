"""Real local HTTP-to-Docker workflow, restart adoption and crash recovery."""
import hashlib
import io
import json
from pathlib import Path
import threading
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import uuid

from wp_spectra.app_server import LocalServer
from wp_spectra.app_service import AppService
from wp_spectra.app_store import TERMINAL,atomic_json
from wp_spectra.dynamic import DynamicConfig
from wp_spectra.scanner import now

BASE=Path(__file__).resolve().parents[1]


def wait_for(predicate,timeout=150):
    end=time.monotonic()+timeout
    while time.monotonic()<end:
        value=predicate()
        if value:return value
        time.sleep(.15)
    raise RuntimeError('Acceptance wait timed out')


class RunningApp:
    def __init__(self,root,config=None):
        self.root=root
        self.service=AppService(root,config)
        self.server=LocalServer(('127.0.0.1',0),self.service)
        self.thread=threading.Thread(target=self.server.serve_forever,daemon=True);self.thread.start()
        self.base='http://127.0.0.1:'+str(self.server.server_address[1])
    def close(self):
        self.server.shutdown();self.server.server_close();self.thread.join();self.service.close()
    def get(self,path):return json.load(urllib.request.urlopen(self.base+path,timeout=10))
    def submit(self,path,mode='full',filename=None):
        data=path.read_bytes()
        csrf=self.get('/api/bootstrap')['csrf_token']
        request=urllib.request.Request(self.base+'/api/jobs?mode='+mode,data=data,headers={
            'Content-Type':'application/zip','X-VETO-Token':csrf,'X-VETO-Filename':urllib.parse.quote(filename or path.name)})
        response=urllib.request.urlopen(request,timeout=30)
        assert response.status==202
        return json.load(response)['job']
    def detail(self,job):return self.get('/api/jobs/'+job['id'])
    def finish(self,job):return wait_for(lambda:(value if (value:=self.detail(job))['status'] in TERMINAL else None))


def main():
    evidence=BASE/'evidence/phase3a';evidence.mkdir(parents=True,exist_ok=True)
    log=io.StringIO()
    result=unittest.TextTestRunner(stream=log,verbosity=2).run(unittest.defaultTestLoader.discover(str(BASE/'tests')))
    (evidence/'tests.txt').write_text(log.getvalue(),encoding='utf-8')
    print(f'Offline tests: {result.testsRun}, successful={result.wasSuccessful()}',flush=True)
    if not result.wasSuccessful():print(log.getvalue());return 1
    data=BASE/'.local'/('acceptance-'+uuid.uuid4().hex)
    app=RunningApp(data,DynamicConfig(action_timeout=4,observation_seconds=3))
    jobs={};checks={}
    try:
        jobs['full']=app.submit(BASE/'fixtures/dynamic/behaviors.zip')
        print('Submitted full analysis through HTTP',flush=True)
        jobs['queued-static']=app.submit(BASE/'fixtures/generated/benign.zip',mode='static')
        active=wait_for(lambda:(value if (value:=app.detail(jobs['full']))['evidence']['runtime_events'] and value['status']=='running' else None))
        worker=active['worker_pid']
        before=active['analysis_id']
        checks['evidence_saved_while_running']=active['evidence']['static_saved'] and active['evidence']['partial_dynamic_saved']
        checks['second_job_queued']=app.detail(jobs['queued-static'])['status']=='queued'
        start=time.monotonic();app.get('/api/jobs');checks['history_responsive_during_execution']=time.monotonic()-start<2
        app.close();app=RunningApp(data)
        print('Restarted server while worker was active',flush=True)
        adopted=app.detail(jobs['full'])
        checks['restart_adopts_same_worker']=adopted['worker_pid']==worker and adopted['analysis_id']==before
        finished=app.finish(jobs['full'])
        checks['full_analysis_completed']=finished['status']=='completed'
        checks['cleanup_verified']=finished['cleanup_verified']==1
        checks['queued_job_completed_after_restart']=app.finish(jobs['queued-static'])['status']=='completed'
        evidence_full=app.get('/api/jobs/'+jobs['full']['id']+'/evidence')
        checks['five_runtime_categories']=set(event['category'] for event in evidence_full['dynamic']['runtime_events'])=={'file','database','account','cron','network'}
        checks['static_dynamic_ids_agree']=evidence_full['static']['analysis_id']==evidence_full['dynamic']['analysis_id']
        expected={'queued','validating','scanning','preparing_sandbox','activate','frontend','admin','cron','idle','cleaning_up','reporting','completed'}
        checks['real_progress_states_persisted']=expected<={event['stage'] for event in finished['progress']}
        jobs['rejected']=app.submit(BASE/'fixtures/generated/traversal.zip',mode='static')
        rejected=app.finish(jobs['rejected'])
        checks['unsafe_zip_rejected']=rejected['status']=='rejected' and rejected['evidence']['static_saved']
        jobs['failure']=app.submit(BASE/'fixtures/dynamic/activation_error.zip')
        failure=app.finish(jobs['failure'])
        checks['failed_activation_retains_evidence']=failure['status']=='failed' and failure['evidence']['static_saved'] and failure['evidence']['partial_dynamic_saved'] and failure['cleanup_verified']==1
        print('Verified successful, rejected and failed submissions',flush=True)
        jobs['timeout']=app.submit(BASE/'fixtures/dynamic/timeout.zip')
        timeout=app.finish(jobs['timeout'])
        checks['timeout_is_incomplete']=timeout['status']=='failed' and not timeout['evidence']['dynamic_complete'] and timeout['cleanup_verified']==1
        jobs['hostile-name']=app.submit(BASE/'fixtures/generated/benign.zip',mode='static',filename='../../<img onerror=alert(1)>.zip')
        hostile=app.finish(jobs['hostile-name'])
        checks['submitted_name_only_displayed']=hostile['status']=='completed' and hostile['filename']=='<img onerror=alert(1)>.zip' and (app.service.store.directory(hostile['id'])/'submission.zip').exists()
        jobs['interrupted']=app.submit(BASE/'fixtures/dynamic/timeout.zip')
        interrupted=wait_for(lambda:(value if (value:=app.detail(jobs['interrupted']))['stage']=='activate' else None))
        process=app.service.children[jobs['interrupted']['id']]
        process.terminate();process.wait(timeout=10)
        recovered=app.finish(jobs['interrupted'])
        checks['killed_worker_is_interrupted']=recovered['status']=='interrupted' and recovered['cleanup_verified']==1
        checks['interrupted_evidence_retained']=recovered['evidence']['static_saved'] and recovered['evidence']['partial_dynamic_saved']
        checks['interrupted_never_called_complete']=not recovered['evidence']['dynamic_complete']
        print('Verified timeout and forced worker interruption recovery',flush=True)
        old_count=app.get('/api/jobs')['counts']['total']
        app.close();app=RunningApp(data)
        checks['terminal_history_survives_restart']=app.get('/api/jobs')['counts']['total']==old_count
        checks['completed_evidence_survives_restart']=app.detail(jobs['full'])['evidence']['dynamic_saved']
        checks['queue_not_paused_after_recovery']=not app.get('/api/bootstrap')['queue_paused']
        for name,job in jobs.items():
            atomic_json(evidence/(name+'-job.json'),app.detail(job))
        atomic_json(evidence/'full-evidence.json',evidence_full)
        summary={'generated_at':now(),'offline_tests':result.testsRun,'checks':checks,'passed':all(checks.values()),
                 'jobs':{name:job['id'] for name,job in jobs.items()},'test_data_directory':str(data),
                 'purpose':'Phase 3A real local workflow acceptance, not final detection accuracy'}
        atomic_json(evidence/'verification.json',summary)
        print(json.dumps(checks,indent=2),flush=True)
        return 0 if summary['passed'] else 1
    finally:app.close()


if __name__=='__main__':raise SystemExit(main())
