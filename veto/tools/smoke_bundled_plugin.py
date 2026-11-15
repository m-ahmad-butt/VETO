"""Package the existing Akismet directory and inspect it without executing PHP.

This is an integration smoke test, not a verified-benign label or integrity check.
Run from veto/: python -m tools.smoke_bundled_plugin
"""

import json
from pathlib import Path
import sys
import zipfile

from wp_spectra.__main__ import validate_report
from wp_spectra.scanner import analyze

BASE = Path(__file__).resolve().parents[1]


def main():
    source = BASE.parent / "web/original/wp-content/plugins/akismet"
    output = BASE / "output"
    output.mkdir(exist_ok=True)
    package = output / "bundled-akismet.zip"
    with zipfile.ZipFile(package, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for path in sorted(source.rglob("*")):
            if path.is_file() and not path.is_symlink():
                archive.write(path, "akismet/" + path.relative_to(source).as_posix())
    report = analyze(package)
    validate_report(report)
    evidence = BASE / "evidence"
    evidence.mkdir(exist_ok=True)
    (evidence / "bundled-akismet.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(f"{report['status']}; {len(report['inventory'])} files; {len(report['static_findings'])} findings; {report['summary']['assessment']}")
    print("Integration smoke test only; package provenance/benignness not independently verified.")
    return 0 if report["status"] == "completed" else 1


if __name__ == "__main__":
    sys.exit(main())
