"""Independent audit of saved experiment integrity and report-pair binding."""
import argparse
import hashlib
import json
from pathlib import Path
import re
from pypdf import PdfReader
from tools.evaluate_phase4 import calculate
from wp_spectra.scanner import now

BASE=Path(__file__).resolve().parents[1]
from tools.phase4_paths import RUNS, VERIFICATION


def main(root):
    before=(root/'metrics.json').read_bytes()
    recalculated=calculate(root)
    checks={'metrics_replay_identical':before==(root/'metrics.json').read_bytes(),
            'experiment_valid':recalculated['valid_experiment']}
    entries=json.loads((root/'run-index.json').read_text())
    for entry in entries:
        name=entry['case']['id'];directory=root/name
        manifest=json.loads((directory/'manifest.json').read_text())
        report=json.loads((directory/'report.json').read_text())
        pdf='\n'.join(p.extract_text() for p in PdfReader(directory/'report.pdf').pages)
        for filename in ('report.json','report.pdf'):
            data=(directory/filename).read_bytes();expected=manifest['files'][filename]
            checks[name+'_'+filename+'_bytes']=hashlib.sha256(data).hexdigest()==expected['sha256'] and len(data)==expected['size_bytes']
        checks[name+'_pair_binding']=report['export_id']==manifest['export_id'] and report['export_id'] in pdf and hashlib.sha256((directory/'report.json').read_bytes()).hexdigest() in pdf
        checks[name+'_all_record_indexes']=all(record['record_id'] in pdf for record in report['records'])
        checks[name+'_raw_static_matches']=report['artifacts']['static.json']==json.loads((directory/'static.json').read_text())
        if entry['case']['scope']!='static':
            checks[name+'_raw_dynamic_matches']=report['artifacts']['dynamic.json']==json.loads((directory/'dynamic.json').read_text())
    regression=(VERIFICATION/'regression.txt').read_text(encoding='utf-16')
    checks['100_regression_tests_passed']=bool(re.search(r'Ran 100 tests',regression)) and bool(re.search(r'\bOK\s*$',regression))
    result={'verified_at':now(),'run':root.name,'checks':checks,'passed':all(checks.values()),'check_count':len(checks)}
    target=VERIFICATION;target.mkdir(parents=True,exist_ok=True)
    (target/'verification.json').write_text(json.dumps(result,indent=2)+'\n',encoding='utf-8')
    print(json.dumps({'checks':len(checks),'passed':result['passed'],'failed':[k for k,v in checks.items() if not v]}))
    return 0 if result['passed'] else 1


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('root',nargs='?',type=Path,default=RUNS/'final-v1')
    raise SystemExit(main(parser.parse_args().root))
