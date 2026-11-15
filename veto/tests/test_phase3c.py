"""Immutable exports, completeness, safe PDF generation and download contracts."""
from copy import deepcopy
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import urllib.error
import urllib.request
import uuid

from pypdf import PdfReader
from test_phase3a import http_app
from test_phase3b import evidence_full,job_for
from wp_spectra.app_service import AppService
from wp_spectra.app_store import atomic_json
from wp_spectra.reports import bundle,encoded,digest,pdf_bytes,validate_bundle


class Phase3CTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='veto-export-')
        self.service=AppService(self.temp.name,dispatch=False)
        self.evidence=evidence_full();self.job=job_for(self.evidence)
        self.job_id=self.seed(self.evidence,'full','completed',self.job['id'])
    def tearDown(self):self.service.close();self.temp.cleanup()
    def seed(self,evidence,mode,status,job_id=None):
        job=job_for(evidence,mode,status);job_id=job_id or job['id']
        directory=self.service.store.directory(job_id);directory.mkdir()
        self.service.store.create(job_id,'sample.zip',mode,job['sha256'],1)
        self.service.store.update(job_id,status,'Saved test evidence',status=status,analysis_id=job['analysis_id'],cleanup_verified=1)
        for key,name in (('static','static.json'),('dynamic','dynamic.json'),('partial_static','static.partial.json'),('partial_dynamic','dynamic.partial.json')):
            if evidence.get(key):atomic_json(directory/name,evidence[key])
        return job_id
    def test_bundle_matches_dashboard_and_retains_raw_evidence(self):
        report=bundle(self.service,self.job_id)
        self.assertEqual(report['overview'],self.service.inspection(self.job_id)['overview'])
        validate_bundle(report)
        self.assertEqual(report['artifacts']['dynamic.json'],self.evidence['dynamic'])
        self.assertEqual(report['overview']['counts']['runtime'],len(self.evidence['dynamic']['runtime_events']))
    def test_pdf_contains_every_record_and_companion_hash(self):
        report=bundle(self.service,self.job_id)
        data=pdf_bytes(report,digest(encoded(report)))
        reader=PdfReader(io.BytesIO(data));text='\n'.join(page.extract_text() for page in reader.pages)
        self.assertIn(digest(encoded(report)),text)
        self.assertIn(report['export_id'],text)
        for record in report['records']:self.assertIn(record['record_id'],text)
        self.assertIn('Review recommended',text)
        self.assertIn('full value in JSON',text)
    def test_export_never_calls_docker_or_mutates_job(self):
        before=self.service.store.get(self.job_id)
        with patch('wp_spectra.dynamic.bounded_process',side_effect=AssertionError('Export must not run Docker')):
            manifest=self.service.reports.create(self.job_id)
        self.assertEqual(self.service.store.get(self.job_id),before)
        self.assertEqual(len(self.service.reports.list(self.job_id)),1)
        self.assertEqual(manifest['counts']['runtime'],133)
    def test_download_checksum_and_path_guards(self):
        manifest=self.service.reports.create(self.job_id)
        for filename in ('../static.json','report.html','manifest.json'):
            with self.assertRaises(ValueError):self.service.reports.download(self.job_id,manifest['export_id'],filename)
        path=self.service.reports.root(self.job_id)/manifest['export_id']/'report.pdf'
        path.write_bytes(b'tampered')
        with self.assertRaisesRegex(ValueError,'checksum'):self.service.reports.download(self.job_id,manifest['export_id'],'report.pdf')
    def test_saved_pair_is_immutable_after_source_changes_and_restart(self):
        manifest=self.service.reports.create(self.job_id)
        original=self.service.reports.download(self.job_id,manifest['export_id'],'report.json')[0]
        value=deepcopy(self.evidence['static']);value['plugin_metadata'][0]['name']='Changed after export'
        atomic_json(self.service.store.directory(self.job_id)/'static.json',value)
        self.service.close();self.service=AppService(self.temp.name,dispatch=False)
        self.assertEqual(self.service.reports.download(self.job_id,manifest['export_id'],'report.json')[0],original)
        self.assertEqual(self.service.reports.list(self.job_id)[0]['export_id'],manifest['export_id'])
    def test_failed_and_partial_outcomes_cannot_export_clean(self):
        for partial in (False,True):
            evidence=deepcopy(self.evidence)
            if partial:
                evidence['partial_dynamic']={'partial':True,'saved_at':'2026-01-01T00:00:00Z','report':evidence['dynamic']};evidence['dynamic']=None
            else:
                evidence['dynamic']['status']='failed';evidence['dynamic']['errors']=[{'stage':'activate','code':'TestError','message':'Controlled failed run'}]
            job_id=self.seed(evidence,'full','interrupted' if partial else 'failed')
            report=bundle(self.service,job_id)
            self.assertEqual(report['overview']['assessment']['code'],'incomplete')
            text='\n'.join(page.extract_text() for page in PdfReader(io.BytesIO(pdf_bytes(report,digest(encoded(report))))).pages)
            self.assertIn('INCOMPLETE ANALYSIS',text)
    def test_static_only_pdf_scope_is_explicit(self):
        evidence={**self.evidence,'dynamic':None}
        job_id=self.seed(evidence,'static','completed')
        report=bundle(self.service,job_id)
        self.assertEqual(report['overview']['counts']['runtime'],0)
        self.assertIn('not executed',report['overview']['assessment']['scope'])
        text='\n'.join(page.extract_text() for page in PdfReader(io.BytesIO(pdf_bytes(report,digest(encoded(report))))).pages)
        self.assertIn('plugin was not executed',text)
    def test_active_jobs_are_not_exported(self):
        self.service.store.update(self.job_id,'activate','Running',status='running')
        with self.assertRaisesRegex(ValueError,'finish'):self.service.reports.create(self.job_id)
        self.assertEqual(self.service.reports.list(self.job_id),[])
    def test_invalid_export_counts_and_evidence_references_rejected(self):
        report=bundle(self.service,self.job_id);report['overview']['counts']['runtime']+=1
        with self.assertRaises(ValueError):validate_bundle(report)
        report=bundle(self.service,self.job_id);record=next(r for r in report['records'] if r['source']=='runtime');record['evidence'][0]['text']='Modified evidence'
        with self.assertRaises(ValueError):validate_bundle(report)
    def test_markup_and_unicode_are_printed_not_interpreted(self):
        report=bundle(self.service,self.job_id)
        report['job']['filename']='<img src="https://example.invalid/payload">\u0645\u0644\u0641.zip'
        data=pdf_bytes(report,digest(encoded(report)));reader=PdfReader(io.BytesIO(data));text='\n'.join(page.extract_text() for page in reader.pages)
        self.assertIn('<img src=',text);self.assertIn('\\u0645',text)
        self.assertFalse(any(page.get('/Annots') for page in reader.pages))
    def test_failed_pdf_generation_does_not_publish_half_pair(self):
        with patch('wp_spectra.reports.pdf_bytes',side_effect=RuntimeError('Controlled render failure')):
            with self.assertRaises(RuntimeError):self.service.reports.create(self.job_id)
        self.assertEqual(self.service.reports.list(self.job_id),[])
    def test_manifest_write_failure_removes_unpublished_pair(self):
        with patch('wp_spectra.reports.atomic_json',side_effect=OSError('Controlled manifest write failure')):
            with self.assertRaises(OSError):self.service.reports.create(self.job_id)
        self.assertEqual(self.service.reports.list(self.job_id),[])
        self.assertEqual(list(self.service.reports.root(self.job_id).iterdir()),[])
        self.assertFalse(self.service.reports.lock.locked())
    def test_http_attachment_and_write_boundary(self):
        with http_app(self.service) as (server,base):
            url=base+'/api/jobs/'+self.job_id+'/exports'
            with self.assertRaises(urllib.error.HTTPError):urllib.request.urlopen(urllib.request.Request(url,data=b'{}'))
            created=json.load(urllib.request.urlopen(urllib.request.Request(url,data=b'{}',headers={'X-VETO-Token':server.csrf,'Content-Type':'application/json'})))['export']
            response=urllib.request.urlopen(url+'/'+created['export_id']+'/report.pdf')
            self.assertEqual(response.headers.get_content_type(),'application/pdf')
            self.assertIn('attachment;',response.headers['Content-Disposition'])
            self.assertTrue(response.read().startswith(b'%PDF-'))
            exported=json.load(urllib.request.urlopen(url+'/'+created['export_id']+'/report.json'))
            self.assertEqual(exported['export_id'],created['export_id'])


if __name__=='__main__':unittest.main()
