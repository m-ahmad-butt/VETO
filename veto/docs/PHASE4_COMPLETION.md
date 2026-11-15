# Phase 4: controlled evaluation and academic preparation

The final experiment `../Evaluations/runs/final-v1/` is valid. The detector and
configuration remained unchanged. All nine runtime runs completed; all 23 planned
behavior instances were independently confirmed; exact resource listings found
no remaining analysis-owned containers, networks or volumes.

| Metric | Raw count | Percentage |
|---|---:|---:|
| Static precision | 6 / 8 flagged packages | 75.00% |
| Static recall | 6 / 8 suspicious packages | 75.00% |
| Dynamic event detection | 22 / 23 confirmed behaviors | 95.65% |
| Static false-positive rate | 2 / 8 benign packages | 25.00% |
| Report completeness | 909 / 909 recorded runtime events | 100.00% |

Static confusion matrix: TP=6, FP=2, TN=6, FN=2, incomplete=0.
These are 16 new static cases and eight new runtime fixtures plus a clean control.
The purposive synthetic dataset was designed by an author who knew the detector;
it is separate from development but not a blind independent benchmark. One trial
per case does not establish timing reliability or general malware accuracy.

False positives: inert documentation matched by raw YARA; a fixed legitimate
maintenance command. Static misses: two indirect command-call constructs.
Dynamic miss: direct-IP failed socket attempt without the monitored HTTP/DNS paths.
No detection rules were changed to eliminate these errors.

Ground truth combines fixture operation assertions with a separate direct reader
that loads neither WordPress nor the observer. Persistent effects additionally
require file/SQL corroboration. Transient/network truth relies on fixture return
assertions, not independent packet capture. Reporting completeness requires exact
full JSON preservation plus PDF record indexing; PDF excerpts are intentional.

Verification: 100 regression tests passed; 137 independent result/report audits
passed; fresh offline virtual-environment replay produced identical metric bytes.
Actual browser screenshots, a backup WebM and rendered report/presentation checks
are saved. Details are in `../Evaluations/verification/` and `../Evaluations/verification/regression.txt`.

Start with [the submission index](../submission/README.md),
[the protocol](../evaluation/PROTOCOL.md), and
[the remaining human gates](../submission/COMPLETION.md).

Evaluation and submission materials are delivered locally. Actual personal
contribution statements, a teammate's reproduction, viva readiness and external
submission remain human actions. No commit, push or institutional submission
has been performed by this work.
