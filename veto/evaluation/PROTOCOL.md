# Phase 4 evaluation protocol

This protocol evaluates the delivered VETO application against Section 5 of
`../../03.pdf`. It does not establish general malware-detection accuracy.

## Dataset and separation

`samples/` contains 16 static cases (8 benign, 8 suspicious) and 8 runtime
cases (benign, file, database, account, cron, network, direct-IP, combined).
The runner adds a ninth runtime run: clean WordPress without the submitted plugin.
Each case is executed once. Existing `../fixtures/` and `../evidence/phase1` through
`phase3c` are development/acceptance material, excluded from these metric samples.

Cases were authored after Phase 3C and no detector changes are made for this
evaluation. The author knows the detector and deliberately includes examples of
documented limitations. This is a purposive, same-author, synthetic evaluation,
not a blind holdout or a representative random sample of WordPress plugins.
The benign labels follow complete inspection of these small authored sources;
they are not inferred from a clean scanner result. Suspicious static examples
are stored in uncalled functions and must not be run in dynamic mode. Runtime
fixtures use dummy accounts and disposable data, without arbitrary commands.

`manifest.json` records labels, rationale, package/source hashes, version,
expected behaviors and prerequisites. Version 1.0.0 applies to every fixture.
Freeze the manifest, all packages, detector/runtime sources, evaluator,
ground-truth reader, lockfile, configuration and exact Docker image IDs before
execution. Never tune the detector to improve these results. A changed evaluation
requires a new named run; retain earlier runs and disclose corrections.

## Execution and ground truth

Use the existing isolated sandbox. Activate the fixture, visit frontend and an
authenticated administrator page, run due cron, and observe for 2 seconds. The
effective limits and action outcomes are saved with every result. The standard
action timeout is 15 seconds and whole-job timeout is 300 seconds.

At activation, frontend and cron stages, controlled fixtures assert their operation
outcomes and save receipts. Immediately before cleanup, the harness executes
`tools/phase4_ground_truth.php` directly in the WordPress container. This script
does not bootstrap WordPress or read VETO's observer state/event log. It reads
receipt bytes, checks persistent file contents/transient absence, and queries
options, account capabilities and cron state directly through MySQL.

Persistent operations require receipt success AND direct corroboration. Transient
creation and network attempts rely on the authored fixture's operation/return
assertions, read by the independent harness, since persistent state cannot prove
them. The direct-IP case records `fsockopen` failure and errno to confirm a blocked
attempt. This is fixture ground truth allowed by the plan, not an independent
packet capture or security audit. DNS confirms invocation and failed resolution;
it does not prove any external DNS packet escaped containment. The controlled
HTTP receipt requires the expected response body and status. Instrumentation
itself creates extra file/SQL events; these remain in raw evidence and reporting
completeness, but are not additional planned behavior instances.

## Metrics and exact units

- Static precision = TP / (TP + FP).
- Static recall = TP / (TP + FN).
- False-positive rate = FP / (FP + TN), using the same static review policy and
  the 8 static benign cases. This is not a dynamic malware-classifier FPR.
- Dynamic detection = matched confirmed behavior instances / independently
  confirmed triggered behavior instances. Each planned instance counts at most
  once even when multiple collector events match. Matching requires the specific
  category, action/resource and where applicable collector/evidence content.
  A cron execution is credited through SQL evidence for its documented effect,
  not as exhaustive observation of cron callbacks.
- Report completeness = runtime events preserved in full JSON AND indexed in
  PDF / runtime events in the saved raw event store. Includes clean control and
  instrumentation noise. JSON raw records and normalized fields must agree;
  duplicate/missing/mutated records fail. PDF full raw evidence is not required:
  its documented design uses an index plus excerpts. Static findings/workflow
  are included by exports but are outside this runtime-event denominator.

`summary.flagged_for_review` is the frozen static classification: a completed
scan with a medium/high indicator is flagged. Low capabilities alone do not flag.
This measures review recommendations, not proof of maliciousness. Incomplete
static runs are reported separately and invalidate a final complete experiment.
Unconfirmed behaviors are listed and excluded from the triggered denominator;
the final experiment is marked invalid until all planned behaviors are confirmed.
A zero denominator is JSON null / undefined, never invented as 0% or 100%.

No target percentage is required for completion. Honest false positives and
misses are results. `valid_experiment` checks execution, independent confirmation,
unchanged sources and cleanup, not whether detection scores are impressive.

## Reproduction

From `VETO/veto/`, using the existing locked development environment:

```powershell
.\.venv\Scripts\python.exe -m tools.build_evaluation
.\.venv\Scripts\python.exe -m tools.evaluate_phase4 run --name my-reproduction
.\.venv\Scripts\python.exe -m tools.evaluate_phase4 calculate ../Evaluations/runs/my-reproduction
```

The run command needs access to Docker Desktop's Linux engine and the three
documented images already built/pulled according to `docs/PHASE2_GUIDE.md`.
Calculation alone needs no Docker or running application. It verifies saved
raw hashes, schemas, exact matches and export contents before writing metrics.
Use `run/source/` for the exact frozen detector/evaluator copy; it contains its
own manifest, packages and lockfile. See the final reproduction guide for the
fresh-source command sequence. Never rerun into an existing named run.

## Validity limits

Small samples, one trial per case, no random sampling, no independent labeler,
no real-malware corpus, finite action coverage, shared-kernel containment,
application hook tampering, indirect PHP resolution, inotify races and transient
account/cron changes remain limitations. Counts and sample sizes must accompany
every reported percentage. Do not interpret completeness as detection coverage.
No third-party product is benchmarked and no superiority claim is made.
