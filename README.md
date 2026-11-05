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

## Architecture (IS lab + DevOps)

```text
Internet → AWS ALB → ASG (EC2) → k3s (Kubernetes)
                              → WordPress (veto-lab)
                              → Prometheus + Grafana (monitoring)
GitLab CI → Terraform (AWS) → Ansible (Docker, Runner, k3s) → deploy manifests
```

| Layer | Technology |
|-------|------------|
| Edge / scale | AWS ALB + Auto Scaling Group (CPU target tracking) |
| Nodes | EC2 in ASG (stage + prod) |
| Orchestration | k3s (Kubernetes) + ingress-nginx NodePort 30080 |
| App | WordPress + MySQL in `deploy/k8s/lab` |
| Observe | Prometheus + Grafana in `deploy/k8s/monitoring` |
| IaC | Terraform under `infra/terraform` |
| Config | Ansible under `infra/ansible` |
| CI/CD | GitLab CI (`.gitlab-ci.yml`) |

Local Compose under `web/` remains for laptop/dev. Stage/prod path is **Kubernetes via ALB**.

## CI/CD pipeline

1. `build` → `test` → `security` (Trivy) automatic  
2. `terraform_plan` / `terraform_apply` manual  
3. `ansible_configure_stage` / `ansible_configure_prod` manual  
4. `deploy_stage` automatic (k8s apply; tag `veto-lab-stage`)  
5. `deploy_prod` manual (tag `veto-lab-prod`)  
6. `drift` manual or scheduled terraform plan  

## What you need to set up

### 1. Accounts and CLI

- AWS account with rights to EC2, ELB, ASG, IAM key pair, S3  
- AWS access key for GitLab CI and/or local Terraform  
- GitLab project `m-ahmad-butt/VETO`  
- Terraform installed locally (optional if only CI apply)  
- SSH client  

### 2. GitLab CI/CD variables

| Variable | Type | Purpose |
|----------|------|---------|
| `AWS_ACCESS_KEY_ID` | Variable | Terraform |
| `AWS_SECRET_ACCESS_KEY` | Variable (masked) | Terraform |
| `ANSIBLE_SSH_KEY` or `SSH_PRIVATE_KEY_FILE` | File or variable | PEM for Ansible SSH |
| `PROD_ENV` | File | Optional leftover for Compose path |

### 3. First-time cloud bring-up

1. Push `main`  
2. Pipeline → manual **terraform_plan** → **terraform_apply**  
3. Wait for ASG instances (1 per env). Note outputs: `stage_ip`, `prod_ip`, `stage_alb_dns`, `prod_alb_dns`  
4. Manual **ansible_configure_stage** then **ansible_configure_prod** (Docker + Runner package + k3s)  
5. SSH each node and **register** GitLab Runner:

```bash
sudo gitlab-runner register
```

- URL: `https://gitlab.com/`  
- Token: GitLab → Settings → CI/CD → Runners  
- Tags: `veto-lab-stage` or `veto-lab-prod`  
- Executor: `shell`  

6. Push again (or retry deploy). **deploy_stage** applies `deploy/k8s`.  
7. Open `http://<stage_alb_dns>/` for WordPress; Grafana path `/grafana` (change default password).  

### 4. Local secrets to change

- `deploy/k8s/lab/wordpress.yaml` MySQL passwords  
- `deploy/k8s/monitoring/grafana.yaml` admin password  

### 5. Costs / safety

- Two ASGs (min 1) + two ALBs incur AWS cost; stop/destroy when idle: `terraform destroy`  
- Do not commit `*.pem` or `*.tfstate`  
- Public images may be pulled; nothing is pushed to your Docker Hub  

## Goals (Information Security)

| Pillar | Objective |
|--------|-----------|
| **Scan** | Static analysis of plugin ZIPs |
| **Observe** | Dynamic analysis in isolated WordPress |
| **Report** | Evidence based security report |

## License / academic use

Prepared for **CS3002 Information Security** at FAST NUCES Lahore. Lab assets and screenshots are for authorized coursework and demonstration only.
