# VETO: WP Spectra — Phase 1 Static Analyzer

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
