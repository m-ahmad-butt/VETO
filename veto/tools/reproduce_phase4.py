"""Fresh venv + frozen source + copied raw evidence; offline metric replay."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import uuid

BASE=Path(__file__).resolve().parents[1]
from tools.phase4_paths import RUNS, VERIFICATION


def main():
    original=RUNS/'final-v1'
    work=BASE/'output'/('phase4-reproduction-'+uuid.uuid4().hex[:8]);work.mkdir(parents=True)
    source=work/'source';shutil.copytree(original/'source',source)
    evidence=work/'saved-run';shutil.copytree(original,evidence)
    subprocess.run([sys.executable,'-m','venv',str(work/'venv')],check=True)
    python=work/'venv/Scripts/python.exe'
    install=subprocess.run([str(python),'-m','pip','install','--no-index','--find-links',str(BASE/'output/wheels'),'-r',str(source/'requirements-lock.txt'),'pypdf==7.1.0'],capture_output=True,text=True)
    (work/'install.log').write_text(install.stdout+install.stderr,encoding='utf-8')
    if install.returncode:raise RuntimeError('Offline installation failed; prepare lockfile and pypdf wheels first. See '+str(work/'install.log'))
    replay=subprocess.run([str(python),'-m','tools.evaluate_phase4','calculate',str(evidence)],cwd=source,capture_output=True,text=True)
    (work/'replay.log').write_text(replay.stdout+replay.stderr,encoding='utf-8')
    same=(original/'metrics.json').read_bytes()==(evidence/'metrics.json').read_bytes()
    result={'passed':replay.returncode==0 and same,'fresh_venv':True,'offline_locked_install':install.returncode==0,
            'frozen_source_replay':replay.returncode==0,'identical_metric_bytes':same,'work_directory':str(work),
            'scope':'Automated same-machine reproduction, not independent teammate sign-off or a new runtime experiment.',
            'metrics_sha256':hashlib.sha256((evidence/'metrics.json').read_bytes()).hexdigest()}
    (VERIFICATION/'fresh-reproduction.json').write_text(json.dumps(result,indent=2)+'\n',encoding='utf-8')
    print(json.dumps(result,indent=2))
    return 0 if result['passed'] else 1


if __name__=='__main__':raise SystemExit(main())
