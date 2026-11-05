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

## Architecture

We just used AWS, Docker, and k3s so the lab can run on EC2. We also used Grafana and Prometheus, stage and prod servers, ALB, ASG, Trivy, Terraform, Ansible, and GitLab CI. Public images can be pulled; we do not push images to Docker Hub.

## Goals (Information Security)

| Pillar | Objective |
|--------|-----------|
| **Scan** | Static analysis of plugin ZIPs |
| **Observe** | Dynamic analysis in isolated WordPress |
| **Report** | Evidence based security report |

## License / academic use

Prepared for **CS3002 Information Security** at FAST NUCES Lahore. Lab assets and screenshots are for authorized coursework and demonstration only.
