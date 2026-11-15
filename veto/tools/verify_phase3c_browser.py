"""Headless Edge QA of creating, downloading and reopening report snapshots."""
import hashlib
import json
from pathlib import Path
import socket
import subprocess
import time
import urllib.request

import pymupdf
from playwright.sync_api import sync_playwright,expect

BASE=Path(__file__).resolve().parents[1]


def main():
    evidence=BASE/'evidence/phase3c';verification=json.loads((evidence/'verification.json').read_text())
    root=Path(verification['test_data_directory']);subject=verification['jobs']['completed'];failed=verification['jobs']['failed'];static=verification['jobs']['static-only']
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    server=subprocess.Popen([str(BASE/'.venv/Scripts/python.exe'),'-m','wp_spectra','serve','--port',str(port),'--data-dir',str(root)],cwd=BASE,
        stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    base=f'http://127.0.0.1:{port}';checks={};errors=[]
    output=BASE/'output/pdf';output.mkdir(parents=True,exist_ok=True)
    try:
        for _ in range(100):
            try:urllib.request.urlopen(base,timeout=1).close();break
            except Exception:time.sleep(.1)
        else:raise RuntimeError('Browser export QA server did not start')
        with sync_playwright() as playwright:
            browser=playwright.chromium.launch(channel='msedge',headless=True)
            page=browser.new_page(viewport={'width':1440,'height':1100},accept_downloads=True,timezone_id='Asia/Karachi')
            page.on('pageerror',lambda error:errors.append(str(error)))
            page.goto(base+'/#'+subject)
            expect(page.locator('#selected-id')).to_have_text(subject)
            expect(page.locator('#report-list .saved-report').first).to_be_visible()
            initial_reports=page.locator('#report-list .saved-report').count()
            page.locator('#report-control').select_option(verification['control_job_id'])
            before=json.load(urllib.request.urlopen(base+'/api/jobs'))['counts']['total']
            page.locator('#create-report').click()
            expect(page.locator('#report-status')).to_contain_text('Report snapshot saved',timeout=30000)
            expect(page.locator('#report-list .saved-report')).to_have_count(initial_reports+1)
            checks['create_report_in_browser']=True
            with page.expect_download() as event:page.get_by_role('link',name='Download JSON',exact=True).first.click()
            download=event.value;download.save_as(output/'browser-report.json')
            report=json.loads((output/'browser-report.json').read_text())
            checks['json_download_uses_generated_filename']=download.suggested_filename.startswith('veto-') and download.suggested_filename.endswith('.json')
            checks['selected_control_is_exported']=report['comparison']['control_job_id']==verification['control_job_id']
            with page.expect_download() as event:page.get_by_role('link',name='Download PDF',exact=True).first.click()
            event.value.save_as(output/'browser-report.pdf')
            doc=pymupdf.open(output/'browser-report.pdf');text='\n'.join(p.get_text() for p in doc)
            checks['pdf_matches_downloaded_json']=report['export_id'] in text and hashlib.sha256((output/'browser-report.json').read_bytes()).hexdigest() in text
            checks['pdf_has_every_record']=all(record['record_id'] in text for record in report['records'])
            after=json.load(urllib.request.urlopen(base+'/api/jobs'))['counts']['total']
            checks['export_did_not_queue_another_analysis']=before==after
            page.locator('#reports').screenshot(path=str(evidence/'reports-desktop.png'))
            page.reload();expect(page.locator('#report-list .saved-report')).to_have_count(initial_reports+1)
            with page.expect_download() as event:page.get_by_role('link',name='Download JSON',exact=True).first.click()
            event.value.save_as(output/'browser-reopened.json')
            checks['saved_download_survives_refresh']=(output/'browser-report.json').read_bytes()==(output/'browser-reopened.json').read_bytes()
            page.goto(base+'/#'+failed);expect(page.locator('#selected-id')).to_have_text(failed)
            expect(page.locator('#report-list')).to_contain_text('incomplete')
            expect(page.locator('#report-control')).to_have_value('')
            checks['comparison_selection_not_carried_to_other_job']=True
            with page.expect_download() as event:page.get_by_role('link',name='Download PDF',exact=True).first.click()
            event.value.save_as(output/'browser-failed.pdf')
            checks['failed_report_is_labeled_incomplete']='INCOMPLETE ANALYSIS' in '\n'.join(p.get_text() for p in pymupdf.open(output/'browser-failed.pdf'))
            page.goto(base+'/#'+static);expect(page.locator('#report-control')).to_be_disabled()
            checks['static_only_has_no_control_selection']=True
            page.set_viewport_size({'width':390,'height':844})
            page.locator('#reports').screenshot(path=str(evidence/'reports-mobile.png'))
            checks['mobile_reports_do_not_overflow']=page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1')
            checks['no_javascript_errors']=not errors
            browser.close()
        result={'passed':all(checks.values()),'checks':checks,'javascript_errors':errors}
        (evidence/'browser-verification.json').write_text(json.dumps(result,indent=2)+'\n',encoding='utf-8')
        print(json.dumps(result,indent=2))
        return 0 if result['passed'] else 1
    finally:server.terminate();server.wait(timeout=10)


if __name__=='__main__':raise SystemExit(main())
