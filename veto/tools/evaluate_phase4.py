"""Frozen Phase 4 experiment and replayable measurements. No detector tuning.

python -m tools.build_evaluation
python -m tools.evaluate_phase4 run --name final-v1
python -m tools.evaluate_phase4 calculate ../Evaluations/runs/final-v1
"""
import argparse
from collections import Counter
from dataclasses import asdict
import hashlib
import io
import json
from pathlib import Path
import platform
import shutil
import subprocess
import sys
import uuid

from pypdf import PdfReader
from wp_spectra.app_service import AppService
from wp_spectra.app_store import atomic_json
from wp_spectra.dynamic import DynamicConfig, observe, validate_dynamic
from wp_spectra.reports import validate_bundle
from wp_spectra.scanner import analyze, now
from wp_spectra.__main__ import validate_report

BASE = Path(__file__).resolve().parents[1]
EVAL = BASE/'evaluation'
from tools.phase4_paths import SAMPLES, RUNS, package_path, source_path


def digest(data): return hashlib.sha256(data).hexdigest()


def ratio(numerator, denominator):
    if not 0 <= numerator <= denominator: raise ValueError('Invalid metric counts')
    return {'numerator':numerator,'denominator':denominator,
            'value':numerator/denominator if denominator else None,
            'percent':100*numerator/denominator if denominator else None}


def classification(rows):
    counts = Counter()
    for row in rows:
        if row['label'] not in ('benign','suspicious'): raise ValueError('Unknown static ground truth')
        if row['status'] != 'completed':
            counts['incomplete'] += 1
            continue
        actual = row['label']=='suspicious'
        predicted = row['flagged']
        counts['tp' if actual and predicted else 'fn' if actual else 'fp' if predicted else 'tn'] += 1
    tp,fp,tn,fn = (counts[k] for k in ('tp','fp','tn','fn'))
    # Incomplete scans are reported separately; final evaluation requires none.
    return {'tp':tp,'fp':fp,'tn':tn,'fn':fn,'incomplete':counts['incomplete'],
            'precision':ratio(tp,tp+fp),'recall':ratio(tp,tp+fn),'false_positive_rate':ratio(fp,fp+tn)}


def confirmed(behavior, witness):
    """Require operation assertion and independent persisted-state corroboration."""
    if witness.get('error') or not witness.get('method'): return False
    if witness.get('receipts',{}).get(behavior,{}).get('ok') is not True: return False
    options = witness.get('options',{})
    checks = {
        'file_create':witness.get('persistent_file')=='modified',
        'file_modify':witness.get('persistent_file')=='modified',
        'transient_delete':witness.get('transient_exists') is False,
        'database_write':options.get('phase4_value')=='phase4-confirmed',
        'account_create':any(a['user_login']=='phase4_admin' and '"administrator";b:1' in a['meta_value'] for a in witness.get('accounts',[])),
        'cron_schedule':'phase4_future' in (options.get('cron') or ''),
        'cron_execute':options.get('phase4_cron_executed')=='yes',
    }
    return checks.get(behavior, True)


def matching(behavior, event):
    category,action,resource,collector = (event[k] for k in ('category','action','resource','collector'))
    evidence = '\n'.join(e['text'] for e in event['evidence'])
    rules = {
        'file_create':category=='file' and action=='created' and resource=='wp-content/phase4-persistent.txt',
        'file_modify':category=='file' and action=='modified' and resource=='wp-content/phase4-persistent.txt' and collector=='baseline-diff',
        'transient_create':category=='file' and action=='created' and resource=='wp-content/phase4-transient.txt' and collector=='inotify',
        'transient_delete':category=='file' and action=='deleted' and resource=='wp-content/phase4-transient.txt' and collector=='inotify',
        'database_write':category=='database' and collector=='mysql-general-log' and 'phase4_value' in evidence and 'phase4-confirmed' in evidence,
        'account_create':category=='account' and action=='created' and 'phase4_admin' in (event['after'] or '') and 'administrator' in (event['after'] or ''),
        'cron_schedule':category=='cron' and action=='created' and ':phase4_future:' in resource,
        'cron_execute':category=='database' and collector=='mysql-general-log' and 'phase4_cron_executed' in evidence,
        'http_blocked':category=='network' and action=='http_blocked' and resource=='http://phase4.example.invalid/blocked',
        'http_received':category=='network' and action=='controlled_http_received' and resource=='/phase4-controlled' and collector=='controlled-http-sink',
        'dns_attempt':category=='network' and action=='dns_blocked' and resource=='phase4-dns.example.invalid' and collector=='dns-sink',
        'direct_ip_attempt':category=='network' and '192.0.2.1' in resource,
    }
    if behavior not in rules: raise ValueError('Unknown behavior '+behavior)
    return rules[behavior]


def report_preserved(event, export, pdf_text):
    """Exactly one event copy, full semantic JSON preservation, PDF record index."""
    records = [r for r in export['records'] if r['source']=='runtime' and r['record_id']=='R:'+event['event_id']]
    raw = export['artifacts'].get('dynamic.json',{}).get('runtime_events',[])
    raw_matches = [r for r in raw if r['event_id']==event['event_id']]
    if len(records)!=1 or raw_matches != [event]: return False
    record = records[0]
    return (all(record[k]==event[k] for k in ('category','action','resource','before','after','evidence'))
            and record['record_id'] in pdf_text)


def source_inventory():
    paths = [p for root in (BASE/'wp_spectra',BASE/'sandbox') for p in root.rglob('*')
             if p.is_file() and '__pycache__' not in p.parts and p.suffix not in ('.pyc','.pyo')]
    paths += [BASE/'requirements-lock.txt',EVAL/'manifest.json',Path(__file__),BASE/'tools/build_evaluation.py',BASE/'tools/phase4_ground_truth.php']
    paths += list(SAMPLES.glob('*'))
    paths.append(BASE/'tools/phase4_paths.py')
    return {('evaluation/samples/'+p.name if p.parent==SAMPLES else p.relative_to(BASE).as_posix()):digest(p.read_bytes()) for p in sorted(paths)}


def command(argv):
    process = subprocess.run(argv,capture_output=True,text=True,timeout=30)
    if process.returncode: raise RuntimeError(process.stderr[-1000:])
    return process.stdout.strip()


def run(name):
    if not name or any(c not in 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_' for c in name): raise ValueError('Use a simple run name')
    manifest = json.loads((EVAL/'manifest.json').read_text())
    for case in manifest['cases']:
        if digest((package_path(case)).read_bytes())!=case['sha256']:raise ValueError('Package hash mismatch')
    environment = {'python':sys.version,'platform':platform.platform(),
                   'packages':command([sys.executable,'-m','pip','freeze']),
                   'docker_server':command(['docker','version','--format','{{.Server.Version}}']),
                   'images':{name:json.loads(command(['docker','image','inspect',name,'--format','{{json .Id}}'])) for name in ('veto-sandbox:phase2','mysql:8.0','wordpress:cli-php8.3')}}
    root=RUNS/name; root.mkdir(parents=True,exist_ok=False)
    config=DynamicConfig(action_timeout=15,observation_seconds=2)
    frozen=source_inventory()
    atomic_json(root/'freeze.json',{'frozen_at':now(),'sources':frozen,'configuration':asdict(config),'environment':environment,
                                  'protocol':'One execution per new case; one clean control. No tuning or case removal after results. Incomplete outcomes invalidate final static metrics until disclosed/rerun.',
                                  'split':'Existing fixtures/ are development; evaluation/samples are new evaluation cases; same-author design limits independence.'})
    atomic_json(root/'manifest.json',manifest)
    for relative in frozen:
        destination=root/'source'/relative;destination.parent.mkdir(parents=True,exist_ok=True)
        shutil.copyfile(source_path(relative),destination)
    entries=[]
    service=AppService(BASE/'.local'/('evaluation-'+name+'-'+uuid.uuid4().hex),config,dispatch=False)
    atomic_json(root/'workspace.json',{'path':str(service.store.root)})
    try:
        sequence=[*manifest['cases'],{**next(c for c in manifest['cases'] if c['id']=='d-benign'),'id':'control','scope':'control','expected_behaviors':[]}]
        for case in sequence:
            print('Evaluating '+case['id'],flush=True)
            case_root=root/case['id'];case_root.mkdir()
            static=analyze(package_path(case));validate_report(static)
            job_id=str(uuid.uuid4());directory=service.store.directory(job_id);directory.mkdir()
            service.store.create(job_id,Path(case['package']).name,'static' if case['scope']=='static' else 'control' if case['scope']=='control' else 'full',static['package']['sha256'],static['package']['size_bytes'])
            atomic_json(directory/'static.json',static);atomic_json(case_root/'static.json',static)
            service.store.update(job_id,'scanning','Evaluation static result saved',status='running',analysis_id=static['analysis_id'])
            witness={};dynamic=None
            if case['scope']!='static':
                def progress(stage, detail):
                    service.store.update(job_id,stage,detail)
                    if stage=='cleaning_up':
                        container='veto-'+uuid.UUID(static['analysis_id']).hex[:12]+'-wp'
                        try:
                            process=subprocess.run(['docker','exec','-i',container,'php'],input=(BASE/'tools/phase4_ground_truth.php').read_bytes(),capture_output=True,timeout=20)
                            if process.returncode:raise RuntimeError(process.stderr.decode(errors='replace')[:500])
                            witness.update(json.loads(process.stdout))
                        except Exception as exc:witness['error']=str(exc)
                        atomic_json(case_root/'ground-truth.json',witness)
                dynamic=observe(package_path(case),config=config,control=case['scope']=='control',static_result=static,on_progress=progress)
                validate_dynamic(dynamic)
                atomic_json(directory/'dynamic.json',dynamic);atomic_json(case_root/'dynamic.json',dynamic)
            result=dynamic or static
            service.store.update(job_id,result['status'],'Frozen evaluation result',status=result['status'],finished_at=now(),cleanup_verified=int(dynamic['cleanup']['verified']) if dynamic else None)
            export=service.reports.create(job_id)
            for filename in ('report.json','report.pdf','manifest.json'):
                shutil.copyfile(service.reports.root(job_id)/export['export_id']/filename,case_root/filename)
            atomic_json(case_root/'progress.json',service.store.progress(job_id))
            atomic_json(case_root/'case.json',case)
            entries.append({'case':case,'job_id':job_id,'analysis_id':static['analysis_id'],'status':result['status']})
            atomic_json(root/'run-index.json',entries)
            print(case['id']+': '+result['status'],flush=True)
        remaining=[]
        for entry in entries:
            if entry['case']['scope']=='static':continue
            for args in (['ps','-aq'],['volume','ls','-q'],['network','ls','-q']):
                output=command(['docker',*args,'--filter','label=veto.analysis_id='+entry['analysis_id']])
                if output:remaining.append({'analysis_id':entry['analysis_id'],'resources':output})
        atomic_json(root/'cleanup.json',{'checked_at':now(),'remaining':remaining,'passed':not remaining})
        atomic_json(root/'freeze-check.json',{'checked_at':now(),'unchanged':frozen==source_inventory()})
    finally:service.close()
    # Seal experiment outputs before offline calculation.
    atomic_json(root/'raw-hashes.json',{p.relative_to(root).as_posix():digest(p.read_bytes()) for p in sorted(root.rglob('*')) if p.is_file()})
    return calculate(root)


def calculate(root):
    root=Path(root)
    hashes=json.loads((root/'raw-hashes.json').read_text())
    for relative,expected in hashes.items():
        if digest((root/relative).read_bytes())!=expected:raise ValueError('Saved experiment changed: '+relative)
    entries=json.loads((root/'run-index.json').read_text())
    static_rows=[];behaviors=[];completeness=[];outcomes=[]
    for entry in entries:
        case=entry['case'];directory=root/case['id']
        static=json.loads((directory/'static.json').read_text());validate_report(static)
        if case['scope']=='static':
            static_rows.append({'id':case['id'],'label':case['label'],'status':static['status'],'flagged':static['summary']['flagged_for_review'],
                                'rules':sorted({f['rule_id'] for f in static['static_findings']}),'rationale':case['rationale']})
        export=json.loads((directory/'report.json').read_text());validate_bundle(export)
        pdf_text='\n'.join(p.extract_text() for p in PdfReader(directory/'report.pdf').pages)
        if case['scope']!='static':
            dynamic=json.loads((directory/'dynamic.json').read_text());validate_dynamic(dynamic)
            witness=json.loads((directory/'ground-truth.json').read_text())
            for behavior in case['expected_behaviors']:
                triggered=confirmed(behavior,witness)
                matches=[e['event_id'] for e in dynamic['runtime_events'] if matching(behavior,e)]
                behaviors.append({'case':case['id'],'behavior':behavior,'confirmed_triggered':triggered,
                                  'matched_event_ids':matches,'detected':triggered and bool(matches)})
            for event in dynamic['runtime_events']:
                completeness.append({'case':case['id'],'event_id':event['event_id'],'correctly_exported':report_preserved(event,export,pdf_text)})
            outcomes.append({'case':case['id'],'status':dynamic['status'],'cleanup':dynamic['cleanup']['verified'],'ground_truth_error':witness.get('error')})
    static_metrics=classification(static_rows)
    metric_values={'static_precision':static_metrics['precision'],'static_recall':static_metrics['recall'],
                   'dynamic_event_detection_rate':ratio(sum(b['detected'] for b in behaviors),sum(b['confirmed_triggered'] for b in behaviors)),
                   'false_positive_rate':static_metrics['false_positive_rate'],
                   'report_completeness':ratio(sum(c['correctly_exported'] for c in completeness),len(completeness))}
    freeze_ok=json.loads((root/'freeze-check.json').read_text())['unchanged']
    cleanup_ok=json.loads((root/'cleanup.json').read_text())['passed']
    valid=freeze_ok and cleanup_ok and static_metrics['incomplete']==0 and all(o['status']=='completed' and o['cleanup'] and not o['ground_truth_error'] for o in outcomes) and all(b['confirmed_triggered'] for b in behaviors)
    result={'schema_version':'1.0.0','valid_experiment':valid,'metrics':metric_values,'confusion_matrix':static_metrics,
            'sample_sizes':{'static_plugins':len(static_rows),'benign_static':sum(r['label']=='benign' for r in static_rows),'suspicious_static':sum(r['label']=='suspicious' for r in static_rows),
                            'runtime_runs_including_control':len(outcomes),'planned_test_behaviors':len(behaviors),'confirmed_test_behaviors':sum(b['confirmed_triggered'] for b in behaviors),'recorded_runtime_events':len(completeness)},
            'false_positives':[r['id'] for r in static_rows if r['label']=='benign' and r['flagged']],
            'false_negatives':[r['id'] for r in static_rows if r['label']=='suspicious' and not r['flagged'] and r['status']=='completed'],
            'dynamic_misses':[b for b in behaviors if b['confirmed_triggered'] and not b['detected']],
            'unconfirmed_behaviors':[b for b in behaviors if not b['confirmed_triggered']],
            'static_results':static_rows,'behaviors':behaviors,'report_event_checks':completeness,'runtime_outcomes':outcomes,
            'interpretation':'Synthetic same-author evaluation, one trial per case. FPR uses the static review flag on 8 benign static cases. Dynamic denominator is confirmed operation instances, not all system events. Completeness requires full JSON event preservation and a PDF index entry; it does not require unabridged evidence in PDF. No general real-world malware accuracy claim.'}
    atomic_json(root/'metrics.json',result)
    import csv
    with (root/'metrics.csv').open('w',newline='',encoding='utf-8') as stream:
        writer=csv.writer(stream);writer.writerow(['metric','numerator','denominator','percent'])
        for key,value in metric_values.items():writer.writerow([key,value['numerator'],value['denominator'],value['percent']])
    print(json.dumps({'valid_experiment':valid,'metrics':metric_values,'sizes':result['sample_sizes']},indent=2),flush=True)
    return result


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    sub=parser.add_subparsers(dest='command',required=True)
    sub.add_parser('run').add_argument('--name',required=True)
    sub.add_parser('calculate').add_argument('directory',type=Path)
    args=parser.parse_args()
    value=run(args.name) if args.command=='run' else calculate(args.directory)
    raise SystemExit(0 if value['valid_experiment'] else 1)
