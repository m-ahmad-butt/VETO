"""Real Phase 3B API workflow: plugin, clean control, comparison and failure."""
import io
import json
from pathlib import Path
import unittest
import urllib.request
import uuid

from tools.verify_phase3a import RunningApp
from wp_spectra.app_store import atomic_json
from wp_spectra.dynamic import DynamicConfig
from wp_spectra.scanner import now

BASE=Path(__file__).resolve().parents[1]


def main():
    evidence=BASE/'evidence/phase3b';evidence.mkdir(parents=True,exist_ok=True)
    log=io.StringIO()
    unit=unittest.TextTestRunner(stream=log,verbosity=2).run(unittest.defaultTestLoader.discover(str(BASE/'tests')))
    (evidence/'tests.txt').write_text(log.getvalue(),encoding='utf-8')
    print(f'Regression tests: {unit.testsRun}; passed={unit.wasSuccessful()}',flush=True)
    if not unit.wasSuccessful():print(log.getvalue());return 1
    root=BASE/'.local'/('phase3b-'+uuid.uuid4().hex)
    app=RunningApp(root,DynamicConfig(action_timeout=8,observation_seconds=1))
    checks={}
    try:
        job=app.submit(BASE/'fixtures/dynamic/behaviors.zip')
        subject=app.finish(job)
        print('Plugin run: '+subject['status'],flush=True)
        overview=app.get('/api/jobs/'+job['id']+'/investigation')
        checks['completed_plugin_inspectable']=subject['status']=='completed'
        checks['admin_change_prompts_explained_review']=overview['assessment']['code']=='review_recommended' and any(f['policy_id']=='REVIEW-R1' and f['record_ids'] for f in overview['assessment']['factors'])
        checks['five_categories_visible']=set(overview['counts']['categories'])=={'file','database','account','cron','network'}
        checks['no_probability_score']='probability' not in overview['assessment'] and 'score' not in overview['assessment']
        records=[];offset=0
        while True:
            page=app.get('/api/jobs/'+job['id']+f'/records?limit=100&offset={offset}')
            records.extend(page['items']);offset+=100
            if offset>=page['total']:break
        raw=app.get('/api/jobs/'+job['id']+'/evidence')
        pointers_ok=True
        for record in records:
            if record['source']=='workflow':continue
            value=raw['static'] if record['source']=='static' else raw['dynamic']
            for part in record['trace']['json_pointer'].strip('/').split('/'):
                value=value[int(part)] if isinstance(value,list) else value[part]
            pointers_ok=pointers_ok and value==record['raw']
        checks['every_finding_and_event_traces_to_raw_evidence']=pointers_ok
        checks['timeline_includes_stages_and_observations']={r['source'] for r in records}=={'workflow','static','runtime'}
        filtered=app.get('/api/jobs/'+job['id']+'/records?source=runtime&category=account&severity=high&stage=activate&q=veto_fixture_admin')
        checks['combined_filters_find_admin_record']=filtered['total']==1
        account=filtered['items'][0]
        checks['before_after_preserved']=account['before'] is None and 'administrator' in account['after']
        detail=app.get('/api/jobs/'+job['id']+'/records/'+account['record_id'])
        checks['record_detail_provenance_present']=bool(detail['trace']['evidence_ids']) and detail['trace']['artifact']=='dynamic.json'
        request=urllib.request.Request(app.base+'/api/jobs/'+job['id']+'/control',data=b'',headers={'X-VETO-Token':app.get('/api/bootstrap')['csrf_token']})
        control=json.load(urllib.request.urlopen(request))['job']
        control_done=app.finish(control)
        print('Clean control: '+control_done['status'],flush=True)
        control_evidence=app.get('/api/jobs/'+control['id']+'/evidence')
        control_overview=app.get('/api/jobs/'+control['id']+'/investigation')
        final=control_evidence['dynamic']['final_snapshot']
        checks['clean_control_runs_without_submitted_plugin']=control_done['status']=='completed' and control_evidence['dynamic']['mode']=='clean_control' and 'veto_fixture_table' not in final['tables'] and not any(user['user_login']=='veto_fixture_admin' for user in final['accounts'].values())
        checks['control_assessment_is_not_plugin_verdict']=control_overview['assessment']['code']=='control_observation' and not control_overview['assessment']['factors']
        comparison=app.get('/api/jobs/'+job['id']+'/comparison?control='+control['id'])
        checks['matching_environments_compared']=comparison['compatible']
        checks['ordinary_and_additional_patterns_visible']=comparison['counts'].get('also_observed_in_control',0)>0 and comparison['counts'].get('not_observed_in_control',0)>0
        checks['comparison_does_not_discount_review']=app.get('/api/jobs/'+job['id']+'/investigation')['assessment']==overview['assessment']
        failure=app.submit(BASE/'fixtures/dynamic/activation_error.zip')
        failure_done=app.finish(failure)
        failed_overview=app.get('/api/jobs/'+failure['id']+'/investigation')
        checks['failed_run_cannot_receive_completed_assessment']=failure_done['status']=='failed' and failed_overview['assessment']['code']=='incomplete' and bool(failed_overview['assessment']['incomplete_reasons'])
        checks['failed_observations_remain_inspectable']=failed_overview['counts']['runtime']>0 and bool(failed_overview['errors'])
        checks['all_three_runs_cleaned']=all(app.detail(row)['cleanup_verified']==1 for row in (job,control,failure))
        atomic_json(evidence/'inspection.json',overview)
        atomic_json(evidence/'comparison.json',comparison)
        atomic_json(evidence/'account-record.json',detail)
        atomic_json(evidence/'failed-inspection.json',failed_overview)
        atomic_json(evidence/'verification.json',{'generated_at':now(),'regression_tests':unit.testsRun,'checks':checks,'passed':all(checks.values()),
                    'subject_job_id':job['id'],'control_job_id':control['id'],'failed_job_id':failure['id'],'test_data_directory':str(root),
                    'purpose':'Controlled evidence-inspection acceptance; not a general malware accuracy benchmark'})
        print(json.dumps(checks,indent=2),flush=True)
        return 0 if all(checks.values()) else 1
    finally:app.close()


if __name__=='__main__':raise SystemExit(main())
