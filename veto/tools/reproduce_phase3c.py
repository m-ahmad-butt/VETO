"""Fresh source copy and venv using pinned, pre-downloaded local wheels.

Prepare output/wheels with pip download -r requirements-lock.txt first.
This verification installs offline, starts the app, uploads a benign static
fixture and downloads a matching report pair without Docker.
"""
import json
from pathlib import Path
import shutil
import socket
import subprocess
import time
import urllib.request
import uuid
import venv

BASE=Path(__file__).resolve().parents[1]


def main():
    root=BASE/'output'/('fresh-install-'+uuid.uuid4().hex)
    source=root/'source';source.mkdir(parents=True)
    shutil.copytree(BASE/'wp_spectra',source/'wp_spectra',ignore=shutil.ignore_patterns('__pycache__'))
    shutil.copy(BASE/'requirements-lock.txt',source/'requirements-lock.txt')
    environment=root/'venv';venv.EnvBuilder(with_pip=True).create(environment)
    python=environment/('Scripts/python.exe' if hasattr(subprocess,'CREATE_NO_WINDOW') else 'bin/python')
    install=subprocess.run([str(python),'-m','pip','install','--no-index','--find-links',str(BASE/'output/wheels'),'-r',str(source/'requirements-lock.txt')],capture_output=True,text=True,timeout=120)
    if install.returncode:raise RuntimeError(install.stdout+install.stderr)
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    flags=getattr(subprocess,'CREATE_NO_WINDOW',0)
    process=subprocess.Popen([str(python),'-m','wp_spectra','serve','--port',str(port),'--data-dir',str(root/'data')],cwd=source,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=flags)
    base=f'http://127.0.0.1:{port}';checks={}
    def get(path):return json.load(urllib.request.urlopen(base+path,timeout=10))
    try:
        for _ in range(100):
            try:get('/api/bootstrap');break
            except Exception:time.sleep(.1)
        else:raise RuntimeError('Fresh installation did not start')
        checks['fresh_venv_offline_install']=True
        token=get('/api/bootstrap')['csrf_token']
        request=urllib.request.Request(base+'/api/jobs?mode=static',data=(BASE/'fixtures/generated/benign.zip').read_bytes(),headers={'Content-Type':'application/zip','X-VETO-Token':token,'X-VETO-Filename':'benign.zip'})
        job=json.load(urllib.request.urlopen(request))['job']
        for _ in range(100):
            detail=get('/api/jobs/'+job['id'])
            if detail['status'] not in ('queued','running'):break
            time.sleep(.1)
        checks['fresh_source_static_analysis']=detail['status']=='completed'
        request=urllib.request.Request(base+'/api/jobs/'+job['id']+'/exports',data=b'{}',headers={'Content-Type':'application/json','X-VETO-Token':token})
        report=json.load(urllib.request.urlopen(request,timeout=30))['export']
        prefix='/api/jobs/'+job['id']+'/exports/'+report['export_id']+'/'
        checks['fresh_install_pdf_export']=urllib.request.urlopen(base+prefix+'report.pdf').read().startswith(b'%PDF-')
        checks['fresh_install_json_export']=get(prefix+'report.json')['export_id']==report['export_id']
        record={'passed':all(checks.values()),'checks':checks,'source_directory':str(source),'installation_log':install.stdout}
        target=BASE/'evidence/phase3c';target.mkdir(parents=True,exist_ok=True)
        (target/'fresh-install-verification.json').write_text(json.dumps(record,indent=2)+'\n',encoding='utf-8')
        print(json.dumps({'passed':record['passed'],'checks':checks}))
        return 0 if record['passed'] else 1
    finally:process.terminate();process.wait(timeout=10)


if __name__=='__main__':raise SystemExit(main())
