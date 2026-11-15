# Demonstration and viva preparation

## Live demonstration (approximately 8 minutes)

1. Explain the trust boundary: submitted ZIP untrusted; host analyzer trusted.
2. Start VETO and upload `../Evaluations/samples/d-combined.zip` in full mode. Show
   actual installation/activation/frontend/admin/cron progress and cleanup.
3. Open evidence: show the administrator account's before/after capabilities,
   persistent file modification, scheduled task and network attempt.
4. Open the timeline and filter by category/stage. Explain that timestamps are
   observation times and that association is not proof of maliciousness.
5. Open coverage/limits. Show the saved clean control and explain background noise.
6. Download matching PDF/JSON. Show export identity and a referenced event.
7. Present metrics with counts. Explain the comment/maintenance false positives,
   indirect-call static misses and direct-IP runtime miss.
8. Recalculate saved metrics without rerunning plugins. Show cleanup.json.

If Docker is unavailable, use the backup WebM and retained reports. State clearly
that this is saved evidence. Do not describe playback as a live scan.

## Questions every member should rehearse

- Why combine static and dynamic analysis? Static inspects inert packages; dynamic
  supplies evidence for exercised behavior. Neither proves safety.
- What does the hash catalog establish? Exact equality to trusted references; the
  default catalog is empty and is not a malware feed.
- Why do false positives occur? Raw YARA matches inert documentation; legitimate
  maintenance code can have a suspicious capability.
- Why are indirect calls missed? No AST/data-flow/function-name resolution.
- What is independent ground truth? Controlled fixture assertions read separately,
  with direct file/SQL corroboration, not the monitor log alone.
- What does a blocked connection prove? Containment prevented progress; only an
  observed attempt is evidence of detection. Direct-IP failures can be missed.
- What is the difference between recall and report completeness? Recall/detection
  concerns capturing ground-truth behavior; completeness preserves captured events.
- What happens on failure? Incomplete status, retained partial evidence and verified
  cleanup/recovery; failure must not produce a clean verdict.
- What does Docker isolation omit? A separate kernel and comprehensive syscall tracing.
- Can a plugin tamper with telemetry? Application hooks can be bypassed; external
  collectors reduce reliance on those hooks but are not exhaustive.
- Why isn't this a general accuracy estimate? Small purposive same-author samples,
  one trial each, no real-malware corpus or representative prevalence.
- What did you personally contribute? Answer from confirmed contribution statements,
  demonstrate the corresponding source/evidence, and explain the whole workflow.

## Actual rehearsal record

Each member's rehearsal and overall-system explanation remain to be attested by
the people involved. Prepared answers are not evidence of viva performance.
