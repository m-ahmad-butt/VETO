"""Browser acceptance for evidence inspection; uses real prior QA runs.

Optional development dependency: installed Playwright and Microsoft Edge.
The server and static XSS fixture use an isolated ignored QA workspace.
"""
import io
import json
from pathlib import Path
import re
import socket
import subprocess
import time
import urllib.request
import zipfile

from playwright.sync_api import sync_playwright,expect

BASE=Path(__file__).resolve().parents[1]


def main():
    evidence=BASE/'evidence/phase3b'
    verification=json.loads((evidence/'verification.json').read_text())
    root=Path(verification['test_data_directory'])
    subject=verification['subject_job_id'];control=verification['control_job_id'];failed=verification['failed_job_id']
    with socket.socket() as probe:probe.bind(('127.0.0.1',0));port=probe.getsockname()[1]
    server=subprocess.Popen([str(BASE/'.venv/Scripts/python.exe'),'-m','wp_spectra','serve','--port',str(port),'--data-dir',str(root)],
            cwd=BASE,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    base=f'http://127.0.0.1:{port}';checks={};errors=[];dialogs=[]
    try:
        for _ in range(100):
            try:urllib.request.urlopen(base,timeout=1).close();break
            except Exception:time.sleep(.1)
        else:raise RuntimeError('Browser QA server did not start')
        with sync_playwright() as playwright:
            browser=playwright.chromium.launch(channel='msedge',headless=True)
            page=browser.new_page(viewport={'width':1440,'height':1100},timezone_id='Asia/Karachi')
            page.on('pageerror',lambda error:errors.append(str(error)))
            def on_dialog(dialog):dialogs.append(dialog.message);dialog.dismiss()
            page.on('dialog',on_dialog)
            page.goto(base+'/#'+subject)
            expect(page.locator('#selected-id')).to_have_text(subject)
            expect(page.locator('#investigation-panel')).to_contain_text('Review recommended',timeout=20000)
            expect(page.locator('#investigation-panel')).to_contain_text('Administrator privilege change observed')
            checks['assessment_explains_admin_review']=True
            page.locator('#investigation').screenshot(path=str(evidence/'assessment-desktop.png'))
            page.get_by_role('button',name='4 static findings saved',exact=True).click()
            expect(page.locator('#tab-static')).to_have_attribute('aria-selected','true')
            expect(page.locator('.record-table tbody tr')).to_have_count(4)
            page.locator('.record-table .record-link').first.click()
            expect(page.locator('#record-dialog')).to_be_visible()
            expect(page.locator('#record-content')).to_contain_text('behaviors/main.php')
            expect(page.locator('#record-content')).to_contain_text('Line / byte offset')
            page.locator('#record-content summary').click()
            expect(page.locator('#record-content')).to_contain_text('/static_findings/0')
            expect(page.locator('#record-content')).to_contain_text('E00001')
            checks['static_location_evidence_and_provenance_visible']=True
            page.locator('#record-dialog').screenshot(path=str(evidence/'static-record.png'))
            page.locator('#close-record').click()
            page.locator('#tab-runtime').click()
            page.locator('#evidence-search').fill('veto_fixture_admin')
            page.locator('#evidence-category').select_option('account')
            page.locator('#evidence-severity').select_option('high')
            page.locator('#evidence-stage').select_option('activate')
            page.get_by_role('button',name='Apply filters',exact=True).click()
            expect(page.locator('.record-table tbody tr')).to_have_count(1)
            page.locator('.record-table .record-link').first.click()
            expect(page.locator('#record-content')).to_contain_text('Absent from the earlier snapshot')
            expect(page.locator('#record-content')).to_contain_text('veto_fixture_admin')
            expect(page.locator('#record-content')).to_contain_text('administrator')
            checks['runtime_filters_and_before_after_work']=True
            page.locator('#record-dialog').screenshot(path=str(evidence/'account-record.png'))
            page.locator('#close-record').click()
            page.locator('#tab-timeline').click()
            expect(page.locator('.record-table tbody tr')).to_have_count(25)
            first=page.locator('.record-table tbody tr').first.inner_text()
            page.locator('#evidence-results').get_by_role('button',name='Next →',exact=True).click()
            expect(page.locator('.record-pager')).to_contain_text('26–50')
            checks['timeline_paginates_without_hiding_records']=page.locator('.record-table tbody tr').first.inner_text()!=first
            page.locator('#evidence-source').select_option('workflow')
            page.get_by_role('button',name='Apply filters',exact=True).click()
            expect(page.locator('.record-table tbody tr').first).to_contain_text('Workflow')
            checks['timeline_source_filter_works']=True
            page.locator('#tab-comparison').click()
            expect(page.locator('#control-select option[value="'+control+'"]').first).to_be_attached(timeout=15000)
            page.locator('#control-select').select_option(control)
            expect(page.locator('#comparison-results')).to_contain_text('Patterns also observed in control:',timeout=20000)
            expect(page.locator('.comparison-table tbody tr')).not_to_have_count(0)
            checks['clean_control_patterns_and_references_visible']=True
            page.locator('#investigation').screenshot(path=str(evidence/'control-comparison.png'))
            page.locator('#tab-coverage').click();page.locator('#tab-comparison').click()
            expect(page.locator('#comparison-results')).to_contain_text('Patterns also observed in control:',timeout=20000)
            checks['comparison_survives_tab_switch']=True
            page.locator('#tab-coverage').click()
            expect(page.locator('.coverage-card')).to_have_count(6)
            expect(page.locator('#investigation-panel')).to_contain_text('Direct-IP')
            checks['coverage_and_limitations_visible']=True
            page.locator('#tab-static').focus();page.keyboard.press('ArrowRight')
            expect(page.locator('#tab-runtime')).to_have_attribute('aria-selected','true')
            checks['tabs_support_keyboard_navigation']=True
            page.goto(base+'/#'+failed)
            expect(page.locator('#investigation-panel')).to_contain_text('Incomplete analysis',timeout=20000)
            expect(page.locator('#investigation-alert')).to_be_visible()
            page.locator('#tab-coverage').click()
            expect(page.locator('#investigation-panel')).to_contain_text('Recorded failures')
            checks['failed_analysis_is_incomplete_with_errors']=True
            page.goto(base+'/#'+subject)
            expect(page.locator('#investigation-panel')).to_contain_text('Review recommended')
            page.set_viewport_size({'width':390,'height':844})
            page.locator('#investigation').screenshot(path=str(evidence/'assessment-mobile.png'))
            page.locator('#tab-runtime').click()
            expect(page.locator('.record-table tbody tr')).to_have_count(25)
            page.locator('.record-table .record-link').first.click()
            expect(page.locator('#record-dialog')).to_be_visible()
            page.locator('#record-dialog').screenshot(path=str(evidence/'record-mobile.png'))
            checks['mobile_dialog_fits_viewport']=page.locator('#record-dialog').bounding_box()['width']<=390
            page.locator('#close-record').click()
            checks['mobile_page_has_no_overflow']=page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1')
            page.set_viewport_size({'width':1440,'height':1100})
            # Static-only controlled HTML payload. It is displayed, never executed.
            payload=b'<?php /*\nPlugin Name: <img src=x onerror=alert(1)>\nVersion: 1.0\n*/\nreturn;\neval($_POST["<img src=x onerror=alert(1)>"]);'
            archive=io.BytesIO()
            with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as zipfile_out:zipfile_out.writestr('hostile/main.php',payload)
            page.locator('input[name=mode][value=static]').check()
            page.locator('#plugin-file').set_input_files({'name':'hostile-display.zip','mimeType':'application/zip','buffer':archive.getvalue()})
            page.locator('#submit-button').click()
            expect(page.locator('#selected-file')).to_contain_text('hostile-display.zip',timeout=15000)
            expect(page.locator('#job-status')).to_have_text('COMPLETED',timeout=15000)
            expect(page.locator('#selected-name')).to_contain_text('<img src=x onerror=alert(1)>')
            page.locator('#tab-static').click()
            expect(page.locator('.record-table tbody tr').first).to_be_visible()
            page.locator('.record-table .record-link').first.click()
            expect(page.locator('#record-content')).to_contain_text('<img src=x onerror=alert(1)>')
            checks['hostile_metadata_and_snippets_are_only_text']=page.locator('img').count()==0 and not dialogs
            checks['no_javascript_errors']=not errors
            browser.close()
        result={'passed':all(checks.values()),'checks':checks,'javascript_errors':errors,'dialogs':dialogs,'source_verification':'verification.json'}
        (evidence/'browser-verification.json').write_text(json.dumps(result,indent=2)+'\n',encoding='utf-8')
        print(json.dumps(result,indent=2))
        return 0 if result['passed'] else 1
    finally:server.terminate();server.wait(timeout=10)


if __name__=='__main__':raise SystemExit(main())
