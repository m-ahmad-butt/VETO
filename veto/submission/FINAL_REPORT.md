# VETO: WP Spectra - Final Report

15 November 2026 | CS3002 Information Security | FAST NUCES Lahore

## Abstract and project scope

VETO: WP Spectra is a local rule-based workflow for static inspection, disposable WordPress execution and evidence reporting. It accepts plugin ZIPs, records explainable indicators and selected runtime effects, and retains PDF/JSON reports after sandbox cleanup.

The final controlled evaluation uses 16 new static packages and 9 runtime runs including a clean control. It measures all five metrics specified in proposal Section 5.2. Results describe a small synthetic study, not real-world malware-detection accuracy.

The contribution is an integrated investigation workflow with explicit coverage and uncertainty. A review recommendation is not proof of maliciousness. No AI/LLM classification, production monitoring, automatic remediation or product-superiority benchmark is implemented.

The original proposal is retained as 03.pdf. Development acceptance evidence for Phases 1-3 is separate from final evaluation evidence. The local application targets Windows/CPython 3.13 and Docker Desktop Linux containers.

## Architecture and trust boundaries

Data flow: untrusted ZIP -> private bounded intake -> hashes / PHP lexical rules / YARA -> static evidence -> optional disposable sandbox -> retained runtime evidence -> investigation policy -> immutable PDF/JSON pair.

The Python host application, rule files and Docker control plane are trusted. Submitted package names, contents and metadata are untrusted. ZIP entries are validated before extraction, including traversal, collisions, special files, size and expansion limits. Static analysis never invokes submitted PHP.

A loopback HTTP service provides buildless HTML/CSS/JavaScript views. SQLite stores jobs and progress; one dispatcher starts separate workers. Process leases support restart adoption and interrupted-run recovery. UUID storage paths, local host/origin checks and a per-server write token protect local write routes.

The analyzer stays outside plugin execution. Each full run receives uniquely labeled containers, tmpfs volumes and an isolated internal network. No host repository bind mount or Docker socket is passed to submitted code. Cleanup verifies exact resource ownership and absence.

The legacy web/ WordPress/CEGA lab is optional historical material. It is not the execution sandbox or a dependency of VETO.

## Static analysis and interpretation

The scanner inventories package/file SHA-256 hashes, discovers conventional plugin headers, and applies nine lexical PHP indicators, two YARA rules and package anomaly rules. Its default known-hash catalog is empty; hash matching demonstrates reference equality, not malware intelligence.

Every finding retains rule ID, severity, file location, matched evidence, rationale and limitations. The frozen flagging policy recommends review when a completed scan contains a medium/high finding. Low-severity capabilities alone do not flag a package.

Lexical masking avoids many matches in comments and strings, but YARA intentionally sees raw bytes. There is no AST, taint, reachability, callback resolution or deobfuscation engine. Fixed legitimate command execution can still trigger review.

Suspicious static evaluation functions are intentionally never called. Their ground-truth labels describe dangerous source constructs in the controlled examples, rather than a claim that these packages are deployed malware.

## Dynamic execution and evidence reporting

Execution stages install and activate the submitted copy, visit frontend and authenticated administrator pages, run due cron and observe for a finite idle window. Before/after snapshots and event collectors cover files, database, accounts/roles, scheduled tasks and selected network attempts.

File monitoring combines recursive inotify and hashes. Database evidence combines mutation-query audit and direct SQL state snapshots. Account and cron state are compared between snapshots. Network collectors include WordPress HTTP hooks, an internal HTTP sink and a DNS sink.

The submitted plugin executes as user 33:33 with dropped capabilities, no privilege escalation, a read-only root filesystem and resource limits. Containers share the Linux kernel; this is not a virtual-machine detonation system.

The investigation view links findings/events to source artifacts, before/after values and a chronological timeline. Clean-control comparison supplies background context without suppressing review indicators. Partial, failed and interrupted outcomes remain explicitly incomplete.

PDF and JSON are generated from a consistent frozen capture. JSON preserves full raw evidence; PDF contains readable details and an index of every retained record with explicit excerpts. Companion checksums detect changed download bytes; they are not digital signatures.

## Controlled evaluation methodology

The dataset contains 8 benign and 8 suspicious static cases, plus benign, file, database, account, cron, network, direct-IP and combined runtime fixtures. A separate clean-control run installs no plugin. All cases are new after development, with hashes, versions, labels, rationale and execution prerequisites in evaluation/manifest.json.

The same author knew the detector and selected challenging cases deliberately. These are separate evaluation cases but not a blind independent holdout. No random sampling, independent labeler or real-malware corpus is claimed. Each case has one execution; repeat-run variability is unmeasured.

Before execution, the runner freezes detector/runtime/evaluator/package hashes, effective settings, package versions and Docker image IDs. It checks the source inventory again after evaluation. No detection rule is changed to improve the measurements.

Dynamic fixtures assert operation outcomes. Before cleanup, a separate direct PHP reader loads no WordPress/observer code and reads receipts, file contents, account capabilities, options and cron state through independent file/SQL operations. Persistent changes require both fixture success and direct corroboration.

Transient creation and network attempts use fixture operation/return assertions, not independent packet capture. Instrumentation is trusted because these are controlled authored fixtures. No event is treated as ground truth solely because VETO reported it.

Settings: action timeout 15 seconds, whole-job timeout 300 seconds, idle observation 2 seconds, one trial per case. Environment details and actual stage results are saved. Every runtime run must complete and clean up for the experiment to be valid.

## Measured results

Static confusion matrix: TP=6, FP=2, TN=6, FN=2; incomplete=0. The static review policy is used for both precision/recall and benign false-positive rate.

Dynamic denominator: 23 independently confirmed behavior instances out of 23 planned instances. A behavior counts once regardless of duplicate collector matches. The complete per-instance matching table is in metrics.json.

Reporting denominator: 909 raw runtime events, including the clean control and fixture instrumentation. Completeness requires exact full JSON preservation plus the event's PDF index entry. PDF excerpts are not required to reproduce every raw field.

The table below reports raw counts and percentages. Zero denominators would be undefined (JSON null). Report completeness measures preservation of recorded events; it cannot establish that all executed behaviors were observed.

Measurement integrity and software correctness are separate. The full regression suite passes 100 tests (95 existing plus 5 measurement-contract tests). An additional 137 independent checks verify replay, hashes, report pairing and full record indexes. The initial restricted regression attempt encountered a fixture ZIP permission error; the successful local-permission rerun is saved in ../Evaluations/verification/regression.txt.

| Metric | Numerator | Denominator | Result |
|---|---:|---:|---:|
| Static precision | 6 | 8 | 75.00% |
| Static recall | 6 | 8 | 75.00% |
| Dynamic event detection | 22 | 23 | 95.65% |
| False-positive rate | 2 | 8 | 25.00% |
| Report completeness | 909 | 909 | 100.00% |

## Error analysis and limits

Static false positives: s-documentation, s-maintenance. The documentation case contains an inert eval/request example that raw YARA matches; the maintenance case uses a fixed legitimate shell command. Both require context rather than a malware verdict.

Static false negatives: s-indirect_shell, s-callback_shell. These construct function names or invoke a callback indirectly. The frozen lexical rules do not resolve these flows. No detector change was made after seeing the scores.

Dynamic misses: d-direct_ip/direct_ip_attempt. A direct-IP socket failure can lack DNS or WordPress HTTP telemetry; a blocked attempt is not equivalent to an observed attempt.

Recursive inotify has watch-creation races and does not retain deleted content. Account/cron diffs can miss changes reverted between snapshots. Mutation SQL is an attempted operation unless state corroborates success. WordPress hooks can be bypassed or tampered with.

A clean control describes this baseline only; pattern absence does not prove plugin causation. Unexercised routes, privilege-dependent paths, delayed behavior, alternative protocols and environment dependencies can remain unseen. One trial per case cannot establish timing reliability.

Potential future work includes process/syscall network observation, stronger isolation, richer PHP analysis, broader separately labeled datasets and repeated trials. These are future improvements, not delivered features.

## Reproducibility and administrator workflow

Install the pinned environment following Readme.md. Static mode requires Python dependencies; new full runs also require Docker Desktop and the dedicated sandbox image described in docs/PHASE2_GUIDE.md. The UI needs no Node build or cloud account.

Start python -m wp_spectra serve from veto/. Upload a controlled ZIP, select static/full analysis, inspect progress/history, open evidence and before/after records, compare a clean control if appropriate, and export a PDF/JSON pair. Saved exports remain accessible after cleanup and server restart.

The authoritative run is ../Evaluations/runs/final-v1/. Its freeze.json, source/, manifest.json, raw-hashes.json, per-case static/dynamic/ground-truth records, exports and metrics permit offline replay. Recalculate with python -m tools.evaluate_phase4 calculate ../Evaluations/runs/final-v1.

An automated fresh virtual environment installed the pinned dependencies offline and replayed the frozen evaluator against a copied raw run. The resulting metrics.json bytes exactly matched the original. This verifies same-machine reproduction, not an independent teammate sign-off. REPRODUCIBILITY.md gives the setup and a new named execution command. New runtime executions may differ in timestamps, IDs, background events and report bytes.

The backup browser recording demonstrates retained evidence, timeline, coverage and PDF/JSON downloads. It has no narration and does not substitute for a live execution or independent reproduction. DEMO_AND_VIVA.md supplies the demonstration script and defense questions.

## Requirement-to-evidence mapping

Static analysis: wp_spectra/intake.py, scanner.py, php.py and resources/static.yar; evaluation static raw reports and confusion matrix.

Isolated execution: wp_spectra/dynamic.py, sandbox/; per-run isolation settings, actual image IDs, successful lifecycle and independent cleanup.json listings.

Five behavioral categories: sandbox/runtime/observer.py and snapshot.php; single-category/combined ground-truth assertions and behavior matching records in metrics.json.

Evidence report: investigation.py, reports.py, report.schema.json; all per-case PDF/JSON pairs, source references and runtime-event completeness checks.

Administrator workflow: app_server.py, app_service.py, app_store.py, app_worker.py and web/; saved Phase 3 workflow/browser/fresh-install verification and Phase 4 screenshots/recording.

Evaluation: evaluation/PROTOCOL.md, manifest.json, frozen source, raw results, metrics.json and metrics.csv; false-positive and missed-detection analysis in this report.

Submission and defense: this report, presentation.html/PDF, reproduction guide, contribution record and viva notes. Member contribution confirmation, teammate reproduction and actual viva readiness are human attestations; preparation does not claim they have happened.

## Sources, authorship and conclusion

Primary project sources: 03.pdf (proposal, Section 5); the four-phase plan dated 15 November 2026; Readme.md; docs/RULES.md; docs/INTERFACES.md; docs/PHASE2_GUIDE.md; and the versioned application sources retained in the frozen run.

Measured evidence: ../Evaluations/runs/final-v1/freeze.json, cleanup.json, raw-hashes.json, run-index.json, metrics.json, metrics.csv and per-case ground-truth/static/dynamic/export records. Historical software verification is retained under evidence/phase1-equivalent root evidence, phase2 and phase3a/3b/3c.

Team identities from the repository: Muhammad Ahmad Butt (23L-3059), Ali Ahmed (23L-3067), Muhammad Zain Tahir (23L-3078). Course: CS3002 Information Security, FAST NUCES Lahore. Project advisor: Sarah Abid Khan. Actual contribution statements require member confirmation.

Phase 4 evaluation tooling and submission drafts were prepared with Codex assistance in the local workspace. The team should review the artifacts and describe assistance according to its course policy. No member contribution, independent review or oral-defense performance is fabricated.

VETO delivers the proposal workflow and the controlled evaluation now supplies the specified measurements. The measurements support a reproducible account of selected detections, errors and reporting preservation. They do not justify a universal safety verdict or broad malware-accuracy claim.
