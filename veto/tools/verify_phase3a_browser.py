"""Optional browser QA with installed Playwright and Microsoft Edge.

Uses the application's venv for its server. No browser is downloaded or published.
"""
import json
from pathlib import Path
import socket
import subprocess
import time
import urllib.request
import uuid

from playwright.sync_api import sync_playwright,expect

BASE=Path(__file__).resolve().parents[1]


def main():
    evidence=BASE/'evidence/phase3a';evidence.mkdir(parents=True,exist_ok=True)
    with socket.socket() as probe:
        probe.bind(('127.0.0.1',0));port=probe.getsockname()[1]
    data=BASE/'.local'/('browser-'+uuid.uuid4().hex)
    command=[str(BASE/'.venv/Scripts/python.exe'),'-m','wp_spectra','serve','--port',str(port),'--data-dir',str(data)]
    flags=subprocess.CREATE_NO_WINDOW if hasattr(subprocess,'CREATE_NO_WINDOW') else 0
    server=subprocess.Popen(command,cwd=BASE,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=flags)
    base=f'http://127.0.0.1:{port}'
    checks={};errors=[];dialogs=[];jobs=[]
    try:
        for _ in range(100):
            try:urllib.request.urlopen(base,timeout=1).close();break
            except Exception:time.sleep(.1)
        else:raise RuntimeError('Browser QA server did not start')
        with sync_playwright() as playwright:
            browser=playwright.chromium.launch(channel='msedge',headless=True)
            context=browser.new_context(viewport={'width':1440,'height':1100},timezone_id='Asia/Karachi')
            page=context.new_page()
            page.on('pageerror',lambda error:errors.append(str(error)))
            def dialog_handler(dialog):dialogs.append(dialog.message);dialog.dismiss()
            page.on('dialog',dialog_handler)
            page.goto(base)
            expect(page.locator('#connection')).to_contain_text('online')
            expect(page.locator('#history-empty')).to_be_visible()
            page.screenshot(path=str(evidence/'desktop-empty.png'),full_page=True)
            checks['initial_empty_state']=True
            name='<img onerror=alert(1)>.zip'
            page.locator('input[name=mode][value=static]').check()
            page.locator('#plugin-file').set_input_files({'name':name,'mimeType':'application/zip','buffer':(BASE/'fixtures/generated/benign.zip').read_bytes()})
            page.locator('#submit-button').click()
            expect(page.locator('#selected-file')).to_contain_text(name,timeout=20000)
            expect(page.locator('#job-status')).to_have_text('COMPLETED',timeout=20000)
            jobs.append(page.locator('#selected-id').inner_text())
            checks['browser_static_upload_completed']=True
            checks['hostile_filename_is_text']=page.locator('img').count()==0 and not dialogs
            page.reload()
            expect(page.locator('#selected-id')).to_have_text(jobs[-1])
            expect(page.locator('#job-status')).to_have_text('COMPLETED')
            checks['reload_retains_selection_and_status']=True
            page.locator('#plugin-file').set_input_files(str(BASE/'fixtures/generated/traversal.zip'))
            page.locator('input[name=mode][value=static]').check()
            page.locator('#submit-button').click()
            expect(page.locator('#selected-file')).to_contain_text('traversal.zip',timeout=15000)
            expect(page.locator('#job-status')).to_have_text('REJECTED',timeout=15000)
            jobs.append(page.locator('#selected-id').inner_text())
            checks['browser_rejected_upload_is_clear']=True
            page.locator('input[name=mode][value=full]').check()
            page.locator('#plugin-file').set_input_files(str(BASE/'fixtures/dynamic/benign.zip'))
            page.locator('#submit-button').click()
            expect(page.locator('#selected-file')).to_contain_text('benign.zip',timeout=15000)
            expect(page.locator('#job-status')).to_have_text('RUNNING',timeout=15000)
            page.screenshot(path=str(evidence/'desktop-running.png'),full_page=True)
            expect(page.locator('#job-status')).to_have_text('COMPLETED',timeout=150000)
            jobs.append(page.locator('#selected-id').inner_text())
            checks['browser_full_upload_completed']=True
            expect(page.locator('#evidence-summary')).to_contain_text('cleanup verified')
            expect(page.locator('#history-body tr')).to_have_count(3)
            checks['history_has_all_three_outcomes']=True
            page.screenshot(path=str(evidence/'desktop-complete.png'),full_page=True)
            page.set_viewport_size({'width':390,'height':844})
            page.screenshot(path=str(evidence/'mobile-complete.png'),full_page=True)
            checks['mobile_has_no_page_overflow']=page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1')
            checks['no_javascript_errors']=not errors
            checks['no_script_dialogs']=not dialogs
            browser.close()
        summary={'checks':checks,'passed':all(checks.values()),'javascript_errors':errors,'dialogs':dialogs,'job_ids':jobs,'test_data_directory':str(data)}
        (evidence/'browser-verification.json').write_text(json.dumps(summary,indent=2)+'\n',encoding='utf-8')
        print(json.dumps(summary,indent=2))
        return 0 if summary['passed'] else 1
    finally:
        server.terminate();server.wait(timeout=10)


if __name__=='__main__':raise SystemExit(main())
