"""Real local Docker acceptance. Uses only generated controlled test plugins.

Run from veto/: python -m tools.verify_phase2
No tags, commits, push, production credentials, or unrelated Docker cleanup.
"""
from collections import Counter
from dataclasses import replace
import hashlib
import io
import json
from pathlib import Path
import subprocess
import sys
import unittest

from tools.build_dynamic_fixtures import build
from wp_spectra.dynamic import DynamicConfig,observe,validate_dynamic
from wp_spectra.scanner import now

BASE=Path(__file__).resolve().parents[1]


def main():
    build()
    evidence=BASE/'evidence/phase2'
    evidence.mkdir(parents=True,exist_ok=True)
    suite=unittest.defaultTestLoader.discover(str(BASE/'tests'))
    log=io.StringIO()
    unit=unittest.TextTestRunner(stream=log,verbosity=2).run(suite)
    (evidence/'tests.txt').write_text(log.getvalue(),encoding='utf-8')
    print(f'Offline tests: {unit.testsRun}; failures={len(unit.failures)} errors={len(unit.errors)}',flush=True)
    if not unit.wasSuccessful():
        print(log.getvalue())
        return 1
    config=DynamicConfig(observation_seconds=1,action_timeout=12)
    results={}
    sequence=[('clean-control','benign',True,config),('behaviors','behaviors',False,config),
              ('fresh-benign','benign',False,config),('activation-error','activation_error',False,config),
              ('timeout','timeout',False,replace(config,action_timeout=3)),
              ('monitor-limit','monitor_limit',False,config)]
    for name,fixture,control,settings in sequence:
        print('Running '+name+'...',flush=True)
        report=observe(BASE/'fixtures/dynamic'/f'{fixture}.zip',config=settings,control=control)
        (evidence/f'{name}.json').write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
        validate_dynamic(report)
        results[name]=report
        print(f"{name}: {report['status']}; {dict(Counter(e['category'] for e in report['runtime_events']))}; cleanup={report['cleanup']['verified']}",flush=True)
        if report['errors']:print(report['errors'],flush=True)
    behavior=results['behaviors']
    events=behavior['runtime_events']
    final=behavior['final_snapshot'] or {}
    options=final.get('option_hashes',{})
    def event(category,action=None,resource=None,collector=None):
        return any(e['category']==category and (action is None or e['action']==action)
                   and (resource is None or resource in e['resource']) and (collector is None or e['collector']==collector) for e in events)
    checks={
        'offline_tests':unit.wasSuccessful(),
        'clean_control_completed':results['clean-control']['status']=='completed',
        'behaviors_completed':behavior['status']=='completed',
        'five_categories_observed':set(Counter(e['category'] for e in events))=={'file','database','account','cron','network'},
        'transient_file_created':event('file','created','veto-transient.txt','inotify'),
        'transient_file_deleted':event('file','deleted','veto-transient.txt','inotify'),
        'transient_file_absent_in_final':'wp-content/veto-transient.txt' not in final.get('files',{}),
        'persistent_file_modified':event('file','modified','veto-persistent.txt','baseline-diff'),
        'database_table_created':event('database','created','veto_fixture_table','baseline-diff'),
        'direct_sql_audited':any(e['collector']=='mysql-general-log' and 'veto_fixture_table' in e['evidence'][0]['text'] for e in events),
        'administrator_created':any(u['user_login']=='veto_fixture_admin' and 'administrator' in u['roles'] for u in final.get('accounts',{}).values()),
        'cron_registration_observed':event('cron','created','veto_fixture_cron'),
        'cron_executed':options.get('veto_fixture_cron_ran')==hashlib.sha256(b'yes').hexdigest(),
        'frontend_executed':options.get('veto_fixture_frontend_ran')==hashlib.sha256(b'yes').hexdigest(),
        'authenticated_admin_executed':options.get('veto_fixture_admin_ran')==hashlib.sha256(b'yes').hexdigest(),
        'outbound_http_blocked':event('network','http_blocked','example.invalid','wordpress-http-hook'),
        'dns_attempt_blocked':event('network','dns_blocked','veto-fixture-dns.example.invalid','dns-sink'),
        'controlled_endpoint_received':event('network','controlled_http_received','/veto-fixture-controlled','controlled-http-sink'),
        'fresh_benign_completed':results['fresh-benign']['status']=='completed',
        'no_prior_file_in_fresh_baseline':'wp-content/veto-persistent.txt' not in (results['fresh-benign']['baseline'] or {}).get('files',{}),
        'no_prior_user_in_fresh_baseline':not any(u['user_login']=='veto_fixture_admin' for u in (results['fresh-benign']['baseline'] or {}).get('accounts',{}).values()),
        'no_prior_table_in_fresh_baseline':'veto_fixture_table' not in (results['fresh-benign']['baseline'] or {}).get('tables',{}),
        'activation_failure_explicit':results['activation-error']['status']=='failed' and any(e['stage']=='activate' for e in results['activation-error']['errors']),
        'activation_failure_evidence_retained':bool(results['activation-error']['runtime_events']),
        'timeout_explicit':results['timeout']['status']=='failed' and any(e['code']=='TimeoutError' and e['stage']=='activate' for e in results['timeout']['errors']),
        'monitor_failure_explicit':results['monitor-limit']['status']=='failed' and bool(results['monitor-limit']['observer_health'].get('errors')),
        'every_run_cleaned':all(r['cleanup']['verified'] and not r['cleanup']['remaining'] for r in results.values()),
        'plugin_nonroot':behavior['isolation'].get('user')=='33:33',
        'internal_network':behavior['isolation'].get('internal_network') is True,
        'isolated_gateway_no_bridge_host_address':behavior['isolation'].get('gateway_mode')=='isolated',
        'no_host_bind_mounts':all(m['type']!='bind' for m in behavior['isolation'].get('mounts',[])),
        'no_published_ports':not behavior['isolation'].get('published_ports'),
        'read_only_rootfs':behavior['isolation'].get('read_only_rootfs') is True,
        'no_capabilities_or_privilege_escalation':behavior['isolation'].get('cap_drop')==['ALL'] and behavior['isolation'].get('no_new_privileges') is True,
    }
    # Independent post-run resource listing for each exact UUID; never delete here.
    remaining=[]
    for report in results.values():
        for command in (['ps','-aq'],['volume','ls','-q'],['network','ls','-q']):
            process=subprocess.run(['docker',*command,'--filter',f"label=veto.analysis_id={report['analysis_id']}"],capture_output=True,text=True,timeout=10)
            if process.returncode:remaining.append(process.stderr)
            elif process.stdout.strip():remaining.append(process.stdout.strip())
    checks['independent_cleanup_listing_empty']=not remaining
    summary={'generated_at':now(),'purpose':'Controlled local Phase 2 acceptance, not final detection accuracy',
             'offline_tests':unit.testsRun,'checks':checks,'passed':all(checks.values()),'remaining_resources':remaining,
             'runs':{name:{'analysis_id':r['analysis_id'],'status':r['status'],'events':dict(Counter(e['category'] for e in r['runtime_events']))} for name,r in results.items()}}
    (evidence/'verification.json').write_text(json.dumps(summary,indent=2)+'\n',encoding='utf-8')
    print(json.dumps(checks,indent=2),flush=True)
    return 0 if summary['passed'] else 1


if __name__=='__main__':sys.exit(main())
