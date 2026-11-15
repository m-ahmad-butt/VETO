# Interfaces and Versioned Results

## CLI

Run commands from `VETO/veto/`:

```powershell
.\.venv\Scripts\python.exe -m wp_spectra --help
.\.venv\Scripts\python.exe -m wp_spectra scan --help
.\.venv\Scripts\python.exe -m wp_spectra scan PATH_TO_PLUGIN.zip --output output/result.json
.\.venv\Scripts\python.exe -m wp_spectra validate output/result.json
```

Use `--hash-catalog PATH` to supply trusted local hash references and `--work-dir
PATH` to choose a trusted temporary parent. All numerical limits have corresponding
CLI flags; inspect `scan --help`. Limits must be positive integers.

## Library

```python
from wp_spectra.scanner import analyze
from wp_spectra.__main__ import validate_report

report = analyze("plugin.zip")
validate_report(report)
if report["status"] != "completed":
    print(report["errors"])
```

`analyze(source, *, limits=None, hash_catalog=None, work_dir=None)` returns a plain
JSON-compatible dictionary. `limits` is an immutable `wp_spectra.intake.Limits`.
Library callers must check status; findings retained on a failed run are partial.
Programmer errors outside analysis, such as invalid constructor parameters, can
raise exceptions.

## Result schema 1.0.0

Authoritative contract: `wp_spectra/resources/result.schema.json`, JSON Schema
Draft 2020-12. Validate using the CLI or `validate_report()`, which checks formats.
The schema rejects unknown top-level fields and clean assessments on failed runs.

| Field | Meaning |
|---|---|
| `analysis_id` | New UUID per run; use for future module correlation. |
| `schema_version`, `analyzer_version` | Contract and application versions. |
| `status` | `completed`, `rejected`, or `failed`; completion is static-only. |
| `started_at`, `finished_at` | UTC ISO-8601 timestamps. |
| `package` | Submitted basename, copied ZIP byte count and SHA-256. Size/hash are null when intake did not finish. |
| `plugin_metadata` | Candidate conventional main files, declared names/versions; not authenticated. |
| `inventory` | Every successfully staged regular member's relative path, actual size, SHA-256. |
| `static_findings` | Stable rule IDs, per-run finding IDs, engine, severity, resource, location, occurrence count, explanation, limitation, inline evidence. |
| `runtime_events` | Empty in Phase 1; never invent events from static capabilities. |
| `errors` | Stage, code, message; failed reports must contain an error. |
| `coverage` | Per-stage completion/failed/not-run; dynamic is always not-run here. |
| `configuration` | Effective limits, YARA version, rule fingerprint, catalog fingerprint/version/reference count, static-only execution marker. |
| `summary` | Finding counts, severity counts, review flag and assessment. |
| `limitations` | Interpretation and coverage limitations included in every report. |

Finding IDs (`F00001`) and evidence IDs (`E00001`) are local to an analysis. Rule IDs
are stable across runs. `line` is 1-based and byte offsets are 0-based in original
file bytes. Findings aggregate repeated occurrences of the same rule in a file;
the first matching location/snippet is shown and `occurrence_count` gives the total.
Package-level findings have null resource/location where appropriate. Snippets are
truncated to 512 characters and JSON-escaped on export.

`rules_sha256` hashes the concatenation of raw `static.yar` bytes, one NUL byte,
and raw `php.py` bytes. `hash_catalog_sha256` hashes the catalog's raw bytes. Package
structure checks are versioned with `analyzer_version` and the Git checkpoint.
Timestamps, analysis IDs, and evidence observation times vary between runs;
inventory, rule matches, and locations are repeatable for identical inputs/config.

## Assessment policy

- `no_indicators_observed`: completed with no configured findings.
- `capabilities_observed`: completed with low-severity findings only.
- `review_recommended`: completed with at least one medium/high finding.
- `incomplete`: rejected/failed; never interpret as safe or clean.

`flagged_for_review` is true only for completed runs with medium/high findings.
For Phase 4 static classification metrics, use this documented policy consistently.
Incomplete runs must be reported separately, not counted as true negatives.

## Hash catalog

```json
{
  "version": "my-reviewed-catalog-1",
  "entries": [
    {
      "sha256": "<64 lowercase hexadecimal characters>",
      "label": "<accurate reference description>",
      "source": "<verifiable provenance>",
      "severity": "medium"
    }
  ]
}
```

Replace placeholder strings with real values. Exact reference matches are per-file,
not package ZIP matches. Package ZIP hashes are inventory identifiers. Duplicate or
malformed references fail configuration. The shipped catalog has zero entries;
the fixture catalog is explicitly test-only and makes no malware attribution claim.

## Runtime integration contract

`resources/runtime-event.schema.json` freezes an independent event contract for
Phase 2: event/analysis IDs, UTC time, one of five behavior categories, action,
resource, before/after evidence, collector, execution stage, and attribution level.
Validate against this separate schema when implementing the runtime collector.
No collector or example presented as a real observation is shipped in Phase 1.

The Phase 1 result schema intentionally requires an empty runtime array. Introduce
a versioned combined report schema in Phase 2/3 that references the event contract
and embeds the unmodified static result. Do not silently change schema 1.0.0 or
reinterpret old reports. Breaking changes require a major version; new combined
envelopes can coexist with existing static consumers.
