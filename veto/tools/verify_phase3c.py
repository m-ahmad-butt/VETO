"""Full local upload -> sandbox -> inspection -> immutable report verification."""
import io
import json
from pathlib import Path
import unittest
import urllib.request
import uuid

from pypdf import PdfReader
from tools.verify_phase3a import RunningApp
from wp_spectra.app_store import atomic_json
from wp_spectra.dynamic import DynamicConfig
from wp_spectra.reports import digest,validate_bundle,ascii_text
from wp_spectra.scanner import now

BASE=Path(__file__).resolve().parents[1]


def main():
    evidence=BASE/'evidence/phase3c';evidence.mkdir(parents=True,exist_ok=True)
    output=BASE/'output/pdf';output.mkdir(parents=True,exist_ok=True)
    stream=io.StringIO();unit=unittest.TextTestRunner(stream=stream,verbosity=2).run(unittest.defaultTestLoader.discover(str(BASE/'tests')))
    (evidence/'tests.txt').write_text(stream.getvalue(),encoding='utf-8')
    print(f'Regression tests: {unit.testsRun}; passed={unit.wasSuccessful()}',flush=True)
    if not unit.wasSuccessful():print(stream.getvalue());return 1
    root=BASE/'.local'/('phase3c-'+uuid.uuid4().hex)
    app=RunningApp(root,DynamicConfig(action_timeout=5,observation_seconds=1))
    jobs={};exports={};checks={};pages={};saved={}
    def post(path,body=None):
        request=urllib.request.Request(app.base+path,data=json.dumps(body or {}).encode(),headers={'Content-Type':'application/json','X-VETO-Token':app.get('/api/bootstrap')['csrf_token']})
        return json.load(urllib.request.urlopen(request,timeout=60))
    try:
        jobs['completed']=app.submit(BASE/'fixtures/dynamic/behaviors.zip');app.finish(jobs['completed'])
        # The control endpoint explicitly requires an empty body.
        request=urllib.request.Request(app.base+'/api/jobs/'+jobs['completed']['id']+'/control',data=b'',headers={'X-VETO-Token':app.get('/api/bootstrap')['csrf_token']})
        control=json.load(urllib.request.urlopen(request))['job'];app.finish(control)
        jobs['failed']=app.submit(BASE/'fixtures/dynamic/activation_error.zip');app.finish(jobs['failed'])
        jobs['static-only']=app.submit(BASE/'fixtures/generated/benign.zip',mode='static');app.finish(jobs['static-only'])
        jobs['timeout']=app.submit(BASE/'fixtures/dynamic/timeout.zip');app.finish(jobs['timeout'])
        print('Completed analysis, control, activation failure, static-only and timeout workflows',flush=True)
        for name,job in jobs.items():
            options={'control_job_id':control['id']} if name=='completed' else {}
            before=app.detail(job)
            manifest=post('/api/jobs/'+job['id']+'/exports',options)['export'];exports[name]=manifest
            prefix='/api/jobs/'+job['id']+'/exports/'+manifest['export_id']+'/'
            json_response=urllib.request.urlopen(app.base+prefix+'report.json');json_data=json_response.read()
            pdf_response=urllib.request.urlopen(app.base+prefix+'report.pdf');pdf_data=pdf_response.read()
            report=json.loads(json_data);validate_bundle(report)
            pdf=PdfReader(io.BytesIO(pdf_data));text='\n'.join(page.extract_text() for page in pdf.pages);pages[name]=len(pdf.pages)
            checks[name+'_matching_dashboard']=report['overview']==app.get('/api/jobs/'+job['id']+'/investigation')
            checks[name+'_matching_checksums']=digest(json_data)==manifest['files']['report.json']['sha256'] and digest(pdf_data)==manifest['files']['report.pdf']['sha256']
            checks[name+'_pdf_indexes_every_record']=all(record['record_id'] in text for record in report['records'])
            checks[name+'_pdf_binds_companion_json']=digest(json_data) in text and report['export_id'] in text
            checks[name+'_assessment_consistent']=' '.join(ascii_text(report['overview']['assessment']['label']).split()) in ' '.join(text.split())
            checks[name+'_download_is_attachment']='attachment;' in pdf_response.headers['Content-Disposition'] and 'attachment;' in json_response.headers['Content-Disposition']
            checks[name+'_job_not_rerun']=app.detail(job)['progress']==before['progress']
            if name in ('failed','timeout'):checks[name+'_incomplete_warning']=report['overview']['assessment']['code']=='incomplete' and 'INCOMPLETE ANALYSIS' in text
            if name=='static-only':checks['static_only_scope_preserved']=report['overview']['counts']['runtime']==0 and 'plugin was not executed' in text
            if name=='completed':checks['control_comparison_exported']=report['comparison']['compatible'] and report['control']['job']['id']==control['id']
            (output/('veto-'+name+'.pdf')).write_bytes(pdf_data);(output/('veto-'+name+'.json')).write_bytes(json_data)
            saved[name]=(prefix,json_data,pdf_data)
        app.close();app=RunningApp(root)
        for name,(prefix,json_data,pdf_data) in saved.items():
            checks[name+'_survives_restart']=urllib.request.urlopen(app.base+prefix+'report.json').read()==json_data and urllib.request.urlopen(app.base+prefix+'report.pdf').read()==pdf_data
        checks['all_analysis_sandboxes_cleaned']=all(app.detail(job)['cleanup_verified']==1 for job in [*jobs.values(),control])
        summary={'generated_at':now(),'regression_tests':unit.testsRun,'checks':checks,'passed':all(checks.values()),'pdf_pages':pages,
                 'jobs':{name:job['id'] for name,job in jobs.items()},'control_job_id':control['id'],
                 'exports':exports,'test_data_directory':str(root),'purpose':'Controlled Phase 3C integration, report consistency and persistence checks'}
        atomic_json(evidence/'verification.json',summary)
        print(json.dumps({'passed':summary['passed'],'checks':len(checks),'failed':[key for key,value in checks.items() if not value],'pages':pages}),flush=True)
        return 0 if summary['passed'] else 1
    finally:app.close()


if __name__=='__main__':raise SystemExit(main())
