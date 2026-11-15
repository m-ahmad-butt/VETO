# Proposal objective to evidence map

| Objective | Implementation | Final evidence |
|---|---|---|
| Static inspection | wp_spectra/intake.py, scanner.py, php.py, resources/static.yar | evaluation static cases, metrics confusion matrix |
| Disposable execution | wp_spectra/dynamic.py, sandbox/ | every dynamic.json isolation/stages/cleanup, cleanup.json |
| Files | inotify and baseline diffs | d-file and d-combined ground truth and matched event IDs |
| Database | SQL audit and state snapshots | d-database/d-combined direct option reads and matching SQL |
| Accounts | SQL account/capability diffs | d-account/d-combined direct SQL and created-account records |
| Scheduled tasks | cron diffs and exercised callbacks | d-cron/d-combined future registration and callback option |
| Network attempts | HTTP hooks, HTTP/DNS sinks | d-network/d-combined and disclosed d-direct_ip miss |
| Consolidated report | investigation.py, reports.py | every report.json/report.pdf pair; exact preservation checks |
| Administrator workflow | app_server/service/store/worker and web/ | Phase 3 saved browser/workflow/fresh-install checks; new capture |
| All five metrics | tools/evaluate_phase4.py | frozen run metrics.json/CSV and per-record raw checks |
| Documentation/defense | submission/ | final report, slides, reproduction guide, demo/viva notes |

Human-only confirmations are tracked in COMPLETION.md, not represented as passed
software checks. Existing guides are retained in Readme.md and docs/.
