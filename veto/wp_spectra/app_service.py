"""Single-dispatcher local service. Active workers are adopted after restart."""
from datetime import datetime,timezone
from collections import OrderedDict
from pathlib import Path
import subprocess
import sys
import threading

from .app_store import ProcessLock,Store,TERMINAL,atomic_json,read_json,ingest
from .dynamic import DynamicConfig,validate_dynamic
from .recovery import cleanup_owned
from .scanner import now


class AppService:
    def __init__(self,root,config=None,*,dispatch=True):
        root=Path(root).resolve();root.mkdir(parents=True,exist_ok=True)
        self.lock=ProcessLock(root/'server.lock')
        if not self.lock.acquire():raise RuntimeError('This application data directory is already open in another server')
        try:
            self.store=Store(root)
            if config is not None:
                from dataclasses import asdict
                atomic_json(root/'settings.json',{'dynamic':asdict(config)})
            elif not (root/'settings.json').exists():
                from dataclasses import asdict
                atomic_json(root/'settings.json',{'dynamic':asdict(DynamicConfig())})
            self.stop=threading.Event()
            self.submit_lock=threading.Lock()
            self.children={}
            self.error=None
            self.inspection_cache=OrderedDict()
            self.inspection_lock=threading.RLock()
            from .reports import ReportStore
            self.reports=ReportStore(self)
            self.thread=None
            if dispatch:
                self.thread=threading.Thread(target=self.loop,daemon=True,name='veto-job-dispatcher')
                self.thread.start()
        except BaseException:
            self.lock.release();raise
    def close(self):
        self.stop.set()
        if self.thread:self.thread.join(timeout=130)
        if self.thread and self.thread.is_alive():raise RuntimeError('Dispatcher has not finished recovery; application lock is retained')
        for job_id,process in self.children.items():
            if (self.store.get(job_id) or {}).get('status') in TERMINAL:
                try:process.wait(timeout=3)
                except subprocess.TimeoutExpired:pass
            if process.poll() is None:
                threading.Thread(target=process.wait,daemon=True,name='veto-worker-reaper').start()
        self.children.clear()
        self.lock.release()
    def evidence(self,job_id):
        directory=self.store.directory(job_id)
        return {'static':read_json(directory/'static.json'),
                'partial_static':read_json(directory/'static.partial.json'),
                'dynamic':read_json(directory/'dynamic.json'),
                'partial_dynamic':read_json(directory/'dynamic.partial.json')}
    def detail(self,job_id):
        job=self.store.get(job_id)
        if not job:return None
        evidence=self.evidence(job_id)
        static=evidence['static'] or (evidence['partial_static'] or {}).get('report')
        dynamic=evidence['dynamic'] or (evidence['partial_dynamic'] or {}).get('report')
        job['progress']=self.store.progress(job_id)
        job['evidence']={'static_saved':evidence['static'] is not None,'partial_static_saved':evidence['partial_static'] is not None,'dynamic_saved':evidence['dynamic'] is not None,
                         'partial_dynamic_saved':evidence['partial_dynamic'] is not None,
                         'static_findings':len(static['static_findings']) if static else None,
                         'runtime_events':len(dynamic['runtime_events']) if dynamic else None,
                         'dynamic_complete':bool(evidence['dynamic'] and evidence['dynamic']['status']=='completed')}
        return job
    def inspection(self,job_id):
        from .investigation import build_inspection
        job=self.store.get(job_id)
        if not job:raise ValueError('Analysis not found')
        directory=self.store.directory(job_id)
        names=('static.json','static.partial.json','dynamic.json','dynamic.partial.json','recovery.json')
        signature=(job['updated_at'],tuple((name,(directory/name).stat().st_mtime_ns,(directory/name).stat().st_size) for name in names if (directory/name).exists()))
        with self.inspection_lock:
            cached=self.inspection_cache.get(job_id)
            if cached and cached[0]==signature:
                self.inspection_cache.move_to_end(job_id)
                return cached[1]
            value=build_inspection(job,self.evidence(job_id),self.store.progress(job_id),read_json(directory/'recovery.json'))
            self.inspection_cache[job_id]=(signature,value)
            self.inspection_cache.move_to_end(job_id)
            while len(self.inspection_cache)>4:self.inspection_cache.popitem(last=False)
            return value
    def create_control(self,job_id):
        job=self.store.get(job_id)
        if not job or job['mode']=='control':raise ValueError('Select a plugin analysis to create a control')
        evidence=self.evidence(job_id)
        if not evidence['static'] or evidence['static']['status']!='completed':raise ValueError('Wait for a completed static inspection before creating a control')
        with self.submit_lock:
            if any(row['control_for']==job_id and row['status'] not in TERMINAL for row in self.store.controls()):
                raise ValueError('A control for this analysis is already queued or running')
            source=self.store.directory(job_id)/'submission.zip'
            with source.open('rb') as stream:
                return ingest(self.store,stream,source.stat().st_size,'control-'+job_id[:8]+'.zip','control',control_for=job_id)
    def recover(self,job):
        job_id=job['id']
        directory=self.store.directory(job_id)
        final=read_json(directory/'dynamic.json') if job['mode']!='static' else read_json(directory/'static.json')
        if final is not None and not job['recovery_requested']:
            if job['mode']!='static':validate_dynamic(final)
            else:
                from .__main__ import validate_report
                validate_report(final)
            cleanup=final.get('cleanup',{}).get('verified',True)
            self.store.update(job_id,final['status'],'Recovered the saved analysis outcome after worker exit',
                              status=final['status'],finished_at=now(),cleanup_verified=int(cleanup),recovery_requested=0)
            return
        self.store.update(job_id,'cleaning_up','Recovering an interrupted run; checking owned sandbox resources')
        cleanup=cleanup_owned(job['analysis_id']) if job['mode']!='static' and job['analysis_id'] else {'verified':True,'removed':[],'remaining':[]}
        atomic_json(directory/'recovery.json',cleanup)
        detail='The analysis worker stopped before completion. Saved evidence has been retained.'
        if not cleanup['verified']:detail+=' Cleanup could not be verified. Start Docker and retry cleanup before queued analyses can run.'
        status=job['status'] if job['status'] in TERMINAL else 'interrupted'
        self.store.update(job_id,status,detail,status=status,finished_at=now(),
                          cleanup_verified=int(cleanup['verified']),recovery_requested=0)
    def retry_cleanup(self,job_id):
        job=self.store.get(job_id)
        if not job or job['status'] not in TERMINAL or job['cleanup_verified']!=0:
            raise ValueError('This job does not need cleanup recovery')
        self.store.update(job_id,'cleaning_up','Cleanup retry requested',recovery_requested=1)
    def tick(self):
        for job_id,process in list(self.children.items()):
            if process.poll() is not None and (self.store.get(job_id) or {}).get('status') in TERMINAL:
                self.children.pop(job_id,None)
        active=self.store.active()
        for job in active:
            lease=ProcessLock(self.store.directory(job['id'])/'worker.lock')
            if not lease.acquire():continue
            try:
                process=self.children.get(job['id'])
                if process and process.poll() is None:continue
                age=(datetime.now(timezone.utc)-datetime.fromisoformat(job['updated_at'].replace('Z','+00:00'))).total_seconds()
                # Allow an already spawned child time to acquire its lock on restart.
                if job['stage']=='starting' and age<5:continue
                self.recover(job)
                self.children.pop(job['id'],None)
            finally:lease.release()
        if self.store.active() or self.store.paused():return
        job=self.store.claim()
        if not job:return
        flags=(subprocess.CREATE_NO_WINDOW|subprocess.CREATE_NEW_PROCESS_GROUP) if sys.platform=='win32' else 0
        try:
            self.children[job['id']]=subprocess.Popen([sys.executable,'-m','wp_spectra.app_worker','--data-dir',str(self.store.root),'--job-id',job['id']],
                cwd=Path(__file__).resolve().parents[1],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,
                creationflags=flags,start_new_session=sys.platform!='win32')
        except Exception as exc:
            self.store.update(job['id'],'worker_error',str(exc)[:1000])
    def loop(self):
        while not self.stop.is_set():
            try:self.tick();self.error=None
            except Exception as exc:self.error=f'{type(exc).__name__}: {str(exc)[:500]}'
            self.stop.wait(.4)
