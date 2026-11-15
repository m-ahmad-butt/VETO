"""From veto/: python -m tools.verify_phase1. No PHP, Docker, or network."""

import io
import json
from pathlib import Path
import platform
import sys
import unittest

from tools.build_fixtures import build
from wp_spectra.__main__ import validate_report
from wp_spectra.scanner import analyze, now

BASE = Path(__file__).resolve().parents[1]


def main():
    evidence = BASE / "evidence"
    evidence.mkdir(exist_ok=True)
    manifest = build()
    log = io.StringIO()
    suite = unittest.defaultTestLoader.discover(str(BASE / "tests"))
    result = unittest.TextTestRunner(stream=log, verbosity=2).run(suite)
    (evidence / "tests.txt").write_text(log.getvalue(), encoding="utf-8")
    print(log.getvalue())
    cases = []
    for case in manifest["cases"]:
        report = analyze(BASE / "fixtures/generated" / case["filename"])
        validate_report(report)
        actual_rules = sorted({finding["rule_id"] for finding in report["static_findings"]})
        passed = report["status"] == case["expected_status"] and actual_rules == case["expected_rules"]
        cases.append({**case, "actual_status": report["status"], "actual_rules": actual_rules, "passed": passed})
        (evidence / (Path(case["filename"]).stem + ".json")).write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    hash_report = analyze(BASE / "fixtures/generated/suspicious.zip", hash_catalog=BASE / "fixtures/generated/test_hash_catalog.json")
    validate_report(hash_report)
    (evidence / "hash-reference-demo.json").write_text(json.dumps(hash_report, indent=2) + "\n", encoding="utf-8")
    summary = {"generated_at": now(), "purpose": "Phase 1 development acceptance, not final accuracy evaluation",
               "python": sys.version, "platform": platform.platform(), "tests_run": result.testsRun,
               "test_failures": len(result.failures), "test_errors": len(result.errors),
               "cases": cases, "passed": result.wasSuccessful() and all(case["passed"] for case in cases)}
    (evidence / "verification.json").write_text(json.dumps(summary, indent=2) + "\n", encoding="utf-8")
    return 0 if summary["passed"] else 1


if __name__ == "__main__":
    sys.exit(main())
