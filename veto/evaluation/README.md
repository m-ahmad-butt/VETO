# Phase 4 source and compact results

This directory is intended for Git:

- `manifest.json`: case definitions, labels and hashes.
- `PROTOCOL.md`: evaluation methodology.
- `results/summary.json`: measured counts, scores, limitations and errors.
- `results/metrics.csv`: the five metric totals.
- `results/freeze.json`: original frozen configuration, environment/image IDs and source hashes.
- `results/ground-truth-summary.json`: fixture assertions, confirmed behaviors and matching event references.
- `results/regression-summary.json`: successful 100-test regression result and raw-log hash.
- `results/verification.json`: all 137 result/report audit checks.
- `results/cleanup.json`: independent cleanup listing and all nine runtime outcomes.

All generated samples, full runs, frozen source copies, full verification logs,
PDF/HTML reports, screenshots, video and the submission archive live in the
repository-root `Evaluations/` directory, which is ignored by Git.

From `veto/`:

```powershell
.\.venv\Scripts\python.exe -m tools.build_evaluation
.\.venv\Scripts\python.exe -m tools.evaluate_phase4 run --name new-run
.\.venv\Scripts\python.exe -m tools.evaluate_phase4 calculate ../Evaluations/runs/final-v1
.\.venv\Scripts\python.exe -m tools.verify_phase4
```

A fresh clone contains the generator and summaries, not the raw local evidence.
The five compact evidence records above document the original run. Their hashes
and event references link to locally retained raw records; hashes alone do not
replace those records or constitute digital signatures.
Generate samples for a new experiment, or restore `Evaluations/` from the local
submission archive to replay the original run. Frozen historical run contents
retain their original relative layout and hashes; relocation did not rewrite them.
