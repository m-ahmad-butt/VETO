"""Generate the academic package from saved measurements, never invented scores."""
import argparse
import base64
from html import escape
import json
from pathlib import Path
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, PageBreak, Table, TableStyle, Image

BASE=Path(__file__).resolve().parents[1]
OUT=BASE/'submission'
from tools.phase4_paths import RUNS, SUBMISSION
ARTIFACTS=SUBMISSION
LABELS={'static_precision':'Static precision','static_recall':'Static recall',
        'dynamic_event_detection_rate':'Dynamic event detection','false_positive_rate':'False-positive rate',
        'report_completeness':'Report completeness'}


def write(name,text): (OUT/name).write_text(text.strip()+'\n',encoding='utf-8')


def main(run_name):
    root=RUNS/run_name
    metrics=json.loads((root/'metrics.json').read_text())
    if not metrics['valid_experiment']:raise ValueError('Do not publish an invalid experiment as final')
    OUT.mkdir(exist_ok=True)
    ARTIFACTS.mkdir(parents=True,exist_ok=True)
    rows=[[LABELS[k],str(v['numerator']),str(v['denominator']),f"{v['percent']:.2f}%" if v['percent'] is not None else 'Undefined'] for k,v in metrics['metrics'].items()]
    matrix=metrics['confusion_matrix'];sizes=metrics['sample_sizes']
    sections=[
        ('Abstract and project scope',[
            'VETO: WP Spectra is a local rule-based workflow for static inspection, disposable WordPress execution and evidence reporting. It accepts plugin ZIPs, records explainable indicators and selected runtime effects, and retains PDF/JSON reports after sandbox cleanup.',
            f"The final controlled evaluation uses {sizes['static_plugins']} new static packages and {sizes['runtime_runs_including_control']} runtime runs including a clean control. It measures all five metrics specified in proposal Section 5.2. Results describe a small synthetic study, not real-world malware-detection accuracy.",
            'The contribution is an integrated investigation workflow with explicit coverage and uncertainty. A review recommendation is not proof of maliciousness. No AI/LLM classification, production monitoring, automatic remediation or product-superiority benchmark is implemented.',
            'The original proposal is retained as 03.pdf. Development acceptance evidence for Phases 1-3 is separate from final evaluation evidence. The local application targets Windows/CPython 3.13 and Docker Desktop Linux containers.'
        ]),
        ('Architecture and trust boundaries',[
            'Data flow: untrusted ZIP -> private bounded intake -> hashes / PHP lexical rules / YARA -> static evidence -> optional disposable sandbox -> retained runtime evidence -> investigation policy -> immutable PDF/JSON pair.',
            'The Python host application, rule files and Docker control plane are trusted. Submitted package names, contents and metadata are untrusted. ZIP entries are validated before extraction, including traversal, collisions, special files, size and expansion limits. Static analysis never invokes submitted PHP.',
            'A loopback HTTP service provides buildless HTML/CSS/JavaScript views. SQLite stores jobs and progress; one dispatcher starts separate workers. Process leases support restart adoption and interrupted-run recovery. UUID storage paths, local host/origin checks and a per-server write token protect local write routes.',
            'The analyzer stays outside plugin execution. Each full run receives uniquely labeled containers, tmpfs volumes and an isolated internal network. No host repository bind mount or Docker socket is passed to submitted code. Cleanup verifies exact resource ownership and absence.',
            'The legacy web/ WordPress/CEGA lab is optional historical material. It is not the execution sandbox or a dependency of VETO.'
        ]),
        ('Static analysis and interpretation',[
            'The scanner inventories package/file SHA-256 hashes, discovers conventional plugin headers, and applies nine lexical PHP indicators, two YARA rules and package anomaly rules. Its default known-hash catalog is empty; hash matching demonstrates reference equality, not malware intelligence.',
            'Every finding retains rule ID, severity, file location, matched evidence, rationale and limitations. The frozen flagging policy recommends review when a completed scan contains a medium/high finding. Low-severity capabilities alone do not flag a package.',
            'Lexical masking avoids many matches in comments and strings, but YARA intentionally sees raw bytes. There is no AST, taint, reachability, callback resolution or deobfuscation engine. Fixed legitimate command execution can still trigger review.',
            'Suspicious static evaluation functions are intentionally never called. Their ground-truth labels describe dangerous source constructs in the controlled examples, rather than a claim that these packages are deployed malware.'
        ]),
        ('Dynamic execution and evidence reporting',[
            'Execution stages install and activate the submitted copy, visit frontend and authenticated administrator pages, run due cron and observe for a finite idle window. Before/after snapshots and event collectors cover files, database, accounts/roles, scheduled tasks and selected network attempts.',
            'File monitoring combines recursive inotify and hashes. Database evidence combines mutation-query audit and direct SQL state snapshots. Account and cron state are compared between snapshots. Network collectors include WordPress HTTP hooks, an internal HTTP sink and a DNS sink.',
            'The submitted plugin executes as user 33:33 with dropped capabilities, no privilege escalation, a read-only root filesystem and resource limits. Containers share the Linux kernel; this is not a virtual-machine detonation system.',
            'The investigation view links findings/events to source artifacts, before/after values and a chronological timeline. Clean-control comparison supplies background context without suppressing review indicators. Partial, failed and interrupted outcomes remain explicitly incomplete.',
            'PDF and JSON are generated from a consistent frozen capture. JSON preserves full raw evidence; PDF contains readable details and an index of every retained record with explicit excerpts. Companion checksums detect changed download bytes; they are not digital signatures.'
        ]),
        ('Controlled evaluation methodology',[
            'The dataset contains 8 benign and 8 suspicious static cases, plus benign, file, database, account, cron, network, direct-IP and combined runtime fixtures. A separate clean-control run installs no plugin. All cases are new after development, with hashes, versions, labels, rationale and execution prerequisites in evaluation/manifest.json.',
            'The same author knew the detector and selected challenging cases deliberately. These are separate evaluation cases but not a blind independent holdout. No random sampling, independent labeler or real-malware corpus is claimed. Each case has one execution; repeat-run variability is unmeasured.',
            'Before execution, the runner freezes detector/runtime/evaluator/package hashes, effective settings, package versions and Docker image IDs. It checks the source inventory again after evaluation. No detection rule is changed to improve the measurements.',
            'Dynamic fixtures assert operation outcomes. Before cleanup, a separate direct PHP reader loads no WordPress/observer code and reads receipts, file contents, account capabilities, options and cron state through independent file/SQL operations. Persistent changes require both fixture success and direct corroboration.',
            'Transient creation and network attempts use fixture operation/return assertions, not independent packet capture. Instrumentation is trusted because these are controlled authored fixtures. No event is treated as ground truth solely because VETO reported it.',
            'Settings: action timeout 15 seconds, whole-job timeout 300 seconds, idle observation 2 seconds, one trial per case. Environment details and actual stage results are saved. Every runtime run must complete and clean up for the experiment to be valid.'
        ]),
        ('Measured results',[
            f"Static confusion matrix: TP={matrix['tp']}, FP={matrix['fp']}, TN={matrix['tn']}, FN={matrix['fn']}; incomplete={matrix['incomplete']}. The static review policy is used for both precision/recall and benign false-positive rate.",
            f"Dynamic denominator: {sizes['confirmed_test_behaviors']} independently confirmed behavior instances out of {sizes['planned_test_behaviors']} planned instances. A behavior counts once regardless of duplicate collector matches. The complete per-instance matching table is in metrics.json.",
            f"Reporting denominator: {sizes['recorded_runtime_events']} raw runtime events, including the clean control and fixture instrumentation. Completeness requires exact full JSON preservation plus the event's PDF index entry. PDF excerpts are not required to reproduce every raw field.",
            'The table below reports raw counts and percentages. Zero denominators would be undefined (JSON null). Report completeness measures preservation of recorded events; it cannot establish that all executed behaviors were observed.',
            'Measurement integrity and software correctness are separate. The full regression suite passes 100 tests (95 existing plus 5 measurement-contract tests). An additional 137 independent checks verify replay, hashes, report pairing and full record indexes. The initial restricted regression attempt encountered a fixture ZIP permission error; the successful local-permission rerun is saved in ../Evaluations/verification/regression.txt.'
        ]),
        ('Error analysis and limits',[
            'Static false positives: '+', '.join(metrics['false_positives'])+'. The documentation case contains an inert eval/request example that raw YARA matches; the maintenance case uses a fixed legitimate shell command. Both require context rather than a malware verdict.',
            'Static false negatives: '+', '.join(metrics['false_negatives'])+'. These construct function names or invoke a callback indirectly. The frozen lexical rules do not resolve these flows. No detector change was made after seeing the scores.',
            'Dynamic misses: '+('; '.join(b['case']+'/'+b['behavior'] for b in metrics['dynamic_misses']) or 'None in this selected set')+'. A direct-IP socket failure can lack DNS or WordPress HTTP telemetry; a blocked attempt is not equivalent to an observed attempt.',
            'Recursive inotify has watch-creation races and does not retain deleted content. Account/cron diffs can miss changes reverted between snapshots. Mutation SQL is an attempted operation unless state corroborates success. WordPress hooks can be bypassed or tampered with.',
            'A clean control describes this baseline only; pattern absence does not prove plugin causation. Unexercised routes, privilege-dependent paths, delayed behavior, alternative protocols and environment dependencies can remain unseen. One trial per case cannot establish timing reliability.',
            'Potential future work includes process/syscall network observation, stronger isolation, richer PHP analysis, broader separately labeled datasets and repeated trials. These are future improvements, not delivered features.'
        ]),
        ('Reproducibility and administrator workflow',[
            'Install the pinned environment following Readme.md. Static mode requires Python dependencies; new full runs also require Docker Desktop and the dedicated sandbox image described in docs/PHASE2_GUIDE.md. The UI needs no Node build or cloud account.',
            'Start python -m wp_spectra serve from veto/. Upload a controlled ZIP, select static/full analysis, inspect progress/history, open evidence and before/after records, compare a clean control if appropriate, and export a PDF/JSON pair. Saved exports remain accessible after cleanup and server restart.',
            f"The authoritative run is ../Evaluations/runs/{run_name}/. Its freeze.json, source/, manifest.json, raw-hashes.json, per-case static/dynamic/ground-truth records, exports and metrics permit offline replay. Recalculate with python -m tools.evaluate_phase4 calculate ../Evaluations/runs/{run_name}.",
            'An automated fresh virtual environment installed the pinned dependencies offline and replayed the frozen evaluator against a copied raw run. The resulting metrics.json bytes exactly matched the original. This verifies same-machine reproduction, not an independent teammate sign-off. REPRODUCIBILITY.md gives the setup and a new named execution command. New runtime executions may differ in timestamps, IDs, background events and report bytes.',
            'The backup browser recording demonstrates retained evidence, timeline, coverage and PDF/JSON downloads. It has no narration and does not substitute for a live execution or independent reproduction. DEMO_AND_VIVA.md supplies the demonstration script and defense questions.'
        ]),
        ('Requirement-to-evidence mapping',[
            'Static analysis: wp_spectra/intake.py, scanner.py, php.py and resources/static.yar; evaluation static raw reports and confusion matrix.',
            'Isolated execution: wp_spectra/dynamic.py, sandbox/; per-run isolation settings, actual image IDs, successful lifecycle and independent cleanup.json listings.',
            'Five behavioral categories: sandbox/runtime/observer.py and snapshot.php; single-category/combined ground-truth assertions and behavior matching records in metrics.json.',
            'Evidence report: investigation.py, reports.py, report.schema.json; all per-case PDF/JSON pairs, source references and runtime-event completeness checks.',
            'Administrator workflow: app_server.py, app_service.py, app_store.py, app_worker.py and web/; saved Phase 3 workflow/browser/fresh-install verification and Phase 4 screenshots/recording.',
            'Evaluation: evaluation/PROTOCOL.md, manifest.json, frozen source, raw results, metrics.json and metrics.csv; false-positive and missed-detection analysis in this report.',
            'Submission and defense: this report, presentation.html/PDF, reproduction guide, contribution record and viva notes. Member contribution confirmation, teammate reproduction and actual viva readiness are human attestations; preparation does not claim they have happened.'
        ]),
        ('Sources, authorship and conclusion',[
            'Primary project sources: 03.pdf (proposal, Section 5); the four-phase plan dated 15 November 2026; Readme.md; docs/RULES.md; docs/INTERFACES.md; docs/PHASE2_GUIDE.md; and the versioned application sources retained in the frozen run.',
            'Measured evidence: ../Evaluations/runs/'+run_name+'/freeze.json, cleanup.json, raw-hashes.json, run-index.json, metrics.json, metrics.csv and per-case ground-truth/static/dynamic/export records. Historical software verification is retained under evidence/phase1-equivalent root evidence, phase2 and phase3a/3b/3c.',
            'Team identities from the repository: Muhammad Ahmad Butt (23L-3059), Ali Ahmed (23L-3067), Muhammad Zain Tahir (23L-3078). Course: CS3002 Information Security, FAST NUCES Lahore. Project advisor: Sarah Abid Khan. Actual contribution statements require member confirmation.',
            'Phase 4 evaluation tooling and submission drafts were prepared with Codex assistance in the local workspace. The team should review the artifacts and describe assistance according to its course policy. No member contribution, independent review or oral-defense performance is fabricated.',
            'VETO delivers the proposal workflow and the controlled evaluation now supplies the specified measurements. The measurements support a reproducible account of selected detections, errors and reporting preservation. They do not justify a universal safety verdict or broad malware-accuracy claim.'
        ]),
    ]
    styles=getSampleStyleSheet()
    styles.add(ParagraphStyle(name='BodyV',fontName='Helvetica',fontSize=10.5,leading=15,spaceAfter=12,textColor=colors.HexColor('#253044')))
    styles['Heading1'].textColor=colors.HexColor('#153d4a')
    story=[]
    story += [Spacer(1,75),Paragraph('VETO: WP Spectra',styles['Title']),Spacer(1,15),Paragraph('Static and Dynamic Security Analysis of WordPress Plugins',styles['Heading2']),Spacer(1,25),Paragraph('Final implementation and controlled evaluation',styles['Heading2']),Spacer(1,30)]
    for line in ['CS3002 Information Security | FAST NUCES Lahore','15 November 2026','Muhammad Ahmad Butt | 23L-3059','Ali Ahmed | 23L-3067','Muhammad Zain Tahir | 23L-3078','Project advisor: Sarah Abid Khan','Evidence run: '+run_name]:story.append(Paragraph(escape(line),styles['BodyV']))
    story.append(Spacer(1,25));story.append(Paragraph('Synthetic controlled evaluation. Counts, limitations and raw evidence accompany every result.',styles['BodyV']))
    md=['# VETO: WP Spectra - Final Report','', '15 November 2026 | CS3002 Information Security | FAST NUCES Lahore','']
    for title,paragraphs in sections:
        story.extend([PageBreak(),Paragraph(escape(title),styles['Heading1']),Spacer(1,14)])
        md.extend(['## '+title,''])
        for paragraph in paragraphs:
            story.append(Paragraph(escape(paragraph),styles['BodyV']));md.extend([paragraph,''])
        if title=='Measured results':
            data=[[Paragraph(escape(x),styles['BodyV']) for x in row] for row in [['Metric','Numerator','Denominator','Result'],*rows]]
            table=Table(data,colWidths=[180,78,95,87]);table.setStyle(TableStyle([('BACKGROUND',(0,0),(-1,0),colors.HexColor('#e5eef0')),('VALIGN',(0,0),(-1,-1),'TOP'),('LINEBELOW',(0,0),(-1,0),1,colors.HexColor('#153d4a')),('BOTTOMPADDING',(0,0),(-1,-1),7)]));story.append(table)
            md.extend(['| Metric | Numerator | Denominator | Result |','|---|---:|---:|---:|',*['| '+' | '.join(row)+' |' for row in rows],''])
    output=ARTIFACTS;output.mkdir(parents=True,exist_ok=True)
    def footer(canvas,doc):
        canvas.setFont('Helvetica',8);canvas.setFillColor(colors.HexColor('#526273'))
        canvas.drawString(48,25,'VETO | Controlled evaluation | 15 November 2026');canvas.drawRightString(A4[0]-48,25,str(doc.page))
    SimpleDocTemplate(str(output/'VETO_FINAL_REPORT.pdf'),pagesize=A4,rightMargin=48,leftMargin=48,topMargin=45,bottomMargin=45,title='VETO Final Report',author='VETO project team').build(story,onFirstPage=footer,onLaterPages=footer)
    (ARTIFACTS/'VETO_FINAL_REPORT.pdf').write_bytes((output/'VETO_FINAL_REPORT.pdf').read_bytes())
    write('FINAL_REPORT.md','\n'.join(md))
    write('REPRODUCIBILITY.md',f'''# Reproducing VETO and the Phase 4 measurements

Authoritative run: `../../Evaluations/runs/{run_name}/`. Do not overwrite it.

## Recalculate saved results without Docker

From `VETO/veto/`:

```powershell
python -m venv .venv
.\\.venv\\Scripts\\python.exe -m pip install -r requirements-dev.txt
.\\.venv\\Scripts\\python.exe -m tools.evaluate_phase4 calculate ../Evaluations/runs/{run_name}
```

This verifies saved raw hashes and recomputes all metrics from individual records,
not from the existing percentage table. Check `valid_experiment: true`.
Expected timestamps/IDs are not regenerated; metric values should match exactly.

## Execute a fresh experiment from the frozen source

Copy `../Evaluations/runs/{run_name}/source/` to a new short local directory. It contains
the exact detector, sandbox, evaluator, sample generator, manifest and packages.
Create a new venv there and install the frozen lockfile plus `pypdf==7.1.0`.
The full repository copy additionally includes tests/docs and requirements-dev.txt.

```powershell
python -m venv .venv
.\\.venv\\Scripts\\python.exe -m pip install -r requirements-lock.txt
.\\.venv\\Scripts\\python.exe -m pip install pypdf==7.1.0
docker build -t veto-sandbox:phase2 sandbox
docker pull mysql:8.0
docker pull wordpress:cli-php8.3
.\\.venv\\Scripts\\python.exe -m tools.evaluate_phase4 run --name independent-reproduction
```

Compare actual image IDs to the original freeze.json. Rebuilt/upstream image tags
can differ; disclose that difference rather than claiming an identical environment.
For exact image reuse, retain the original local image IDs or save/load them with
Docker outside the submission archive. No Docker images are bundled in the ZIP.
Network access is only needed for installation/image preparation. The controlled
experiment runs locally. A new runtime experiment takes several minutes.

Expect all declared behaviors to be confirmed, all sandboxes cleaned, and sources
unchanged. Background event counts, timestamps, IDs, report bytes and timing can
vary. Compare semantic outcomes and individual misses, not byte equality of new runs.

## Application and full tests

The full repository's `Readme.md` and `docs/PHASE2_GUIDE.md` contain setup/user guides.
Use `python -m wp_spectra serve` from veto/ and open http://127.0.0.1:8765.
Use only the runtime `d-*.zip` samples for full mode; `s-*.zip` are static-only.
Run the full regression suite with `python -m unittest discover -s tests -v`.
Fixture generation needs write access to fixtures/. Tests do not require live Docker.

## Submission build and visual QA

`python -m tools.prepare_submission --run {run_name}` regenerates report/HTML slides
from saved metrics. The optional capture/render tool runs with a development Python
containing Playwright 1.63.0 and PyMuPDF 1.28.2, with installed Microsoft Edge.
It records the actual local UI and prints the HTML presentation to PDF.
These are QA dependencies, not application dependencies. Backup recording has no audio.

## Human reproduction record

Teammate: not yet confirmed. Date/environment: not yet confirmed. Outcome: not yet
confirmed. Fill with an actual independent run; automated execution is not a teammate
sign-off. Attach the new run's freeze, metrics and cleanup files.
''')
    write('DEMO_AND_VIVA.md','''# Demonstration and viva preparation

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
''')
    if not (OUT/'CONTRIBUTIONS.md').exists():
        write('CONTRIBUTIONS.md','''# Team contribution record

Repository-listed members:

| Member | Roll number | Actual contribution and evidence | Member confirmation |
|---|---|---|---|
| Muhammad Ahmad Butt | 23L-3059 | Awaiting member-provided statement | Not confirmed |
| Ali Ahmed | 23L-3067 | Awaiting member-provided statement | Not confirmed |
| Muhammad Zain Tahir | 23L-3078 | Awaiting member-provided statement | Not confirmed |

Do not infer personal roles from shared commits or the automated implementation.
Phase 4 tooling, controlled execution and submission drafts were prepared with
Codex assistance in this workspace. Team members must review and accurately
describe their own work and any assistance under the course's applicable policy.
''')
    write('REQUIREMENT_EVIDENCE.md','''# Proposal objective to evidence map

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
''')
    write('COMPLETION.md',f'''# Phase 4 completion record

## Delivered and measured

- New documented evaluation cases, frozen detector/configuration/environment.
- Independent fixture ground truth with direct file/SQL corroboration.
- Valid experiment `{run_name}`; all five metrics computed from saved raw results.
- Counts, denominators, selection limits and false positives/misses disclosed.
- Every full run's cleanup verified and independently listed.
- 100 regression tests passed, with measurement-contract checks.
- 137 independent result/export audit checks passed.
- Fresh offline virtual environment reproduced identical metric bytes.
- Reproduction scripts, frozen source, raw evidence, report pairs and metric tables.
- Final report, 12-slide HTML/PDF presentation, actual screenshots and a WebM backup demo.
- Editable full-system Mermaid diagram in ARCHITECTURE.md and viva preparation.
- Requirement-to-evidence mapping and contribution record prepared.

## Human confirmations still required before claiming academic closeout

- Actual contribution statements/confirmation from all listed members.
- A teammate's independent reproduction if the instructor/plan requires it.
- Actual rehearsal and each member's ability to defend the delivered system.
- Team review of final artifacts and the instructor's exact submission format.
- Actual institutional submission; this workspace does not submit externally.

No automated tool can truthfully attest these personal actions on someone's behalf.
Implementation/evaluation preparation is delivered; do not mark those human gates
as passed until the corresponding evidence is supplied.
''')
    # Portable, editable HTML presentation. No hosted fonts or scripts.
    metric_table='<table><tr><th>Metric</th><th>Count</th><th>Result</th></tr>'+''.join('<tr><td>'+escape(r[0])+'</td><td>'+r[1]+'/'+r[2]+'</td><td>'+r[3]+'</td></tr>' for r in rows)+'</table>'
    slides=[
        ('VETO: WP Spectra','<p class="big">Scan. Observe. Report.</p><p>Static and dynamic security analysis of WordPress plugins</p><p>CS3002 Information Security<br>FAST NUCES Lahore | 15 November 2026</p><p style="font-size:23px">Muhammad Ahmad Butt | Ali Ahmed | Muhammad Zain Tahir</p>','Introduce all three members and the proposal workflow.'),
        ('The investigation workflow','<ol><li>Inspect the submitted package without executing it</li><li>Exercise a copy in disposable WordPress</li><li>Review evidence and export matching PDF/JSON</li></ol><p>Suspicious activity requires administrator interpretation.</p>','Describe the motivating SEO-spam incident as project motivation, not a case benchmark.'),
        ('Architecture and trust boundaries','<p class="flow">Untrusted ZIP → Bounded intake → Static rules</p><p class="flow">Disposable sandbox → Retained evidence → Reports</p><p>Trusted host controller stays outside submitted code.<br>Legacy WordPress/CEGA lab remains separate.</p>','Explain UUID storage, process workers, SQLite history and restart adoption.'),
        ('Disposable execution','<ul><li>Fresh containers, tmpfs storage and internal network per run</li><li>Non-root plugin, dropped capabilities, no host source mount</li><li>Bounded actions and observation window</li><li>Ownership-checked cleanup with retained evidence</li></ul><p>Containers share the Linux kernel.</p>','Show isolation settings in an actual dynamic.json. Do not claim VM-level containment.'),
        ('Five observed behavior categories','<ol><li>File creation, modification and deletion</li><li>Database mutation evidence</li><li>Account and role changes</li><li>Scheduled-task changes</li><li>Selected outbound connection attempts</li></ol>','Explain SQL attempts versus success; transient states and alternate protocols remain gaps.'),
        ('Evidence before a verdict','<p>Findings link to source records and before/after values.<br>Timeline, coverage and clean controls preserve context.</p><p>Incomplete runs stay incomplete.<br>PDF/JSON exports freeze one consistent evidence capture.</p>','Open the real evidence screenshot or live retained job. Review recommendation is not malware probability.'),
        ('Evaluation design','<ul><li>16 static packages: 8 benign + 8 suspicious</li><li>8 runtime fixtures + 1 clean control</li><li>New cases; detector and settings frozen before execution</li><li>Fixture assertions plus separate direct file/SQL reads</li></ul><p>Same-author synthetic study; one trial per case.</p>','Source: evaluation/PROTOCOL.md and final run freeze.json. Not an independent blind benchmark.'),
        ('Measured results',metric_table,'Source: ../Evaluations/runs/'+run_name+'/metrics.json. FPR uses static review flags. Completeness requires exact JSON and PDF index preservation.'),
        ('False positives and missed detections','<ul><li>Inert documentation matches raw-byte YARA</li><li>Legitimate fixed maintenance command triggers review</li><li>Indirect function calls evade lexical PHP checks</li><li>Direct-IP failed sockets can lack network evidence</li></ul>','Use individual source packages and matched/missed records to explain each error. No detector was tuned after results.'),
        ('Limits of the measurements','<ul><li>Small purposive synthetic dataset</li><li>No independent labeler or real-malware corpus</li><li>Finite actions; one trial per case</li><li>Application hooks and snapshot races remain</li></ul><p>Report completeness is not monitoring completeness.</p>','No product comparison or general malware accuracy claim. Explain blocked versus detected.'),
        ('Reproduce and inspect','<p>Saved manifest + frozen source + raw results + metric script</p><p class="code">python -m tools.evaluate_phase4 calculate<br>../Evaluations/runs/'+run_name+'</p><p>Recalculation needs no Docker.<br>New runtime trials need the documented Docker images.</p>','This is one command on one line; the displayed wrap is typographic. Show cleanup.json and raw-hashes verification.'),
        ('Delivered system and academic closeout','<p>The complete investigation workflow and controlled measurements are delivered.</p><p>Review the report and raw evidence.<br>Confirm member contributions, independent reproduction and viva readiness before academic closeout.</p>','Human contribution and readiness attestations are pending until members supply them. Do not imply external submission has occurred.'),
    ]
    image=ARTIFACTS/'screenshots/assessment.png'
    if image.exists():
        data=base64.b64encode(image.read_bytes()).decode()
        slides[5]=(slides[5][0],'<img class="evidence" alt="Actual VETO evaluation evidence view" src="data:image/png;base64,'+data+'">',slides[5][2])
    css='''*{box-sizing:border-box}body{margin:0;background:#102b36;color:#183442;font-family:Arial,sans-serif}section{display:none;background:#f6f8f7;width:1280px;height:720px;padding:62px 76px;position:relative;overflow:hidden}section.active{display:block}h1{font-size:45px;margin:0 0 35px;line-height:1.15}p,li{font-size:28px;line-height:1.5}li{margin-bottom:15px}p.big{font-size:53px;color:#13756c}.flow{font-size:29px;color:#13756c}table{width:100%;border-collapse:collapse;font-size:25px}td,th{text-align:left;padding:15px 10px;border-bottom:1px solid #c7d3d7}th{color:#13756c}.code{font-family:monospace;font-size:24px}.evidence{max-width:1120px;height:470px;object-fit:contain;object-position:left top}aside{display:none}body.notes aside{display:block;position:absolute;bottom:44px;left:76px;right:76px;font-size:18px;background:#e3eeeb;padding:12px}.page{position:absolute;bottom:22px;right:40px;color:#536c75;font-size:17px}nav{position:fixed;bottom:8px;left:12px;color:white;font-size:14px}button{padding:6px 10px;margin-right:7px} @media print{@page{size:1280px 720px;margin:0}body{background:white}section,section.active{display:block;page-break-after:always}section:last-of-type{page-break-after:auto}nav,aside{display:none!important}}'''
    body=''.join('<section'+(' class="active"' if i==0 else '')+'><h1>'+escape(title)+'</h1>'+content+'<aside>'+escape(note)+'</aside><span class="page">'+str(i+1)+' / '+str(len(slides))+'</span></section>' for i,(title,content,note) in enumerate(slides))
    js="let n=0;const slides=[...document.querySelectorAll('section')];function show(i){n=Math.max(0,Math.min(slides.length-1,i));slides.forEach((s,j)=>s.classList.toggle('active',j===n));}addEventListener('keydown',e=>{if(['ArrowRight','PageDown',' '].includes(e.key)){e.preventDefault();show(n+1)}if(['ArrowLeft','PageUp'].includes(e.key))show(n-1);if(e.key.toLowerCase()==='n')document.body.classList.toggle('notes')});"
    (ARTIFACTS/'presentation.html').write_text('<!doctype html><html lang="en"><meta charset="utf-8"><title>VETO Final Presentation</title><style>'+css+'</style>'+body+'<nav><button onclick="show(n-1)">Previous</button><button onclick="show(n+1)">Next</button>Arrow keys: slides | N: speaker notes | Print: PDF</nav><script>'+js+'</script></html>',encoding='utf-8')
    write('SLIDE_NOTES.md','\n\n'.join('## '+str(i+1)+'. '+title+'\n\n'+note for i,(title,_,note) in enumerate(slides)))
    write('README.md',f'''# VETO final submission package

Generated PDFs, HTML slides, screenshots and video are in the ignored
repository-root Evaluations/submission/ folder. FINAL_REPORT.md here is editable
report source intended for Git. The archive is in Evaluations/packages/.
Compact results are versioned under veto/evaluation/results/.
The HTML deck is editable and self-contained; a PowerPoint file is not generated
because the prescribed presentation authoring runtime is unavailable here.

Measured run: ../../Evaluations/runs/{run_name}/metrics.json and metrics.csv.
Ground truth, raw results, report pairs, frozen sources and cleanup proof are
stored beside those measurements. Screenshots and the backup WebM are local
evidence captures, not invented mockups. The backup has no narration.

Read COMPLETION.md for the distinction between delivered work and the remaining
human attestations. CONTRIBUTIONS.md requires actual member-provided roles.
REPRODUCIBILITY.md and DEMO_AND_VIVA.md support handoff and academic defense.
The package is prepared locally; nothing has been submitted to an institution.
Restore Evaluations/ from the local archive or create a new run after a fresh clone.
''')
    print(json.dumps({'report':str(ARTIFACTS/'VETO_FINAL_REPORT.pdf'),'slides':len(slides),'valid_experiment':True}))


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--run',default='final-v1')
    main(parser.parse_args().run)
