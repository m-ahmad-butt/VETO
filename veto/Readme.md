# VETO: WP Spectra — Local Analysis Application

## Phase 4: controlled evaluation and submission materials

The frozen experiment is [`evaluation/results/summary.json`](evaluation/results/summary.json).
Its measured results are static precision 6/8 (75%), static recall 6/8 (75%),
static false-positive rate 2/8 (25%), dynamic detection 22/23 (95.65%), and report
completeness 909/909 (100%). There are 16 new static cases and nine runtime runs
including clean control. All 23 planned behaviors were independently confirmed;
all runtime sandboxes were cleaned up. The full regression suite passes 100 tests.

These are small synthetic same-author samples, not general malware accuracy.
Read the [protocol](evaluation/PROTOCOL.md), [final package](submission/README.md),
and [remaining human confirmations](submission/COMPLETION.md).
The older phase-specific sections below preserve their historical delivery records.

```powershell
.\.venv\Scripts\python.exe -m tools.evaluate_phase4 calculate ../Evaluations/runs/final-v1
.\.venv\Scripts\python.exe -m tools.verify_phase4
```

Recalculation verifies raw hashes and uses saved records; it needs no Docker.
For a fresh execution use a new run name, as described in
[`submission/REPRODUCIBILITY.md`](submission/REPRODUCIBILITY.md).

## Phase 3C: PDF/JSON reports and completed application delivery

Restart an already running web server (Ctrl+C, then the command below) and refresh
the browser. Existing analyses remain available; exporting does not rerun a plugin
and does not require Docker when evidence is already saved.

```powershell
cd "D:\SEMESTER-7\Information Security\Project\VETO\veto"
.\.venv\Scripts\python.exe -m wp_spectra serve
```

Open **http://127.0.0.1:8765**, select an analysis, and find **Saved investigation
reports**. Optionally select a completed clean control, click **Create PDF + JSON
report**, then use either download link. All analysis records are exported;
investigation search filters do not remove records from the report.

### What the two formats contain

- **PDF:** A readable investigation report with identity/hashes, scope, review
  assessment and reasons, actual coverage/errors, execution configuration and
  image IDs, static findings and supporting snippets, observed resources,
  optional control comparison, policy/limitations, and a complete chronological
  index of every retained static/runtime/workflow record.
- **JSON:** Versioned schema `1.0.0`, the same frozen assessment/counts, every
  normalized record, exact retained raw analysis artifacts and workflow rows,
  source references, artifact identities, and any selected control evidence.
- **Matching pair:** Both derive from one consistent capture. The PDF includes
  the companion JSON SHA-256 and the same export UUID. A separate saved manifest
  records both download hashes/sizes. Downloads are refused if their bytes no
  longer match the manifest. These are integrity checks, not digital signatures.

Long fields in the PDF index are explicitly marked as excerpts. The JSON contains
their full values and the raw before/after evidence. Review-reason state changes
and static matching snippets appear in the readable report. Unsupported Unicode
characters use visible escaped codepoints in the PDF; JSON preserves exact values.
No untrusted markup becomes PDF HTML, remote images, or executable links.

Incomplete, failed, rejected and interrupted runs can export retained evidence,
with an explicit incomplete assessment. Static-only reports explicitly say that
the plugin was not executed. Queued/running jobs wait until their outcome is saved
before export. A renderer/write error publishes no half-complete pair and does
not modify the job, its evidence, or previous exports.

### Persistence and reproducibility

Pairs are saved under `.local/app/jobs/<job UUID>/exports/<export UUID>/`, with
`report.pdf`, `report.json` and `manifest.json`. Publishing the directory is atomic;
existing pairs are immutable. They survive browser refresh, server restart and
sandbox destruction. Policy/evidence changes affect only newly created reports.
The schema is `wp_spectra/resources/report.schema.json`; semantic validation also
checks counts, source references and canonical artifact content hashes.

The artifact manifest distinguishes hashes of original saved file bytes from
canonical JSON content hashes. Canonical JSON uses sorted keys, ASCII escaping,
two-space indentation and a trailing newline. Original byte hashes can differ
after whitespace normalization even when parsed content is identical.

Generation is serialized locally. Bounds are 64 MiB for report JSON, 32 MiB for
PDF, 10,000 indexed records per PDF and 30 saved pairs per job. A limit error is
explicit; records are never silently dropped. Archive a closed application
workspace to retain older data. Windows/CPython 3.13 is the verified platform.

### Phase 3C API

| Endpoint | Purpose |
|---|---|
| `POST /api/jobs/<UUID>/exports` | Create an immutable pair; JSON body optionally contains `control_job_id`; current write token required |
| `GET /api/jobs/<UUID>/exports` | Saved pair manifests, newest first |
| `GET /api/jobs/<UUID>/exports/<export UUID>/report.pdf` | Attachment download with checksum verification |
| `GET /api/jobs/<UUID>/exports/<export UUID>/report.json` | Matching structured attachment |

Original local-origin, UUID/path and CSRF boundaries remain in effect. Download
filenames are generated from UUIDs, not submitted filenames. Export creation
does not start Docker, enqueue another job, or change analysis history.

### Setup and final verification

ReportLab is now a pinned runtime dependency and is installed in this checkout.
For a fresh installation:

```powershell
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements-lock.txt
.\.venv\Scripts\python.exe -m wp_spectra serve
```

Docker images/custom sandbox setup are needed only for new full analyses, as
documented in Phase 2. The browser UI has no Node build or hosted dependency.
For development tests, install the additional PDF reader:

```powershell
.\.venv\Scripts\python.exe -m pip install -r requirements-dev.txt
.\.venv\Scripts\python.exe -m tools.verify_phase3c
```

The live verifier performs controlled upload/analysis/inspection/export workflows
for completed, failed, static-only and timed-out outcomes, plus a clean control.
It checks all record IDs, PDF/JSON identity, dashboard agreement, download headers,
cleanup and unchanged downloads after restart. Representative local PDFs/JSONs
are generated under `output/pdf/` (ignored by Git).

Browser QA with installed Playwright/Edge:
`python tools/verify_phase3c_browser.py`. PDF rendering/layout QA with installed
PyMuPDF: `python tools/review_phase3c_pdfs.py`. Both are development tools, not
runtime requirements. Every representative PDF page is rendered for visual review.

An isolated installation can be verified without network access after preparing
the platform-specific wheelhouse:

```powershell
.\.venv\Scripts\python.exe -m pip download --only-binary=:all: -r requirements-lock.txt --dest output/wheels
.\.venv\Scripts\python.exe -m tools.reproduce_phase3c
```

This creates a fresh venv and source copy, installs the pinned wheels offline,
starts the app, uploads a static fixture, and downloads both report formats.
Results are in `evidence/phase3c/`, including live, browser, fresh-install and PDF
layout verification. These checks establish software workflow/report consistency,
not final detection accuracy. **Phases 1, 2 and 3 are implemented; Phase 4 evaluation
and academic submission/defense remain.**

## Phase 3B: detailed evidence and explainable assessment

If the application was already running, stop its web server with Ctrl+C, run
`python -m wp_spectra serve` again using the existing venv as shown below, and
refresh the browser. The new backend routes require a restart. Your history and
saved results remain available; existing analyses do not need to be rerun to
inspect their retained evidence. The database adds a nullable `control_for` field
automatically, preserving existing records.

Select an analysis and use **Inspect evidence**, the clickable finding/event
counts, or the **Evidence & assessment** panel:

- **Assessment:** Review reasons linked to original evidence, observed-resource
  counts, scope, uncertainty, and an expandable versioned policy explanation.
- **Static findings:** Rule ID, severity, engine, file, line/byte offset, matching
  snippet, rationale, limitations, occurrence count, package/file hashes, and
  artifact reference.
- **Runtime activity:** Files, database, accounts, scheduled tasks, and network
  observations. Filter by category, severity, stage, and text; open a record for
  its collector and before/after evidence.
- **Timeline:** Chronological static, runtime and workflow records. Filter by
  source as well as category/stage/severity/text. Timestamps are observation times,
  not proof of causal ordering.
- **Clean control:** Queue a separate run without installing the submitted
  plugin, or select a completed control. Compare patterns and supporting records.
- **Coverage & limits:** Actual collector status, errors, incomplete observation,
  cleanup state, evidence-integrity checks, and interpretation limitations.

Record lists and comparison patterns are paginated. The resource preview shows
up to 100 entries and explicitly points to the searchable runtime view for the
complete set. Record dialogs show up to 512-character static snippets inherited
from the scanner; the original uploaded ZIP and artifact hashes remain available
in the local workspace. No package text is rendered as HTML or executed by a view.

### Review policy 1.0.0

The policy is implemented in `wp_spectra/investigation.py` and displayed in the
assessment view. It assigns a review recommendation, not a malware probability,
proof of maliciousness, or a safe/unsafe verdict.

| Rule | Trigger | Interpretation |
|---|---|---|
| REVIEW-S1 | A configured static finding is medium/high severity | Inspect its matched code and context. Legitimate implementations can match. |
| REVIEW-R1 | An account snapshot shows creation/transition into enabled administrator privileges | Review the identity, authorization, and intended plugin behavior. Provisioning can be legitimate. |
| REVIEW-R2 | After installation, a file is written/deleted/moved into core/configuration paths, or a PHP file outside the submitted plugin | Review the file and collector evidence. Maintenance can be legitimate. |
| COVERAGE-C1 | A required stage is incomplete, evidence binding/contract checks fail, or cleanup remains unverified | Incomplete takes precedence, while recorded review indicators remain visible. |
| CONTROL-C1 | A normalized runtime pattern also occurs in the control | Show context; never suppress a review indicator or incomplete warning. |

Low-severity capabilities and routine database/cron/network activity do not become
threats merely because many events were recorded. The account rule recognizes the
enabled standard administrator capability; it is not a complete authorization or
custom-role analysis. Monitoring limits from Phase 2 continue to apply.

Assessment labels are **Review recommended**, **Capabilities and activity
observed**, **No configured indicators observed**, **Clean-control observation**,
or **Incomplete analysis**. The available scope is shown alongside them. A completed
static-only scan never implies runtime coverage. Failed/interrupted/partial runs
never receive a completed clean assessment. A control's source-package static
findings are retained for traceability but excluded from its observation assessment
because that plugin was not installed.

The assessment is derived from saved evidence with the displayed current policy
version. Raw analyzer artifacts are not rewritten. Contract/ID/hash checks detect
inconsistent evidence; they are not cryptographic signatures or a guarantee
against a local operator replacing artifacts.

### Clean-control comparison

Open a full analysis with retained runtime observations, choose **Clean control**,
then **Run clean control**. The queue creates a linked `mode=control` job using the
same source archive for validated intake; the submitted plugin is **not installed**.
The reference becomes selectable when that run completes. Its progress remains in
history. One outstanding control per source job is allowed.

The comparison identifies patterns also observed in control, patterns not observed
in that control, and control-only patterns. It compares category, action,
normalized resource and collector, with occurrence counts and original record
references. Cron timestamps and HTTP query strings are normalized; SQL patterns
use operation/table where recognized. Similar patterns can contain different
values or have different causes. An absent control pattern is not proof of plugin
causation. Up to three example references per side are shown per pattern; the
runtime view retains all records.

Image IDs, action timeout, and observation duration are checked for compatibility.
Mismatched environments produce a visible descriptive-only warning. A partial
subject run can be compared only with its incompleteness clearly shown; a failed
or partial reference cannot be selected as a completed control. Comparisons do
not change the review policy result.

### Evidence references and Phase 3B API

Static records use `S:<finding ID>`, runtime records `R:<event ID>`, and workflow
updates `P:<progress sequence>`. Each includes the job/analysis identity, artifact
name, JSON pointer (or SQLite progress sequence), and supporting evidence IDs.
Runtime before/after strings and raw source records are preserved. A missing value
from an event-only collector is labeled **Not captured**, not invented. Baseline
creation/deletion can explicitly indicate absence from the earlier/later snapshot.

| Endpoint | Purpose |
|---|---|
| `GET /api/jobs/<UUID>/investigation` | Scope, assessment/reasons/policy, coverage, limits, errors and resource overview |
| `GET /api/jobs/<UUID>/records` | Filters: `source`, `category`, `severity`, `stage`, `q`, `offset`, `limit` (1–100) |
| `GET /api/jobs/<UUID>/records/<record ID>` | Original record, location, supporting evidence, before/after and provenance |
| `GET /api/controls` | Latest 100 clean-control job records, including pending statuses |
| `POST /api/jobs/<UUID>/control` | Queue a linked clean control; empty body and current write token required |
| `GET /api/jobs/<UUID>/comparison?control=<UUID>` | Compatibility, normalized pattern groups, counts, references and limits |

All existing local host/origin/token boundaries apply. Control jobs cannot be
forged through the general upload mode parameter. Inspection uses a bounded cache
keyed by artifact changes and job state; changed evidence invalidates cached views.

### Phase 3B verification

```powershell
.\.venv\Scripts\python.exe -m tools.verify_phase3b
```

The delivered run passes **82 regression tests** and **17 real API/Docker checks**.
It demonstrates a controlled plugin, a clean control, and a failed activation;
checks every finding/event reference against the raw artifact; verifies all five
categories, combined filters, before/after values, and unchanged review indicators
after comparison. All three analysis sandboxes are cleaned up.

Optional browser QA: `python tools/verify_phase3b_browser.py` with the installed
Playwright/Edge development environment. All **14 browser checks** pass, including
source dialogs, filters, timeline pagination, control comparisons, keyboard tabs,
incomplete outcomes, mobile layout and harmless HTML text that must not execute.
See [`verification.json`](evidence/phase3b/verification.json),
[`browser-verification.json`](evidence/phase3b/browser-verification.json), and
the [assessment preview](evidence/phase3b/assessment-desktop.png).
These are development acceptance results, not general malware-detection accuracy.

Phase 3B is complete. PDF/JSON report delivery is implemented in Phase 3C above;
final detection-effectiveness evaluation remains in Phase 4.

## Phase 3A: dashboard, queue, progress and history

From the project workspace, start the local application:

```powershell
cd "D:\SEMESTER-7\Information Security\Project\VETO\veto"
.\.venv\Scripts\python.exe -m wp_spectra serve
```

Open **http://127.0.0.1:8765** in a browser. Submit a plugin ZIP and choose either
static inspection or static + sandbox analysis. Keep Docker Desktop running for
sandbox mode; static mode works without Docker. The existing venv and sandbox
image are sufficient. No Node build, npm install, hosted service, or additional
Python runtime dependency is required for this interface.

The dashboard provides upload validation, background execution, actual stage
updates, persistent history, hashes, plugin names, evidence counts, failure
details, and cleanup recovery. It supports desktop and mobile layouts. Detailed
investigation views and explainable review assessment are available in Phase 3B;
PDF and JSON report snapshots are available in Phase 3C above.

### Use and persistence

- ZIP uploads are limited to 50 MiB. One worker executes at a time, with up to eight
  pending/running submissions. The displayed filename never determines a path or
  shell command: storage and worker arguments use generated UUIDs.
- History and the queue live in `.local/app/history.sqlite3`. Per-job submissions,
  static/dynamic evidence, partial snapshots, and recovery records are under
  `.local/app/jobs/<job UUID>/`. These files are ignored by Git.
- Browser refresh retains status and history. The selected job is in the URL
  fragment; the browser polls saved progress roughly every 1.2 seconds.
- Ctrl+C stops the web server. A worker already executing finishes independently
  and saves its outcome. Queued work waits until the server starts again.
- After a restart, the server adopts workers that still hold a process lease. It
  does not execute those submissions twice. A dead worker becomes interrupted,
  available evidence is retained, and recovery checks/removes only its owned
  sandbox resources.
- When cleanup cannot be verified, queued jobs pause. Start Docker, select the
  affected job, and use **Retry sandbox cleanup**. A retry does not rerun the plugin.
- Partial evidence is explicitly labeled and is never treated as a completed
  analysis. Static-only completion is not a full sandbox result. Completion is
  not a malware-free verdict.
- Archive application data only after active workers have finished and the web
  server has stopped; keep the SQLite database and its associated data together.

Use another port or a separate workspace if needed:

```powershell
.\.venv\Scripts\python.exe -m wp_spectra serve --port 8766 --data-dir .local/another-workspace
```

Only one web server may own a given data directory. Keep it on a local disk, not
a shared network filesystem. Upload admission checks a 2 GiB local-storage budget;
individual JSON artifacts are capped at 64 MiB, with analyzer limits still applied.
Evidence growth after admission can increase the workspace beyond that admission
threshold, so archive old runs periodically.

### Phase 3A architecture and local API

The UI is buildless HTML/CSS/JavaScript. A loopback-only Python HTTP service uses a
SQLite WAL queue and one dispatcher. Analysis runs in separate Python processes,
with atomic evidence writes and OS-backed process leases. Per-stage callbacks
extend the analyzers without changing their existing result schemas or CLI behavior.
No production credentials, WordPress source bind mounts, or Docker socket are
provided to submitted plugins.

The service binds only to `127.0.0.1`. Host/origin checks and a per-server write
token reject cross-origin submissions. Package text is rendered as text, not HTML;
a restrictive content-security policy provides another boundary. This is a local,
single-user application, not a remotely accessible authenticated hosting service.

| Endpoint | Purpose |
|---|---|
| `GET /api/bootstrap` | Current write token and queue/service state |
| `POST /api/jobs?mode=full` or `mode=static` | Raw ZIP body, explicit Content-Length, `X-VETO-Filename` (percent encoded), `X-VETO-Token` |
| `GET /api/jobs?limit=25&offset=0` | Paginated history, counts and queue state |
| `GET /api/jobs/<job UUID>` | Metadata, persisted stage log, status and evidence availability |
| `GET /api/jobs/<job UUID>/evidence` | Retained static, dynamic and explicitly partial artifacts for integration |
| `POST /api/jobs/<job UUID>/retry-cleanup` | Request ownership-checked cleanup recovery; empty body and write token |

HTTP 202 means queued, not completed. Terminal states are completed, rejected,
failed, or interrupted. The UI job UUID identifies the submission record; its
`analysis_id` field correlates the underlying static and dynamic engine evidence.
Neither filename nor HTTP options can supply executable commands, image names,
filesystem destinations, or worker arguments.

### Phase 3A verification and demonstration

```powershell
.\.venv\Scripts\python.exe -m unittest discover -s tests -v
.\.venv\Scripts\python.exe -m tools.verify_phase3a
```

The live verifier uses controlled fixtures in separate ignored QA workspaces and
tests the HTTP workflow, all five runtime categories, persistent progress, server
restart, queued continuation, rejected packages, activation failure, timeout,
hostile filenames, and forced worker interruption with cleanup. No real malware
or production data is used. Results are saved under `evidence/phase3a/`.

Optional browser QA uses an already installed Playwright package and Microsoft
Edge: `python tools/verify_phase3a_browser.py`. It is a development check, not a
runtime dependency. It verifies real browser uploads, refresh, rejected input,
safe filename display, full execution, and mobile layout, and saves screenshots.

For a demonstration: submit `fixtures/dynamic/behaviors.zip` in full mode, refresh
while it runs, inspect its stage log and saved outcome, then submit
`fixtures/generated/traversal.zip` to show rejection. The benign fixture still
records ordinary WordPress events; those are observations, not automatic malware
judgments. Evidence/risk interpretation belongs in Phase 3B.

### Phase 3A acceptance boundary

The local workflow is implemented. Saved evidence documents regression tests,
real HTTP/Docker acceptance and browser checks. Windows with CPython 3.13, Docker
Desktop's Linux engine and Edge was verified. Other OS/browser combinations have
not been exercised here. Process/host power loss can leave partial evidence;
restart recovery reports that honestly and does not invent a completed result.
The inherited Phase 2 monitoring limits still apply.

Delivered verification: **59 regression tests**, **20 real API/Docker checks**,
and **10 browser checks**, all passing. Records are
[`regression-verification.json`](evidence/phase3a/regression-verification.json),
[`verification.json`](evidence/phase3a/verification.json), and
[`browser-verification.json`](evidence/phase3a/browser-verification.json).
The API suite's own initial test log predates one additional checkpoint test;
the separate regression record contains the final 59-test run.
See the [desktop preview](evidence/phase3a/desktop-complete.png) and
[mobile preview](evidence/phase3a/mobile-complete.png). QA servers are stopped
after verification; use `serve` to start your own workspace.

## Phase 1 static analyzer

Local, rule-based WordPress plugin ZIP inspection. The `scan` command never
executes, installs, or activates submitted PHP. No Docker, CEGA backend, cloud
account, or production WordPress connection is needed for Phase 1.

## Phase 2 is available

The explicit `observe` command runs a plugin copy in a fresh local Docker sandbox
and records behavioral evidence. See the [Phase 2 guide](docs/PHASE2_GUIDE.md) and
[completion record](docs/PHASE2_COMPLETION.md). Static `scan` behavior and its schema
remain compatible.

```powershell
# From VETO/veto/, with Docker Desktop running:
.\.venv\Scripts\python.exe -m wp_spectra observe fixtures/dynamic/behaviors.zip --output output/dynamic-demo.json
.\.venv\Scripts\python.exe -m wp_spectra validate-dynamic output/dynamic-demo.json
```

## Quick start (PowerShell)

From `D:\SEMESTER-7\Information Security\Project`:

```powershell
cd .\VETO\veto
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements-lock.txt
.\.venv\Scripts\python.exe tools/build_fixtures.py
.\.venv\Scripts\python.exe -m wp_spectra scan fixtures/generated/benign.zip --output output/benign.json
.\.venv\Scripts\python.exe -m wp_spectra scan fixtures/generated/suspicious.zip --output output/suspicious.json
.\.venv\Scripts\python.exe -m wp_spectra validate output/suspicious.json
```

The local environment is already installed in this checkout. Skip the first two
setup commands if reusing it. No PowerShell activation or execution-policy change
is needed. Use a new report filename on subsequent scans: existing reports are
never overwritten by the CLI.

**Runtime verified:** Windows, CPython 3.13, yara-python 4.5.4. Python 3.11+ is the
intended baseline; other OS/Python combinations have not been verified here.
Internet is needed to install dependencies; analysis and verification are offline.

To scan your own plugin, supply its local ZIP instead of a fixture path. A copy of
an installed plugin should first be packaged as one plugin folder in a ZIP.

## Verify Phase 1

```powershell
.\.venv\Scripts\python.exe -m tools.verify_phase1
```

This runs the security/acceptance suite, rebuilds deterministic fixtures, checks
every expected result against the schema, and regenerates evidence in `evidence/`.
It deliberately replaces generated development evidence; preserve a copy before
rerunning if you need an immutable historical record.

The delivered run passes **33 tests** and all nine fixture expectations. Additional
offline reproduction and real-package smoke checks are available:

```powershell
.\.venv\Scripts\python.exe -m tools.reproduce_local
.\.venv\Scripts\python.exe -m tools.smoke_bundled_plugin
```

Source-copy reproduction uses an isolated Python process with the same installed
dependencies. It does not claim a second person's signoff or another OS installation.

## Output and exit codes

- `0`: Static analysis completed, whether findings are present or absent.
- `2`: Package rejected, for example traversal, limits, corrupt ZIP, or missing input.
- `3`: Analysis/configuration/output failure. Treat the result as incomplete.
- Argument syntax errors use argparse's exit code `2` and do not produce a report.

Without `--output`, JSON goes to stdout. With `--output`, the JSON is written to
that path and a brief summary goes to stderr. Reports include hashes, metadata,
inventory, findings, evidence, limitations, effective limits, coverage, and errors.

`completed` means the configured static checks finished, not that a plugin is safe.
Medium/high indicators set `flagged_for_review`; low-only capability observations
do not. An incomplete report cannot produce a clean assessment.

## What is implemented

- Bounded private ZIP staging and preflight validation of every member.
- SHA-256 package/file inventory and optional exact-reference hash matching.
- Nine lexical PHP indicators, two real YARA rules, six package anomaly checks.
- Plugin header discovery and explainable, located evidence.
- Versioned JSON Schema, library interface, CLI, controlled fixtures, and tests.
- Explicit failure states and temporary staging cleanup.

The default reference-hash catalog is intentionally empty; no malware intelligence
feed is bundled. Demonstrate hash matching using the clearly labeled test catalog:

```powershell
.\.venv\Scripts\python.exe -m wp_spectra scan fixtures/generated/suspicious.zip --hash-catalog fixtures/generated/test_hash_catalog.json --output output/hash-demo.json
```

This demonstrates exact matching to an inert development fixture, not real malware
attribution. There is no dynamic execution, dashboard, PDF export, or cleanup of
infected websites in Phase 1.

## Documentation and evidence

- [Architecture and threat model](docs/ARCHITECTURE_AND_THREAT_MODEL.md)
- [Interfaces and schemas](docs/INTERFACES.md)
- [Rule catalog and limits](docs/RULES.md)
- [Demonstration and handoff](docs/DEMO_AND_HANDOFF.md)
- [Completion record](docs/PHASE1_COMPLETION.md)
- [Acceptance results](evidence/verification.json)
- [Detailed test log](evidence/tests.txt)
- [Controlled suspicious report](evidence/suspicious.json)

`web/` remains supporting lab material. Its existing CEGA/ExamSlot frontend,
external backend references, persistent storefront, and commerce/mail bootstrap
are not dependencies of this analyzer and have not been modified.
