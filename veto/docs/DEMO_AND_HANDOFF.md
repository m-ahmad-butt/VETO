# Demonstration and Handoff

## Five-minute Phase 1 demonstration

Use a PowerShell terminal in `VETO/veto/`. The environment is `.venv`; activation is
unnecessary. Choose unused output names to preserve earlier evidence.

1. **Explain the trust boundary.** The input ZIP is untrusted; PHP is never run.
   Show the architecture diagram and configured limits.
2. **Show a benign fixture.**

   ```powershell
   .\.venv\Scripts\python.exe -m wp_spectra scan fixtures/generated/benign.zip
   ```

   Explain the package/file hashes, metadata, no-indicators assessment, and explicit
   limitation that absence of findings does not prove safety.
3. **Show controlled suspicious indicators.**

   ```powershell
   .\.venv\Scripts\python.exe -m wp_spectra scan fixtures/generated/suspicious.zip --output output/demo-suspicious.json
   .\.venv\Scripts\python.exe -m wp_spectra validate output/demo-suspicious.json
   Get-Content output/demo-suspicious.json
   ```

   Point to a PHP finding and a YARA finding with byte offset, line, evidence,
   rationale, and limitation. The expected result is ten distinct rule findings.
4. **Show false-positive restraint.**

   ```powershell
   .\.venv\Scripts\python.exe -m wp_spectra scan fixtures/generated/capabilities.zip
   ```

   Scheduling, HTTP, and filesystem capabilities are low severity and do not by
   themselves set the medium/high review flag.
5. **Reject traversal.**

   ```powershell
   .\.venv\Scripts\python.exe -m wp_spectra scan fixtures/generated/traversal.zip
   $LASTEXITCODE
   ```

   Expected: rejected, unsafe-path error, incomplete assessment, exit code `2`.
6. **Demonstrate exact hash matching, with honest provenance.**

   ```powershell
   .\.venv\Scripts\python.exe -m wp_spectra scan fixtures/generated/suspicious.zip --hash-catalog fixtures/generated/test_hash_catalog.json
   ```

   Explain that this is a match to the known development fixture, not verified
   malware. The default catalog contains no unverified reference labels.
7. **Show saved verification evidence.** Open `evidence/verification.json` and
   `evidence/tests.txt`. These are development acceptance results, not final
   precision/recall measurements.

## Reproduce all acceptance evidence

```powershell
.\.venv\Scripts\python.exe -m tools.verify_phase1
.\.venv\Scripts\python.exe -m tools.smoke_bundled_plugin
```

The second command packages the bundled Akismet directory into ignored `output/`
and writes `evidence/bundled-akismet.json`. It never activates or runs Akismet and
does not independently verify its origin or classify it as ground-truth benign.

## Teammate reproduction checklist

This is a human acceptance step. Automated tests are not a substitute for another
teammate actually following the instructions.

- [ ] Start from a fresh checkout and install `requirements-lock.txt` in a new venv.
- [ ] Build fixtures and run the acceptance verifier successfully.
- [ ] Reproduce benign, suspicious, capabilities, traversal, and hash demonstrations.
- [ ] Validate a saved report and explain status versus assessment.
- [ ] Record operator, date, environment, observed results, and any setup issue.

No teammate signoff has been fabricated. Record the result when it is performed.

## Phase 2 handoff

- Consume the immutable Phase 1 static result through `analyze()` or the CLI.
- Preserve the UUID for correlation with a separate runtime run.
- Implement and validate the standalone runtime-event schema.
- Add a separate combined envelope rather than changing existing report meaning.
- Keep runtime execution outside the analyzer boundary.
- Start with minimal WordPress; the legacy storefront's always-active plugins are
  not an appropriate quiet baseline.
- Preserve static evidence if later execution fails and report partial coverage.

## Viva questions to prepare

- Why is a hash match meaningful only with trustworthy reference provenance?
- Why does `eval` merit review while a scheduled task alone may not?
- What can lexical checks miss compared with AST/taint analysis?
- Why can YARA match inert comments and strings?
- How do archive validation and actual streamed limits prevent common ZIP attacks?
- Why is a completed static scan different from a safe plugin verdict?
- How will Phase 2 handle events that snapshots miss and distinguish background activity?
