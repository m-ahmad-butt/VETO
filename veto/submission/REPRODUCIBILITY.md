# Reproducing VETO and the Phase 4 measurements

Authoritative run: `../../Evaluations/runs/final-v1/`. Do not overwrite it.

## Recalculate saved results without Docker

From `VETO/veto/`:

```powershell
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements-dev.txt
.\.venv\Scripts\python.exe -m tools.evaluate_phase4 calculate ../Evaluations/runs/final-v1
```

This verifies saved raw hashes and recomputes all metrics from individual records,
not from the existing percentage table. Check `valid_experiment: true`.
Expected timestamps/IDs are not regenerated; metric values should match exactly.

## Execute a fresh experiment from the frozen source

Copy `../Evaluations/runs/final-v1/source/` to a new short local directory. It contains
the exact detector, sandbox, evaluator, sample generator, manifest and packages.
Create a new venv there and install the frozen lockfile plus `pypdf==7.1.0`.
The full repository copy additionally includes tests/docs and requirements-dev.txt.

```powershell
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements-lock.txt
.\.venv\Scripts\python.exe -m pip install pypdf==7.1.0
docker build -t veto-sandbox:phase2 sandbox
docker pull mysql:8.0
docker pull wordpress:cli-php8.3
.\.venv\Scripts\python.exe -m tools.evaluate_phase4 run --name independent-reproduction
```

Compare actual image IDs to the original freeze.json. Rebuilt/upstream image tags
can differ; disclose that difference rather than claiming an identical environment.
For exact image reuse, retain the original local image IDs or save/load them with
Docker outside the submission archive. No Docker images are bundled in the ZIP.
Network access is only needed for installation/image preparation. The controlled
experiment runs locally. A new runtime experiment takes several minutes.

Expect all declared behaviors to be confirmed, all sandboxes cleaned, and sources
unchanged. Background event counts, timestamps, IDs, report bytes and timing can
vary. Compare semantic outcomes and individual misses, not byte equality of new runs.

## Application and full tests

The full repository's `Readme.md` and `docs/PHASE2_GUIDE.md` contain setup/user guides.
Use `python -m wp_spectra serve` from veto/ and open http://127.0.0.1:8765.
Use only the runtime `d-*.zip` samples for full mode; `s-*.zip` are static-only.
Run the full regression suite with `python -m unittest discover -s tests -v`.
Fixture generation needs write access to fixtures/. Tests do not require live Docker.

## Submission build and visual QA

`python -m tools.prepare_submission --run final-v1` regenerates report/HTML slides
from saved metrics. The optional capture/render tool runs with a development Python
containing Playwright 1.63.0 and PyMuPDF 1.28.2, with installed Microsoft Edge.
It records the actual local UI and prints the HTML presentation to PDF.
These are QA dependencies, not application dependencies. Backup recording has no audio.

## Human reproduction record

Teammate: not yet confirmed. Date/environment: not yet confirmed. Outcome: not yet
confirmed. Fill with an actual independent run; automated execution is not a teammate
sign-off. Attach the new run's freeze, metrics and cleanup files.
