"""Verify a fresh source copy in an isolated Python process with installed deps.

This is automated source-copy reproduction, not a second person's signoff or a
new OS/dependency installation. No network, PHP, or Docker is involved.
"""

import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

BASE = Path(__file__).resolve().parents[1]


def main():
    with tempfile.TemporaryDirectory(prefix="veto-repro-") as private:
        root = Path(private)
        for folder in ("wp_spectra", "tools", "tests", "fixtures"):
            shutil.copytree(BASE / folder, root / folder, ignore=shutil.ignore_patterns("__pycache__"))
        code = "import sys; sys.path.insert(0, sys.argv[1]); from tools.verify_phase1 import main; sys.exit(main())"
        result = subprocess.run([sys.executable, "-I", "-c", code, str(root)], cwd=root, capture_output=True, text=True, timeout=120)
        verification = json.loads((root / "evidence/verification.json").read_text()) if (root / "evidence/verification.json").exists() else None
        evidence = {"method": "Fresh copied source; isolated Python process; same installed dependencies and OS",
                    "exit_code": result.returncode, "verification": verification, "stdout": result.stdout, "stderr": result.stderr}
        (BASE / "evidence/reproduction.json").write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
        print(f"Isolated source-copy reproduction exit code: {result.returncode}")
        return result.returncode


if __name__ == "__main__":
    sys.exit(main())
