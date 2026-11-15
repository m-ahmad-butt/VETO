"""One durable job in a child process; independent of HTTP server lifetime."""
import argparse
import os
from pathlib import Path
import sys

from .app_store import ProcessLock,Store,atomic_json,read_json
from .dynamic import DynamicConfig,observe,validate_dynamic
from .scanner import analyze,now


def run_job(root,job_id):
    store=Store(root)
    directory=store.directory(job_id)
    lease=ProcessLock(directory/'worker.lock')
    if not lease.acquire():return 1
    try:
        job=store.get(job_id)
        if not job or job['status']!='running':return 1
        store.update(job_id,'validating','Checking your submitted plugin ZIP',worker_pid=os.getpid())
        def progress(stage,detail):store.update(job_id,stage,detail)
        def static_checkpoint(value):
            atomic_json(directory/'static.partial.json',{'partial':True,'saved_at':now(),'report':value})
        static=analyze(directory/'submission.zip',on_progress=progress,on_checkpoint=static_checkpoint)
        from .__main__ import validate_report
        validate_report(static)
        atomic_json(directory/'static.json',static)
        names=static['plugin_metadata']
        after_static_stage='scanning' if static['status']=='completed' else store.get(job_id)['stage']
        store.update(job_id,after_static_stage,'Static evidence saved',analysis_id=static['analysis_id'],
                     plugin_name='Clean WordPress control' if job['mode']=='control' else names[0]['name'][:200] if names else None)
        report=static
        if job['mode'] in ('full','control') and static['status']=='completed':
            settings=read_json(store.root/'settings.json') or {}
            config=DynamicConfig(**settings.get('dynamic',{}))
            def checkpoint(value):
                atomic_json(directory/'dynamic.partial.json',{'partial':True,'saved_at':now(),'report':value})
            report=observe(directory/'submission.zip',config=config,static_result=static,
                           control=job['mode']=='control',
                           on_progress=progress,on_checkpoint=checkpoint)
            validate_dynamic(report)
            atomic_json(directory/'dynamic.json',report)
        store.update(job_id,'reporting','Saving the analysis outcome and available evidence')
        status=report['status']
        cleanup=report.get('cleanup',{}).get('verified',True)
        if status=='completed':
            detail='Analysis completed. Evidence is saved locally; completion does not establish plugin safety.'
        elif status=='rejected':
            detail='Package rejected. '+('; '.join(error['message'] for error in report['errors']))[:1000]
        else:
            detail='Analysis incomplete. '+('; '.join(error['message'] for error in report['errors']))[:1000]
        store.update(job_id,status,detail,status=status,finished_at=now(),cleanup_verified=int(cleanup))
        return 0
    except BaseException as exc:
        # Supervisor owns interruption recovery, including any orphaned resources.
        try:store.update(job_id,'worker_error',f'{type(exc).__name__}: {str(exc)[:1000]}')
        except Exception:pass
        return 1
    finally:lease.release()


if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--data-dir',type=Path,required=True)
    parser.add_argument('--job-id',required=True)
    args=parser.parse_args()
    sys.exit(run_job(args.data_dir,args.job_id))
