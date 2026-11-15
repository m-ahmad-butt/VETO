"""Live whole-job watchdog test; verifies evidence retrieval after deadline."""
import json
from pathlib import Path
import sys
from wp_spectra.dynamic import DynamicConfig,observe,validate_dynamic

BASE=Path(__file__).resolve().parents[1]

def main():
    report=observe(BASE/'fixtures/dynamic/timeout.zip',config=DynamicConfig(job_timeout=25,action_timeout=90,observation_seconds=1))
    validate_dynamic(report)
    path=BASE/'evidence/phase2/job-timeout.json'
    path.write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
    passed=report['status']=='failed' and report['cleanup']['verified'] and any(e['code']=='TimeoutError' for e in report['errors']) and bool(report['runtime_events'])
    print(json.dumps({'passed':passed,'status':report['status'],'errors':report['errors'],'retained_events':len(report['runtime_events']),'cleanup_verified':report['cleanup']['verified']}))
    return 0 if passed else 1

if __name__=='__main__':sys.exit(main())
