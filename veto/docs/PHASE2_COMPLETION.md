# Phase 2 Completion and Assessment Evidence

## Delivered implementation

- Independent Docker lifecycle controller in `wp_spectra/dynamic.py`.
- A dedicated custom image and trusted scripts in `sandbox/`.
- Fresh minimal WordPress, MySQL, isolated internal network, and bounded tmpfs
  storage per analysis; no production configuration or host source mounts.
- Non-root submitted-plugin execution, dropped capabilities, resource limits,
  read-only root filesystem, read-only MU instrumentation, and no Docker socket.
- Baselines and direct-SQL snapshots independent of submitted WordPress code.
- External file events, SQL audit, account/role and cron diffs, blocked WordPress
  HTTP telemetry, DNS sink, and controlled HTTP receipt evidence.
- Installation, activation, frontend, authenticated admin, cron, and idle stages.
- Action/job/output limits; explicit failure reports and retained diagnostics.
- Ownership-checked cleanup and a recovery tool for forced host/process shutdown.
- A separate dynamic envelope that preserves the Phase 1 static schema and uses
  the existing runtime-event contract.
- Five reproducible controlled fixture types and six live acceptance scenarios.

## Evidence files

The delivered suite passes **43 offline tests** and **35 live acceptance checks**
across six scenarios. The behavior run records all five categories; the independent
cleanup listing confirms no resources remain for those analysis UUIDs.

| File under `evidence/phase2/` | What it demonstrates |
|---|---|
| `tests.txt` | Offline regression/security suite, including unchanged Phase 1 checks |
| `clean-control.json` | Normal WordPress activity without a submitted plugin |
| `behaviors.json` | All five categories, transient file evidence, direct SQL, admin account, cron, HTTP/DNS |
| `fresh-benign.json` | A later analysis starts without the prior fixture's file, user, or table |
| `activation-error.json` | Activation exception produces an explicit failed result with retained evidence |
| `timeout.json` | Hanging activation is bounded and cleaned up |
| `monitor-limit.json` | Oversized monitored file produces incomplete analysis, not a clean result |
| `job-timeout.json` | Additional whole-job watchdog test retains evidence after its deadline and cleans up |
| `verification.json` | Actual acceptance assertions, per-run IDs, and independent post-cleanup listings |

The machine-readable verification record is authoritative for the executed test
count and pass/fail results. No independent teammate approval is required: the
project can be reproduced by its solo developer using the documented commands.
The suite uses harmless controlled fixtures, not arbitrary real malware.

## Demonstration for assessment

1. Explain the trust boundaries and show the isolated network/resource settings.
2. Run `observe` with `fixtures/dynamic/behaviors.zip`.
3. Show an inotify create/delete pair for `veto-transient.txt`, even though it is
   absent from the final file snapshot.
4. Show the database table mutation and its direct-SQL audit evidence.
5. Show the added dummy administrator and its role in independent SQL snapshots.
6. Show cron registration and the saved cron-execution option/file evidence.
7. Show blocked HTTP/DNS attempts and a controlled endpoint receipt.
8. Compare the clean-control report so normal WordPress events are not mislabeled.
9. Show failed/timeout reports and successful cleanup verification.

## Material limits

This is selected behavioral monitoring, not exhaustive process/syscall tracing or
proof of maliciousness. WordPress hooks are application-level and can be tampered
with; direct-IP failed connections can lack network evidence. Recursive inotify
has watch races; account/cron diffs can miss states reverted between snapshots.
Containers share the Linux kernel. The full guide records these limitations and
how to interpret coverage and attribution.

The Phase 3 dashboard, consolidated risk presentation, and PDF reports remain
future work. Final precision/recall and other proposal metrics belong to Phase 4.
No Git tags, checkpoint labels, remote deployment, or push are created by this
phase's verification commands.
