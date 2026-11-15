# VETO: WP Spectra

**Static and Dynamic Security Analysis of WordPress Plugins**

**Scan. Observe. Report.**

## Local application (Phase 3 complete)

Run `python -m wp_spectra serve` using the existing venv from `veto/`, then open
`http://127.0.0.1:8765`. The dashboard supports ZIP uploads, background static/full
analysis, live progress and persistent history. See the
[startup and workflow guide](veto/Readme.md). Docker is needed only for full mode.

Investigation views now include located static findings, runtime before/after
evidence, timeline filters, a versioned review policy, clean-control comparison,
and explicit coverage/limitations. Restart an already running server to load new
routes; existing saved analyses remain inspectable. Matching immutable PDF/JSON
reports are available under **Saved investigation reports**, including incomplete
outcomes and optional clean controls. Exports survive restart and do not rerun
plugins. Final detection-effectiveness evaluation and academic delivery remain
in Phase 4.

## Phase 1 implementation

The local static analyzer is implemented in [`veto/`](veto/Readme.md), with safe ZIP
intake, YARA/PHP checks, hashes, structured evidence, fixtures, and acceptance tests.
See the [setup and CLI guide](veto/Readme.md) and
[completion record](veto/docs/PHASE1_COMPLETION.md). It runs independently of the
legacy WordPress/CEGA lab and does not execute submitted plugins.

## Phase 2 implementation

The explicit local `observe` command now supports disposable WordPress execution,
external behavioral observation, controlled networking, and verified cleanup.
See the [Phase 2 setup and demonstration guide](veto/docs/PHASE2_GUIDE.md).
It uses a separate analysis environment and leaves the existing lab untouched.

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

## Goals (Information Security)

| Pillar | Objective |
|--------|-----------|
| **Scan** | Static analysis of plugin ZIPs |
| **Observe** | Dynamic analysis in isolated WordPress |
| **Report** | Evidence based security report |

## License / academic use

Prepared for **CS3002 Information Security** at FAST NUCES Lahore. Lab assets and screenshots are for authorized coursework and demonstration only.
