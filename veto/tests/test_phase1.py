"""Security and behavioral acceptance tests; fixtures are never executed."""

from contextlib import redirect_stderr, redirect_stdout
from dataclasses import replace
import hashlib
import io
import json
from pathlib import Path
import stat
import struct
import tempfile
import unittest
from unittest.mock import patch
import zipfile

import yara

from tools.build_fixtures import build
from wp_spectra.__main__ import main, validate_report
from wp_spectra.intake import Limits
from wp_spectra.php import findings as php_findings
from wp_spectra.scanner import analyze


class PhaseOneTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="vt-")
        self.root = Path(self.temp.name)
        self.fixtures = self.root / "fixtures"
        self.manifest = build(self.fixtures)
        self.work = self.root / "work"
        self.work.mkdir()

    def tearDown(self):
        self.temp.cleanup()

    def scan(self, name, **kwargs):
        result = analyze(self.fixtures / name, work_dir=self.work, **kwargs)
        validate_report(result)
        self.assertEqual(list(self.work.iterdir()), [], "Private staging must be cleaned")
        self.assertEqual(result["runtime_events"], [])
        return result

    def archive(self, entries, name="custom.zip", compression=zipfile.ZIP_STORED):
        target = self.fixtures / name
        with zipfile.ZipFile(target, "w", compression=compression) as archive:
            for path, content in entries:
                if isinstance(path, str):
                    info = zipfile.ZipInfo(path)
                    # Retain hostile names that ZipInfo normalizes on Windows.
                    info.filename = path
                    info.orig_filename = path
                    info.compress_type = compression
                else:
                    info = path
                archive.writestr(info, content)
        return name

    def test_manifest_expectations(self):
        for case in self.manifest["cases"]:
            with self.subTest(case=case["filename"]):
                result = self.scan(case["filename"])
                self.assertEqual(result["status"], case["expected_status"])
                self.assertEqual(sorted({f["rule_id"] for f in result["static_findings"]}), case["expected_rules"])
                if result["status"] == "completed":
                    self.assertEqual(result["package"]["sha256"], case["sha256"])
                    for item in result["inventory"]:
                        self.assertEqual(item["sha256"], case["member_hashes"][item["path"]])

    def test_benign_metadata_and_no_findings(self):
        report = self.scan("benign.zip")
        self.assertEqual(report["summary"]["assessment"], "no_indicators_observed")
        self.assertEqual(report["plugin_metadata"][0]["name"], "VETO Benign Greeting")
        self.assertEqual(report["plugin_metadata"][0]["version"], "1.0.0")
        self.assertEqual(report["configuration"]["hash_reference_count"], 0)

    def test_low_capabilities_do_not_flag_suspicious(self):
        report = self.scan("capabilities.zip")
        self.assertEqual(report["summary"]["assessment"], "capabilities_observed")
        self.assertFalse(report["summary"]["flagged_for_review"])

    def test_yara_engine_and_location_evidence(self):
        report = self.scan("suspicious.zip")
        self.assertEqual(report["configuration"]["yara_version"], yara.__version__)
        data = (Path(__file__).resolve().parents[1] / "fixtures/sources/suspicious.php.txt").read_bytes().replace(b"\r\n", b"\n")
        for finding in report["static_findings"]:
            self.assertTrue(finding["rationale"])
            self.assertTrue(finding["limitation"])
            self.assertTrue(finding["evidence"]["text"])
            if finding["byte_offset"] is not None:
                self.assertEqual(finding["line"], data[:finding["byte_offset"]].count(b"\n") + 1)

    def test_hash_reference_provenance(self):
        report = self.scan("suspicious.zip", hash_catalog=self.fixtures / "test_hash_catalog.json")
        matched = [f for f in report["static_findings"] if f["rule_id"] == "HASH001"]
        self.assertEqual(len(matched), 1)
        self.assertIn("NOT verified malware", matched[0]["evidence"]["text"])
        self.assertTrue(matched[0]["reference"])

    def test_hash_does_not_match_changed_bytes(self):
        source = (Path(__file__).resolve().parents[1] / "fixtures/sources/suspicious.php.txt").read_bytes() + b"\n// changed"
        name = self.archive([("example/main.php", source)])
        report = self.scan(name, hash_catalog=self.fixtures / "test_hash_catalog.json")
        self.assertNotIn("HASH001", {f["rule_id"] for f in report["static_findings"]})

    def test_path_rejections(self):
        paths = ["../escape.php", "/absolute.php", "C:/escape.php", "x\\escape.php", "x/../escape.php",
                 "x//file.php", "x/./file.php", "x/file.php:stream", "x/NUL.php", "x/COM1.txt",
                 "x/trailing. ", "x/trailing.", "x/a?.php", "x/\x01.php", "x/" + "a" * 181,
                 "x/e\u0301.php", "x/" * 22 + "main.php"]
        for path in paths:
            with self.subTest(path=repr(path)):
                report = self.scan(self.archive([(path, b"content")]))
                self.assertEqual(report["status"], "rejected")
                self.assertEqual(report["errors"][0]["code"], "unsafe_path")
        self.assertFalse((self.root / "escape.php").exists())

    def test_embedded_nul_name(self):
        name = self.archive([("x/abc.php", b"content")])
        target = self.fixtures / name
        target.write_bytes(target.read_bytes().replace(b"x/abc.php", b"x/a\x00c.php"))
        self.assertEqual(self.scan(name)["status"], "rejected")

    def test_symlink_and_special_file_rejection(self):
        for mode in (stat.S_IFLNK, stat.S_IFIFO, stat.S_IFSOCK):
            with self.subTest(mode=mode):
                info = zipfile.ZipInfo("example/link")
                info.create_system = 3
                info.external_attr = (mode | 0o777) << 16
                report = self.scan(self.archive([(info, b"../../escape")]))
                self.assertEqual(report["errors"][0]["code"], "special_file")

    def test_encrypted_flag_rejection(self):
        name = self.archive([("example/main.php", b"content")])
        target = self.fixtures / name
        data = bytearray(target.read_bytes())
        central = data.index(b"PK\x01\x02")
        struct.pack_into("<H", data, 6, 1)
        struct.pack_into("<H", data, central + 8, 1)
        target.write_bytes(data)
        self.assertEqual(self.scan(name)["errors"][0]["code"], "encrypted_archive")

    def test_unsupported_compression(self):
        name = self.archive([("example/main.php", b"content")], compression=zipfile.ZIP_BZIP2)
        self.assertEqual(self.scan(name)["errors"][0]["code"], "compression_method")

    def test_directory_payload_rejection(self):
        name = self.archive([("example/", b"payload")])
        self.assertEqual(self.scan(name)["errors"][0]["code"], "directory_payload")

    def test_directory_entries_are_supported(self):
        main_file = b"<?php /*\nPlugin Name: Directory test\n*/"
        name = self.archive([("example/", b""), ("example/main.php", main_file)])
        self.assertEqual(self.scan(name)["status"], "completed")

    def test_empty_and_directory_only_archives(self):
        self.assertEqual(self.scan(self.archive([]))["errors"][0]["code"], "entry_count")
        self.assertEqual(self.scan(self.archive([("empty/", b"")]))["errors"][0]["code"], "empty_package")

    def test_compressed_and_expanded_limits(self):
        cases = [("max_archive_bytes", 20, "archive_size"), ("max_file_bytes", 20, "file_size"),
                 ("max_expanded_bytes", 20, "expanded_size")]
        for field, limit, code in cases:
            with self.subTest(field=field):
                result = self.scan("benign.zip", limits=replace(Limits(), **{field: limit}))
                self.assertEqual(result["errors"][0]["code"], code)

    def test_file_entry_and_ratio_limits(self):
        name = self.archive([("x/a", b"a"), ("x/b", b"b")])
        self.assertEqual(self.scan(name, limits=replace(Limits(), max_files=1))["errors"][0]["code"], "file_count")
        self.assertEqual(self.scan(name, limits=replace(Limits(), max_entries=1))["errors"][0]["code"], "entry_count")
        name = self.archive([("x/a", b"A" * 100000)], compression=zipfile.ZIP_DEFLATED)
        self.assertEqual(self.scan(name)["errors"][0]["code"], "compression_ratio")

    def test_crc_failure_and_non_zip(self):
        name = self.archive([("x/a", b"UNIQUE_PAYLOAD")])
        target = self.fixtures / name
        target.write_bytes(target.read_bytes().replace(b"UNIQUE_PAYLOAD", b"BROKEN_PAYLOAD"))
        self.assertEqual(self.scan(name)["status"], "rejected")
        target.write_bytes(b"not a zip")
        self.assertEqual(self.scan(name)["status"], "rejected")

    def test_missing_input(self):
        self.assertEqual(self.scan("missing.zip")["errors"][0]["code"], "input_file")

    def test_catalog_failure_is_incomplete(self):
        bad = self.fixtures / "bad.json"
        for content in ('{', '{}', '{"version":"1","entries":[{"sha256":"fake"}]}'):
            bad.write_text(content)
            report = self.scan("benign.zip", hash_catalog=bad)
            self.assertEqual(report["status"], "failed")
            self.assertEqual(report["summary"]["assessment"], "incomplete")

    def test_missing_yara_is_not_a_clean_scan(self):
        with patch.dict("sys.modules", {"yara": None}):
            report = self.scan("benign.zip")
        self.assertEqual(report["status"], "failed")
        self.assertEqual(report["summary"]["assessment"], "incomplete")

    def test_yara_timeout_and_warning_are_not_clean(self):
        for mode in ("timeout", "warning"):
            class FakeRules:
                def match(self, **kwargs):
                    if mode == "timeout":
                        raise yara.TimeoutError("test timeout")
                    kwargs["warnings_callback"]("too many matches")
                    return []
            with self.subTest(mode=mode), patch("yara.compile", return_value=FakeRules()):
                report = self.scan("benign.zip")
                self.assertEqual(report["status"], "failed")
                self.assertEqual(report["coverage"]["yara"], "failed")

    def test_finding_limit_is_incomplete(self):
        report = self.scan("suspicious.zip", limits=replace(Limits(), max_findings=1))
        self.assertEqual(report["status"], "failed")
        self.assertEqual(report["summary"]["assessment"], "incomplete")

    def test_repeatable_findings_and_inventory(self):
        first, second = self.scan("suspicious.zip"), self.scan("suspicious.zip")
        self.assertNotEqual(first["analysis_id"], second["analysis_id"])
        self.assertEqual(first["inventory"], second["inventory"])
        for report in (first, second):
            for finding in report["static_findings"]:
                finding.pop("observed_at")
        self.assertEqual(first["static_findings"], second["static_findings"])

    def test_fixture_build_is_deterministic(self):
        second = build(self.root / "other")
        self.assertEqual(self.manifest, second)

    def test_php_lexer_ignores_comments_strings_html_and_heredocs(self):
        data = b'''system('outside php'); <?php
// shell_exec('comment');
/* exec('comment'); */
# passthru('comment');
$a = "shell_exec('string')";
$b = 'system("string")';
$c = <<<TEXT
shell_exec('heredoc');
TEXT;
$d = <<<'LITERAL'
exec('nowdoc');
LITERAL;
?> exec('outside php');'''
        self.assertEqual(list(php_findings(data)), [])

    def test_php_lexer_handles_case_multiline_namespaces_and_backticks(self):
        data = b"<?php \\ShElL_ExEc\n('x'); $x = `echo example`;"
        self.assertEqual({f[0] for f in php_findings(data)}, {"PHP002", "PHP009"})

    def test_php_ignores_method_calls(self):
        data = b"<?php $obj->exec('x'); Thing::system('x'); $shell_exec('x'); $obj-> exec('x'); Thing:: system('x'); function wp_remote_get($x) {}"
        self.assertEqual(list(php_findings(data)), [])

    def test_binary_and_multiple_header_anomalies(self):
        benign = b"<?php /*\nPlugin Name: Example\n*/"
        name = self.archive([("x/one.php", benign), ("x/two.php", benign), ("x/tool.bin", b"\x7fELFdata")])
        self.assertEqual({f["rule_id"] for f in self.scan(name)["static_findings"]}, {"PKG002", "PKG006"})

    def test_schema_rejects_clean_status_on_failure(self):
        report = self.scan("benign.zip")
        report["status"] = "failed"
        with self.assertRaises(Exception):
            validate_report(report)

    def test_report_validation_rejects_inconsistent_summary(self):
        report = self.scan("benign.zip")
        report["summary"]["finding_count"] = 1
        with self.assertRaises(ValueError):
            validate_report(report)
        report = self.scan("suspicious.zip")
        report["summary"]["flagged_for_review"] = False
        with self.assertRaises(ValueError):
            validate_report(report)

    def test_runtime_contract_schema(self):
        from jsonschema import Draft202012Validator, FormatChecker
        from wp_spectra.scanner import RESOURCES
        schema = json.loads((RESOURCES / "runtime-event.schema.json").read_text())
        Draft202012Validator.check_schema(schema)
        # Synthetic contract test, never included as a runtime observation.
        event = {"event_schema_version": "1.0.0", "event_id": "synthetic-contract-test", "analysis_id": "00000000-0000-4000-8000-000000000001",
                 "observed_at": "2026-01-01T00:00:00Z", "category": "file", "action": "created", "resource": "/synthetic",
                 "before": None, "after": "test", "collector": "schema-test", "execution_stage": "activate",
                 "evidence": [{"id": "synthetic", "text": "contract test only"}], "attribution": "unattributed"}
        validator = Draft202012Validator(schema, format_checker=FormatChecker())
        validator.validate(event)
        event["category"] = "invented"
        with self.assertRaises(Exception):
            validator.validate(event)

    def test_cli_exit_codes_json_and_no_overwrite(self):
        output = self.root / "result.json"
        with redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
            self.assertEqual(main(["scan", str(self.fixtures / "benign.zip"), "--output", str(output)]), 0)
            first = output.read_bytes()
            self.assertEqual(main(["scan", str(self.fixtures / "suspicious.zip"), "--output", str(output)]), 3)
            self.assertEqual(output.read_bytes(), first)
            self.assertEqual(main(["validate", str(output)]), 0)
            self.assertEqual(main(["scan", str(self.fixtures / "traversal.zip")]), 2)
            self.assertEqual(main(["scan", str(self.fixtures / "benign.zip"), "--max-files", "0"]), 3)
            self.assertEqual(main(["scan", str(self.fixtures / "benign.zip"), "--hash-catalog", str(self.root / "missing.json")]), 3)
        stdout = io.StringIO()
        with redirect_stdout(stdout):
            self.assertEqual(main(["scan", str(self.fixtures / "benign.zip")]), 0)
        validate_report(json.loads(stdout.getvalue()))

    def test_package_code_is_never_executed(self):
        with patch("subprocess.run", side_effect=AssertionError("Unexpected subprocess")), patch("os.system", side_effect=AssertionError("Unexpected command")):
            self.assertEqual(self.scan("suspicious.zip")["status"], "completed")


if __name__ == "__main__":
    unittest.main()
