# VETO: WP Spectra

**Static and Dynamic Security Analysis of WordPress Plugins**

**Scan. Observe. Report.**

WordPress sites rely on third party plugins for functionality. A malicious, compromised, or vulnerable plugin can inject spam links, alter content, create unauthorized accounts, or establish persistence. Some of that behavior only becomes visible when the plugin runs.

## Motivation

The idea comes from a real incident. My school friend called me and told me that his client WordPress site was under attack. After roughly **three hours** of diagnosis and cleanup, found the cause was a **fake / malicious plugin** that had effectively destroyed the legitimate application surface through **SEO-spam injection

**Example of the compromised surface (SEO spam overlay):**

![SEO spam compromise on a client WordPress storefront, injected foreign content and broken assets](web/files/image.png)

VETO aims to shorten that investigation loop: inspect the plugin package, observe runtime behavior in isolation, and give administrators a structured evidence report before trusting the plugin on production.



| | |
|---|---|
| **Course** | CS3002 Information Security |
| **Institution** | National University of Computer and Emerging Sciences (FAST NUCES), Lahore |
| **Department** | Computer Science |
| **Project Advisor** | Sarah Abid Khan |

## Group Members

| Name | Roll Number |
|------|-------------|
| Muhammad Ahmad Butt | 23L-3059 |
| Ali Ahmed | 23L-3067 |
| Muhammad Zain Tahir | 23L-3078 |

## Goals

| Pillar | Objective |
|--------|-----------|
| **Scan** | Static analysis of submitted plugin ZIPs: known signatures, suspicious PHP patterns, obfuscation indicators, risky functions, and package anomalies, before execution. |
| **Observe** | Dynamic analysis in an isolated disposable WordPress environment: file and DB changes, account creation, WP Cron changes, and outbound network attempts. |
| **Report** | Timestamped security report combining static findings and runtime events: evidence, affected files, event timeline, and risk summary (review and export). |

## Scope

**In scope**

1. **Static plugin analysis.** Inspect packages for signatures, obfuscation, risky PHP, and package anomalies.
2. **Dynamic behavioral analysis.** Run a plugin copy in a Docker based WordPress sandbox; monitor selected runtime activities against a baseline.
3. **Evidence based reporting.** Structured report with evidence, affected resources, observed events, and a risk summary.

**Out of scope**

* Real time monitoring of production WordPress sites
* Live HTTP traffic inspection or WAF functionality
* Visitor routing, deception routing, or decoy traffic handling
* Automatic malware removal, cleaning, or remediation

Findings include supporting evidence for administrator review; they are not treated as definitive proof of maliciousness. Signature scanning may miss novel or heavily obfuscated threats; sandbox behavior may differ from production; legitimate plugins may trigger false positives.

## Proposed Workflow

1. **Plugin submission.** Administrator submits a plugin ZIP (or a copy of an installed plugin).
2. **Static analysis.** Unpack; YARA rules, SHA 256 checks, PHP pattern checks; record findings before execution.
3. **Sandbox initialization.** Fresh disposable WordPress via Docker; baseline files and database; restricted privileges, resource limits, controlled network.
4. **Controlled execution.** Install and activate a copy; run a predefined test sequence (frontend pages, admin actions, cron where applicable) for a fixed observation window.
5. **Behavioral monitoring.** File create, modify, and delete; DB changes; unexpected users or admins; WP Cron changes; outbound connection attempts; compare to baseline.
6. **Report and cleanup.** Combine static and dynamic findings into a timestamped report; destroy the disposable sandbox.

## Expected Outcomes

| Module | Description |
|--------|-------------|
| **Static Analysis** | Scanner for signatures, PHP patterns, obfuscation indicators, and risky functions. |
| **Dynamic Analysis Sandbox** | Disposable WordPress environment that runs a submitted plugin copy. |
| **Behavioral Monitoring** | Records file, DB, account, cron, and outbound network events. |
| **Evidence Report** | Exportable report: static findings, runtime observations, affected files, timeline, risk summary. |
| **Evaluation** | Tests with benign plugins and controlled cases for coverage, false positives, and event visibility. |

## Related Work (brief)

VETO is not a replacement for existing products. Documented emphases of selected tools include Wordfence Scan (file, malware, and vulnerability scanning), Sucuri Security (malware scanning, hardening, firewall related features), and WPScan (known vulnerabilities in core, plugins, and themes). VETO’s proposed focus is a prototype workflow: accept a plugin package, then static analysis, then isolated execution, then behavioral observation, then a consolidated evidence report.

## CI/CD and infrastructure

| Tool | Role |
|------|------|
| GitLab CI | Root [`.gitlab-ci.yml`](.gitlab-ci.yml) |
| Terraform | AWS `stage` and `prod` VMs under [`infra/terraform/`](infra/terraform/) |
| Ansible | Host setup (Docker, `/opt/veto-lab`) under [`infra/ansible/`](infra/ansible/) |
| Docker Compose | WordPress lab under [`web/`](web/) |
| Scripts | [`scripts/ci/`](scripts/ci/) |

Pipeline on the default branch:

1. `build` then `test` (automatic)
2. `terraform_plan` / `terraform_apply` (manual)
3. `ansible_configure_stage` / `ansible_configure_prod` (manual)
4. `deploy_stage` (automatic after test; runner tag `veto-lab-stage`)
5. `deploy_prod` (manual after stage; runner tag `veto-lab-prod`)

GitLab CI/CD variables: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `PROD_ENV` (File), `ANSIBLE_SSH_KEY` or `SSH_PRIVATE_KEY_FILE`.

## License / academic use

Prepared for **CS3002 Information Security** at FAST NUCES Lahore. Lab assets and screenshots are for authorized coursework and demonstration only. Analysis is intended for self hosted test environments where the owner has authorized security testing.
