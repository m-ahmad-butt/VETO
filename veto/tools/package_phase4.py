"""Bundle source, evidence and academic files without local history or secrets."""
import hashlib
import json
from pathlib import Path
import zipfile

BASE=Path(__file__).resolve().parents[1]
ROOT=BASE.parent
from tools.phase4_paths import LOCAL, PACKAGES, VERIFICATION


def main():
    files=[]
    for folder in ('wp_spectra','sandbox','tests','tools','docs','fixtures','evidence','evaluation','submission'):
        files.extend(p for p in (BASE/folder).rglob('*') if p.is_file() and '__pycache__' not in p.parts and p.suffix not in ('.pyc','.pyo'))
    for folder in ('runs','samples','submission','verification'):
        files.extend(p for p in (LOCAL/folder).rglob('*') if p.is_file() and '__pycache__' not in p.parts)
    files.extend(BASE/name for name in ('Readme.md','requirements.txt','requirements-dev.txt','requirements-lock.txt'))
    files.extend(ROOT/name for name in ('README.md','03.pdf','.gitignore'))
    files.extend(BASE/name for name in ('.gitignore','.gitattributes'))
    files.extend(p for p in (LOCAL/'README.md',LOCAL/'relocation-manifest.json') if p.exists())
    excluded={VERIFICATION/'package-manifest.json',VERIFICATION/'package-verification.json'}
    files=sorted(set(files)-excluded)
    manifest={p.relative_to(ROOT).as_posix():{'sha256':hashlib.sha256(p.read_bytes()).hexdigest(),'size_bytes':p.stat().st_size} for p in files}
    VERIFICATION.mkdir(parents=True,exist_ok=True)
    manifest_path=VERIFICATION/'package-manifest.json'
    # Exclude a previous manifest from itself; include the new manifest in the ZIP.
    manifest.pop(manifest_path.relative_to(ROOT).as_posix(),None)
    files=[p for p in files if p!=manifest_path]
    manifest_path.write_text(json.dumps({'files':manifest,'exclusions':'Legacy web lab, local application data, venv, wheelhouse, temporary QA renders, Docker images and credentials are not included.'},indent=2)+'\n',encoding='utf-8')
    files.append(manifest_path)
    PACKAGES.mkdir(parents=True,exist_ok=True)
    target=PACKAGES/'VETO_PHASE4_SUBMISSION.zip'
    with zipfile.ZipFile(target,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as archive:
        for path in files:archive.write(path,path.relative_to(ROOT).as_posix())
    with zipfile.ZipFile(target) as archive:
        if archive.testzip() is not None:raise RuntimeError('Package CRC validation failed')
        for relative,expected in manifest.items():
            if hashlib.sha256(archive.read(relative)).hexdigest()!=expected['sha256']:raise RuntimeError('Archive content differs: '+relative)
    summary={'archive':str(target),'files':len(files),'bytes':target.stat().st_size,'sha256':hashlib.sha256(target.read_bytes()).hexdigest(),'verified':True}
    (VERIFICATION/'package-verification.json').write_text(json.dumps(summary,indent=2)+'\n',encoding='utf-8')
    print(json.dumps(summary,indent=2))


if __name__=='__main__':main()
