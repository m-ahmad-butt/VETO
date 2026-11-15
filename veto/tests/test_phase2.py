"""Offline tests. Real Docker acceptance is tools.verify_phase2."""
from dataclasses import replace
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from tools.build_dynamic_fixtures import build
from wp_spectra.dynamic import Docker, DockerError, DynamicConfig, observe, validate_dynamic, bounded_process
from wp_spectra.scanner import RESOURCES

BASE=Path(__file__).resolve().parents[1]


class PhaseTwoTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        build()

    def test_invalid_config(self):
        for value in (0,-1,901,True,'10'):
            with self.subTest(value=value),self.assertRaises(ValueError):
                DynamicConfig(action_timeout=value)

    def test_preflight_rejection_never_calls_docker(self):
        with patch('wp_spectra.dynamic.bounded_process',side_effect=AssertionError('Docker must not run')):
            result=observe(BASE/'fixtures/generated/traversal.zip')
        validate_dynamic(result)
        self.assertEqual(result['status'],'rejected')
        self.assertEqual(result['runtime_events'],[])
        self.assertTrue(result['cleanup']['verified'])

    def test_missing_docker_is_failed(self):
        with patch('wp_spectra.dynamic.bounded_process',side_effect=FileNotFoundError('docker unavailable')):
            result=observe(BASE/'fixtures/dynamic/benign.zip')
        validate_dynamic(result)
        self.assertEqual(result['status'],'failed')
        self.assertEqual(result['errors'][0]['stage'],'initialization')

    def test_docker_timeout_converts_and_redacts(self):
        import time
        docker=Docker(time.monotonic()+10,('secret-value',))
        with patch('wp_spectra.dynamic.bounded_process',side_effect=subprocess.TimeoutExpired('docker',1)):
            with self.assertRaises(TimeoutError):docker.run('version')
        process=subprocess.CompletedProcess([],1,b'',b'bad secret-value')
        with patch('wp_spectra.dynamic.bounded_process',return_value=process):
            with self.assertRaisesRegex(DockerError,r'\[REDACTED\]'):docker.run('version')

    def test_whole_job_deadline(self):
        docker=Docker(0)
        with patch('wp_spectra.dynamic.bounded_process') as run:
            with self.assertRaises(TimeoutError):docker.run('version')
            run.assert_not_called()

    def test_schema_is_valid(self):
        from jsonschema import Draft202012Validator
        Draft202012Validator.check_schema(json.loads((RESOURCES/'dynamic-result.schema.json').read_text()))

    def test_runtime_fixture_build_is_deterministic(self):
        first=build()
        second=build()
        self.assertEqual(first,second)

    def test_completed_cannot_have_missing_coverage(self):
        with patch('wp_spectra.dynamic.bounded_process',side_effect=FileNotFoundError('docker unavailable')):
            result=observe(BASE/'fixtures/dynamic/benign.zip')
        result['status']='completed'
        result['errors']=[]
        with self.assertRaises(ValueError):validate_dynamic(result)

    def test_bounded_process_output_cap(self):
        import sys
        with self.assertRaises(DockerError):
            bounded_process([sys.executable,'-c',"print('X'*100000)"],max_output=1024)

    def test_bounded_process_input_and_stderr(self):
        import sys
        process=bounded_process([sys.executable,'-c',"import sys; sys.stdout.buffer.write(sys.stdin.buffer.read()); sys.stderr.write('diagnostic')"],data=b'example')
        self.assertEqual(process.stdout,b'example')
        self.assertEqual(process.stderr,b'diagnostic')


if __name__=='__main__':unittest.main()
