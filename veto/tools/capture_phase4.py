"""Optional QA Python: Playwright/Edge + PyMuPDF. Record real retained evidence."""
import argparse
import json
from pathlib import Path
import socket
import subprocess
import time
import urllib.request

import pymupdf
from playwright.sync_api import sync_playwright,expect

BASE=Path(__file__).resolve().parents[1]
from tools.phase4_paths import RUNS, SUBMISSION, VERIFICATION, LOCAL
OUT=SUBMISSION


def main(run_name):
    root=RUNS/run_name
    entries=json.loads((root/'run-index.json').read_text())
    ids={e['case']['id']:e['job_id'] for e in entries}
    workspace=json.loads((root/'workspace.json').read_text())['path']
    shots=OUT/'screenshots';shots.mkdir(parents=True,exist_ok=True)
    video=LOCAL/'qa/video';video.mkdir(parents=True,exist_ok=True)
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    server=subprocess.Popen([str(BASE/'.venv/Scripts/python.exe'),'-m','wp_spectra','serve','--port',str(port),'--data-dir',workspace],cwd=BASE,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    address=f'http://127.0.0.1:{port}'
    checks={};errors=[]
    try:
        for _ in range(100):
            try:urllib.request.urlopen(address,timeout=1).close();break
            except Exception:time.sleep(.1)
        else:raise RuntimeError('QA server did not start')
        with sync_playwright() as p:
            browser=p.chromium.launch(channel='msedge',headless=True)
            context=browser.new_context(viewport={'width':1440,'height':1000},record_video_dir=str(video),record_video_size={'width':1440,'height':1000},accept_downloads=True,timezone_id='Asia/Karachi')
            page=context.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
            page.goto(address+'/#'+ids['d-combined'])
            expect(page.locator('#selected-id')).to_have_text(ids['d-combined'])
            expect(page.locator('#investigation-panel')).to_contain_text('Review recommended',timeout=20000)
            page.screenshot(path=str(shots/'dashboard.png'),full_page=True)
            page.wait_for_timeout(3000)
            page.locator('#open-investigation').click()
            page.locator('#investigation').screenshot(path=str(shots/'assessment.png'))
            page.wait_for_timeout(3000)
            page.locator('#tab-runtime').click()
            page.locator('#evidence-search').fill('phase4_admin')
            page.locator('#evidence-category').select_option('account')
            page.get_by_role('button',name='Apply filters',exact=True).click()
            expect(page.locator('.record-table tbody tr')).to_have_count(1)
            page.locator('.record-link').first.click()
            expect(page.locator('#record-content')).to_contain_text('phase4_admin')
            expect(page.locator('#record-content')).to_contain_text('administrator')
            page.locator('#record-dialog').screenshot(path=str(shots/'account-evidence.png'))
            checks['actual_account_evidence_visible']=True
            page.wait_for_timeout(4000);page.locator('#close-record').click()
            page.locator('#tab-timeline').click()
            expect(page.locator('.record-table tbody tr')).to_have_count(25)
            page.locator('#investigation').screenshot(path=str(shots/'timeline.png'))
            page.wait_for_timeout(3000)
            page.locator('#tab-comparison').click()
            page.locator('#control-select').select_option(ids['control'])
            expect(page.locator('#comparison-results')).to_contain_text('Patterns also observed in control:',timeout=20000)
            page.locator('#investigation').screenshot(path=str(shots/'control.png'))
            checks['actual_clean_control_comparison_visible']=True
            page.wait_for_timeout(3000)
            page.locator('#tab-coverage').click()
            expect(page.locator('#investigation-panel')).to_contain_text('Direct-IP')
            page.locator('#investigation').screenshot(path=str(shots/'coverage.png'))
            page.wait_for_timeout(3000)
            page.locator('#reports').scroll_into_view_if_needed()
            expect(page.locator('#report-list .saved-report')).to_have_count(1)
            for extension in ('JSON','PDF'):
                with page.expect_download() as download:page.get_by_role('link',name='Download '+extension,exact=True).first.click()
                data=download.value.path()
                checks[extension.lower()+'_download']=Path(data).stat().st_size>0
            page.locator('#reports').screenshot(path=str(shots/'reports.png'))
            page.wait_for_timeout(3000)
            recording=page.video
            context.close();recording.save_as(str(OUT/'VETO_BACKUP_DEMO.webm'))
            browser.close()
        checks['no_browser_errors']=not errors
    finally:
        server.terminate()
        try:server.wait(timeout=10)
        except subprocess.TimeoutExpired:server.kill();server.wait()
    # Update report/deck source to embed the newly captured evidence screenshot.
    subprocess.run([str(BASE/'.venv/Scripts/python.exe'),'-m','tools.prepare_submission','--run',run_name],cwd=BASE,check=True)
    with sync_playwright() as p:
        browser=p.chromium.launch(channel='msedge',headless=True)
        page=browser.new_page(viewport={'width':1280,'height':720})
        page.goto((OUT/'presentation.html').as_uri())
        expect(page.locator('section.active')).to_have_count(1)
        slide_shots=LOCAL/'qa/slides';slide_shots.mkdir(parents=True,exist_ok=True)
        count=page.locator('section').count()
        for i in range(count):
            page.evaluate('(i)=>show(i)',i)
            checks['slide_'+str(i+1)+'_fits']=page.locator('section.active').evaluate('(s)=>s.scrollHeight<=s.clientHeight && s.scrollWidth<=s.clientWidth')
            page.screenshot(path=str(slide_shots/f'slide-{i+1:02}.png'))
        page.pdf(path=str(OUT/'presentation.pdf'),prefer_css_page_size=True,print_background=True)
        browser.close()
    # Render every academic PDF page; inspect text bounds as an additional check.
    render=LOCAL/'qa/pdfs';render.mkdir(parents=True,exist_ok=True)
    pdfs={}
    for filename in ('VETO_FINAL_REPORT.pdf','presentation.pdf'):
        doc=pymupdf.open(OUT/filename);folder=render/filename[:-4];folder.mkdir(exist_ok=True)
        bad=[]
        for n,page in enumerate(doc):
            page.get_pixmap(matrix=pymupdf.Matrix(1,1)).save(folder/f'page-{n+1:02}.png')
            for block in page.get_text('dict')['blocks']:
                for line in block.get('lines',[]):
                    for span in line['spans']:
                        x0,y0,x1,y1=span['bbox']
                        if x0 < 10 or y0 < 10 or x1 > page.rect.width-10 or y1 > page.rect.height-10:
                            bad.append({'page':n+1,'text':span['text'][:60],'bbox':span['bbox']})
        checks[filename+'_bounds']=not bad
        pdfs[filename]={'pages':len(doc),'out_of_bounds':bad}
    verification={'passed':all(checks.values()),'checks':checks,'pdfs':pdfs,'browser_errors':errors,
                  'video':'Evaluations/submission/VETO_BACKUP_DEMO.webm','note':'Real retained evaluation UI; no narration; no new plugin execution during recording.'}
    target=VERIFICATION;target.mkdir(parents=True,exist_ok=True)
    (target/'artifact-verification.json').write_text(json.dumps(verification,indent=2)+'\n',encoding='utf-8')
    print(json.dumps(verification,indent=2))
    return 0 if verification['passed'] else 1


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--run',default='final-v1')
    raise SystemExit(main(parser.parse_args().run))
