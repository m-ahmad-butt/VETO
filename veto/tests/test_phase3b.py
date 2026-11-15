"""Review policy, evidence provenance, filters, comparisons and API contracts."""
from copy import deepcopy
import json
from pathlib import Path
import sqlite3
import tempfile
import unittest
import urllib.error
import urllib.request
import uuid

from wp_spectra.app_service import AppService
from wp_spectra.app_store import Store,atomic_json
from wp_spectra.investigation import build_inspection,compare_control,query_records,runtime_review,runtime_signature,administrator
from test_phase3a import http_app

BASE=Path(__file__).resolve().parents[1]


def evidence_full():
    value=json.loads((BASE/'evidence/phase3a/full-evidence.json').read_text())
    return {'static':value['static'],'dynamic':value['dynamic'],'partial_static':None,'partial_dynamic':None}


def job_for(evidence,mode='full',status='completed'):
    static=evidence['static'] or evidence['partial_static']['report']
    return {'id':str(uuid.uuid4()),'filename':'sample.zip','mode':mode,'status':status,'sha256':static['package']['sha256'],
            'analysis_id':static['analysis_id'],'plugin_name':'Test fixture','cleanup_verified':1}


def control_model(subject):
    raw=json.loads((BASE/'evidence/phase2/clean-control.json').read_text())
    # Synthetic compatible-environment fixture derived from actual control evidence.
    raw['images']=deepcopy(subject['dynamic']['images'])
    raw['configuration']=deepcopy(subject['dynamic']['configuration'])
    evidence={'static':raw['static_result'],'dynamic':raw,'partial_static':None,'partial_dynamic':None}
    return build_inspection(job_for(evidence,mode='control'),evidence,[])


class Phase3BTests(unittest.TestCase):
    def setUp(self):
        self.evidence=evidence_full();self.job=job_for(self.evidence)
    def model(self,**kwargs):return build_inspection(kwargs.get('job',self.job),kwargs.get('evidence',self.evidence),kwargs.get('progress',[]))
    def test_runtime_admin_is_explained_review_reason(self):
        model=self.model();assessment=model['overview']['assessment']
        self.assertEqual(assessment['code'],'review_recommended')
        self.assertTrue(any(f['policy_id']=='REVIEW-R1' and f['record_ids'] for f in assessment['factors']))
        self.assertNotIn('probability',assessment)
        self.assertEqual(assessment['priority'],'elevated')
    def test_static_capabilities_alone_are_not_elevated(self):
        model=self.model(job={**self.job,'mode':'static'},evidence={**self.evidence,'dynamic':None})
        self.assertEqual(model['overview']['assessment']['code'],'observations_only')
        self.assertFalse(model['overview']['assessment']['factors'])
        self.assertTrue(all(c['state']=='not_run' for c in model['overview']['coverage'] if c['category']!='static'))
    def test_benign_static_has_no_indicators_with_limited_scope(self):
        raw=json.loads((BASE/'evidence/benign.json').read_text())
        evidence={'static':raw,'dynamic':None,'partial_static':None,'partial_dynamic':None}
        model=self.model(job=job_for(evidence,'static'),evidence=evidence)
        self.assertEqual(model['overview']['assessment']['code'],'no_indicators_observed')
        self.assertIn('not executed',model['overview']['assessment']['scope'])
    def test_high_static_indicator_is_traceable(self):
        raw=json.loads((BASE/'evidence/suspicious.json').read_text())
        evidence={'static':raw,'dynamic':None,'partial_static':None,'partial_dynamic':None}
        model=self.model(job=job_for(evidence,'static'),evidence=evidence)
        factors=model['overview']['assessment']['factors']
        self.assertTrue(factors)
        self.assertTrue(all(f['policy_id']=='REVIEW-S1' for f in factors))
        self.assertTrue(all(any(r['record_id']==rid for r in model['records']) for f in factors for rid in f['record_ids']))
    def test_incomplete_precedes_review_and_retains_indicators(self):
        for status in ('failed','interrupted','running','rejected'):
            with self.subTest(status=status):
                model=self.model(job={**self.job,'status':status})
                self.assertEqual(model['overview']['assessment']['code'],'incomplete')
                self.assertTrue(model['overview']['assessment']['factors'])
    def test_partial_dynamic_provenance_is_explicit(self):
        evidence={**self.evidence,'dynamic':None,'partial_dynamic':{'partial':True,'report':self.evidence['dynamic']}}
        model=self.model(job={**self.job,'status':'interrupted'},evidence=evidence)
        record=next(r for r in model['records'] if r['source']=='runtime')
        self.assertEqual(record['trace']['artifact'],'dynamic.partial.json')
        self.assertTrue(record['trace']['json_pointer'].startswith('/report/'))
        self.assertTrue(model['overview']['partial'])
        self.assertEqual(model['overview']['assessment']['code'],'incomplete')
    def test_partial_static_does_not_establish_completion(self):
        evidence={**self.evidence,'static':None,'partial_static':{'partial':True,'report':self.evidence['static']},'dynamic':None}
        model=self.model(job={**self.job,'mode':'static','status':'interrupted'},evidence=evidence)
        self.assertTrue(model['records'][0]['trace']['json_pointer'].startswith('/report/'))
        self.assertEqual(model['overview']['assessment']['code'],'incomplete')
    def test_missing_coverage_or_cleanup_is_not_clean(self):
        self.evidence['dynamic']['coverage']['network']=False
        model=self.model()
        self.assertEqual(model['overview']['assessment']['code'],'incomplete')
        self.assertTrue(model['overview']['integrity_issues'])
        self.evidence=evidence_full()
        self.job['cleanup_verified']=0
        self.assertEqual(self.model()['overview']['assessment']['code'],'incomplete')
    def test_package_hash_and_id_mismatch_detected(self):
        self.job['sha256']='a'*64
        self.assertTrue(self.model()['overview']['integrity_issues'])
        self.job['sha256']=self.evidence['static']['package']['sha256']
        self.job['analysis_id']=str(uuid.uuid4())
        self.assertEqual(self.model()['overview']['assessment']['code'],'incomplete')
    def test_original_records_resolve_by_json_pointer(self):
        model=self.model()
        for record in model['records']:
            original=self.evidence['static'] if record['source']=='static' else self.evidence['dynamic']
            for part in record['trace']['json_pointer'].strip('/').split('/'):
                original=original[int(part)] if isinstance(original,list) else original[part]
            self.assertEqual(original,record['raw'])
            self.assertEqual(record['trace']['package_sha256'],self.job['sha256'])
    def test_timeline_includes_workflow_and_is_sorted(self):
        progress=[{'seq':1,'stage':'queued','observed_at':'2020-01-01T00:00:00Z','detail':'Queued'}]
        model=self.model(progress=progress)
        self.assertEqual(model['records'][0]['record_id'],'P:1')
        self.assertEqual(model['records'][0]['trace']['progress_seq'],1)
        self.assertEqual(len(model['records']),len(self.evidence['static']['static_findings'])+len(self.evidence['dynamic']['runtime_events'])+1)
    def test_filters_and_pagination(self):
        model=self.model()
        accounts=query_records(model,source='runtime',category='account',severity='high',stage='activate',q='veto_fixture_admin')
        self.assertEqual(accounts['total'],1)
        first=query_records(model,limit=10);second=query_records(model,limit=10,offset=10)
        self.assertFalse({r['record_id'] for r in first['items']}&{r['record_id'] for r in second['items']})
        self.assertEqual(query_records(model,q='no-such-resource-ever')['total'],0)
    def test_query_limits(self):
        for values in ({'source':'bad'},{'category':'shell'},{'severity':'malicious'},{'limit':101},{'offset':-1},{'q':'x'*201}):
            with self.subTest(values=values),self.assertRaises(ValueError):query_records(self.model(),**values)
    def test_sql_attempt_and_before_after_are_not_reinterpreted(self):
        model=self.model()
        audit=next(r for r in model['records'] if r['engine']=='mysql-general-log')
        self.assertIn('not proof of success',audit['limitation'])
        account=next(r for r in model['records'] if r['category']=='account')
        self.assertEqual(account['after'],account['raw']['after'])
        self.assertIsNone(account['before'])
    def test_admin_detection_requires_enabled_capability(self):
        self.assertFalse(administrator(json.dumps({'roles':'a:1:{s:13:"administrator";b:0;}','user_login':'administrator'})))
        self.assertTrue(administrator(json.dumps({'roles':{'administrator':True}})))
        self.assertFalse(administrator(json.dumps({'user_login':'administrator'})))
    def test_sensitive_paths_ignore_install_and_plain_text(self):
        event={'category':'file','action':'modified','resource':'wp-config.php','execution_stage':'activate'}
        self.assertEqual(runtime_review(event)[0],'REVIEW-R2')
        for path in ('wp-content/plugins/submission/main.php','wp-content/cache.txt'):
            self.assertIsNone(runtime_review({**event,'resource':path})[0])
        self.assertIsNone(runtime_review({**event,'execution_stage':'install'})[0])
    def test_control_patterns_do_not_discount_review(self):
        subject=self.model();control=control_model(subject)
        before=deepcopy(subject['overview']['assessment'])
        comparison=compare_control(subject,control)
        self.assertTrue(comparison['compatible'])
        self.assertGreater(comparison['counts'].get('also_observed_in_control',0),0)
        self.assertGreater(comparison['counts'].get('not_observed_in_control',0),0)
        self.assertEqual(subject['overview']['assessment'],before)
        self.assertTrue(comparison['policy_unchanged'])
        self.assertEqual(control['overview']['assessment']['code'],'control_observation')
    def test_incompatible_control_is_explicit(self):
        subject=self.model();control=control_model(subject)
        control['dynamic']['images']['sandbox']['id']='different'
        comparison=compare_control(subject,control)
        self.assertFalse(comparison['compatible']);self.assertTrue(comparison['mismatches'])
    def test_failed_or_noncontrol_reference_rejected(self):
        subject=self.model()
        with self.assertRaises(ValueError):compare_control(subject,subject)
        control=control_model(subject);control['overview']['assessment']['code']='incomplete'
        with self.assertRaises(ValueError):compare_control(subject,control)
    def test_normalization_preserves_different_sql_targets(self):
        def record(category,resource,action='created',engine='baseline-diff',text=''):
            return {'category':category,'resource':resource,'action':action,'engine':engine,'before':None,'after':None,'evidence':[{'text':text}]}
        self.assertEqual(runtime_signature(record('cron','100:hook:key')),runtime_signature(record('cron','200:hook:key')))
        self.assertEqual(runtime_signature(record('network','https://example.invalid/path?q=1')),runtime_signature(record('network','https://example.invalid/path?q=2')))
        self.assertNotEqual(runtime_signature(record('database','veto',engine='mysql-general-log',text='INSERT INTO fixture_table VALUES (1)')),
                            runtime_signature(record('database','veto',engine='mysql-general-log',text='INSERT INTO wp_options VALUES (1)')))
    def test_migration_preserves_existing_jobs(self):
        with tempfile.TemporaryDirectory() as root:
            store=Store(root)
            job_id=str(uuid.uuid4());store.directory(job_id).mkdir();store.create(job_id,'old.zip','static','a'*64,1)
            with store.connection() as db:db.execute('ALTER TABLE jobs DROP COLUMN control_for');db.execute('PRAGMA user_version=1')
            migrated=Store(root)
            self.assertEqual(migrated.get(job_id)['filename'],'old.zip')
            self.assertIsNone(migrated.get(job_id)['control_for'])
    def test_api_trace_routes_and_control_submission(self):
        with tempfile.TemporaryDirectory() as root:
            service=AppService(root,dispatch=False)
            try:
                job_id=self.job['id'];directory=service.store.directory(job_id);directory.mkdir()
                (directory/'submission.zip').write_bytes((BASE/'fixtures/dynamic/behaviors.zip').read_bytes())
                service.store.create(job_id,'behaviors.zip','full',self.job['sha256'],1)
                service.store.update(job_id,'completed','Saved',status='completed',analysis_id=self.job['analysis_id'],cleanup_verified=1)
                atomic_json(directory/'static.json',self.evidence['static']);atomic_json(directory/'dynamic.json',self.evidence['dynamic'])
                with http_app(service) as (server,base):
                    def get(path):return json.load(urllib.request.urlopen(base+path))
                    overview=get('/api/jobs/'+job_id+'/investigation')
                    self.assertEqual(overview['assessment']['code'],'review_recommended')
                    rows=get('/api/jobs/'+job_id+'/records?source=static&limit=1')
                    record=get('/api/jobs/'+job_id+'/records/'+rows['items'][0]['record_id'])
                    self.assertTrue(record['trace']['json_pointer'])
                    with self.assertRaises(urllib.error.HTTPError) as caught:get('/api/jobs/'+job_id+'/records/no-such-record')
                    self.assertEqual(caught.exception.code,404)
                    with self.assertRaises(urllib.error.HTTPError):get('/api/jobs/'+job_id+'/records?limit=9999')
                    request=urllib.request.Request(base+'/api/jobs/'+job_id+'/control',data=b'',headers={'X-VETO-Token':server.csrf})
                    created=json.load(urllib.request.urlopen(request))['job']
                    self.assertEqual(created['mode'],'control');self.assertEqual(created['control_for'],job_id)
                    self.assertEqual(get('/api/controls')['controls'][0]['id'],created['id'])
                    with self.assertRaises(urllib.error.HTTPError):urllib.request.urlopen(request)
                    self.assertEqual(len(service.store.history()),2)
            finally:service.close()
    def test_inspection_cache_invalidates_without_job_update(self):
        with tempfile.TemporaryDirectory() as root:
            service=AppService(root,dispatch=False)
            try:
                job_id=self.job['id'];directory=service.store.directory(job_id);directory.mkdir()
                service.store.create(job_id,'sample.zip','full',self.job['sha256'],1)
                service.store.update(job_id,'completed','Saved',status='completed',analysis_id=self.job['analysis_id'],cleanup_verified=1)
                atomic_json(directory/'static.json',self.evidence['static']);atomic_json(directory/'dynamic.json',self.evidence['dynamic'])
                first=service.inspection(job_id)
                self.assertIs(first,service.inspection(job_id))
                value=deepcopy(self.evidence['static']);value['static_findings'][0]['title']='Changed retained title'
                atomic_json(directory/'static.json',value)
                self.assertIsNot(first,service.inspection(job_id))
            finally:service.close()


if __name__=='__main__':unittest.main()
