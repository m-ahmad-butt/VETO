# VETO: Adaptive Deception Firewall for WordPress
**Detect. Deceive. Isolate. Protect.**

## Motivation

The idea comes from a real incident. My school friend called me and told me that his client WordPress site was under attack. After roughly **three hours** of diagnosis and cleanup, found the cause was a **fake / malicious plugin** that had effectively destroyed the legitimate application surface through **SEO-spam injection**.

That plugin was associated with spam-SEO behavior under a suspicious name (`dmzktif`). The site no longer presented the intended product experience; injected foreign content and broken assets dominated the page. 

**Example of the compromised surface (SEO spam overlay):**

![SEO-spam compromise on a client WordPress storefront — injected foreign content and broken assets](veto-lab/files/image.png)

VETO aims to shorten that investigation loop: inspect suspicious plugins, preserve evidence, deceive attackers with a decoy, and give administrators a clearer incident timeline.


| | |
|---|---|
| **Course** | CS3002 - Information Security |
| **Institution** | National University of Computer and Emerging Sciences (FAST-NUCES), Lahore |
| **Department** | Computer Science |
| **Project Advisor** | Sarah Abid Khan |

## Group Members

| Name | Roll Number |
|------|-------------|
| Muhammad Ahmad Butt | 23L-3059 |
| Ali Ahmed | 23L-3067 |
| Muhammad Zain Tahir | 23L-3078 |

---
## Main Products

| Component | Objective |
|-----------|-----------|
| **VETO Shield** | Inspect HTTP requests with configurable rules, signatures, rate patterns, and risk scoring. |
| **VETO Mirage** | Route selected suspicious sessions to a decoy WordPress site via reverse-proxy routing (visible domain/URL can remain unchanged). |
| **VETO Plugin Scanner** | Analyze plugin ZIPs for signatures, suspicious PHP patterns, obfuscation, risky functions, and package anomalies. |
| **VETO Sandbox** | Execute a copy of an untrusted plugin in an isolated disposable WordPress environment; record file/DB changes, accounts, cron, and outbound attempts. |
| **VETO Sentinel** | Monitor production for file-integrity changes, unexpected admins, suspicious scheduled jobs, and SEO-spam / content modifications. |
| **VETO Containment** | Alert administrators and support controlled actions (e.g. disable / quarantine after confirmation or policy). |
| **VETO Forensics** | Maintain a timestamped incident timeline with evidence, risk indicators, response actions, and exportable reports. |

---

## License / academic use

Prepared for **CS3002 Information Security** at FAST-NUCES Lahore. Lab assets and screenshots are for authorized coursework and demonstration only.
