# Two-server AWS demo

Terraform creates two Ubuntu 24.04 EC2 instances in the account's default VPC:

- **WordPress:** public HTTP on port 80; SSH from your `/32` address.
- **VETO:** HTTP and SSH from your `/32` address. Nginx serves the dashboard on the EC2 public DNS name and proxies to VETO on `127.0.0.1:8765`. The Docker analysis sandbox runs on this server, separate from WordPress.

The default sizes are `m7i-flex.large` for WordPress and `t3.small` for VETO, with 30 and 50 GiB encrypted gp3 volumes. Both sizes appeared as free-tier-eligible for this account in `ap-south-1` on 2026-11-26; eligibility does not guarantee zero cost, and instances, storage, and public IPv4 addresses can incur charges or consume credits. **The 2 GiB VETO server may run the dashboard and static scans, but full Docker sandbox analysis can fail for lack of memory.** For the full demo, set `veto_instance_type = "m7i-flex.large"` in `terraform.tfvars` before applying. The demo uses the EC2 public DNS names, not a purchased domain. Both sites use plain HTTP for this short demonstration; do not use real accounts or data. VETO has no login, so port 80 is restricted to your current public IP.

## 1. Set local variables in PowerShell

Run from the project root. You need AWS CLI credentials, Terraform, OpenSSH, and an existing EC2 key pair in the chosen region. Set the path to the matching private key on your computer.

```powershell
cd C:\Users\ahmad\Downloads\sem7\IS_PROJECT
aws sts get-caller-identity
$KeyPath = "C:\path\to\your-key.pem"
$MyIp = (Invoke-RestMethod 'https://checkip.amazonaws.com').Trim()
Copy-Item .\infra\terraform.tfvars.example .\infra\terraform.tfvars
notepad .\infra\terraform.tfvars
```

In `terraform.tfvars`, set `aws_region`, `key_name`, and `admin_cidr` to your values. Use `$MyIp/32` for `admin_cidr` (for example `203.0.113.10/32`). The selected region must have a default VPC and default subnet. If your public IP changes, update the `/32` and reapply before reconnecting.

```powershell
terraform -chdir=infra init
terraform -chdir=infra plan
terraform -chdir=infra apply
$WpHost = terraform -chdir=infra output -raw wordpress_public_dns
$VetoHost = terraform -chdir=infra output -raw veto_public_dns
$WpHost
$VetoHost
```

## 2. Deploy WordPress

```powershell
ssh -i $KeyPath "ubuntu@$WpHost" 'mkdir -p ~/infra'
scp -i $KeyPath .\infra\install-docker.sh .\infra\deploy-wordpress.sh .\infra\wordpress.compose.yml "ubuntu@${WpHost}:~/infra/"
ssh -i $KeyPath "ubuntu@$WpHost" 'bash ~/infra/deploy-wordpress.sh'
```

Open `http://<wordpress_public_dns>` in your browser and finish the WordPress installer with **demo-only credentials**. The database password is generated on the server in `~/infra/.env`. Wait a minute if MySQL is still starting. To inspect service logs:

```powershell
ssh -i $KeyPath "ubuntu@$WpHost" 'cd ~/infra && sudo docker compose --env-file .env -f wordpress.compose.yml logs --tail=80'
```

## 3. Deploy VETO

The archive command includes the current working-tree changes and excludes the local virtual environment and job data. Upload it with the deployment scripts.

```powershell
tar -czf .\infra\veto-source.tar.gz --exclude=.venv --exclude=.local --exclude=output -C .\veto .
ssh -i $KeyPath "ubuntu@$VetoHost" 'mkdir -p ~/infra ~/veto'
scp -i $KeyPath .\infra\install-docker.sh .\infra\deploy-veto.sh .\infra\veto-source.tar.gz "ubuntu@${VetoHost}:~/infra/"
ssh -i $KeyPath "ubuntu@$VetoHost" 'tar -xzf ~/infra/veto-source.tar.gz -C ~/veto && bash ~/infra/deploy-veto.sh'
scp -i $KeyPath .\infra\publish-veto.sh "ubuntu@${VetoHost}:~/infra/"
ssh -i $KeyPath "ubuntu@$VetoHost" "bash ~/infra/publish-veto.sh $VetoHost"
```

This installs Docker Engine, Python dependencies, and the three images used by VETO's sandbox, builds `veto-sandbox:phase2`, and starts a systemd service. It can take several minutes. Check the service:

```powershell
ssh -i $KeyPath "ubuntu@$VetoHost" 'sudo systemctl status veto --no-pager && sudo journalctl -u veto -n 80 --no-pager'
```

Open `http://<veto_public_dns>` from the permitted IP. No SSH tunnel is needed. Submit both ZIPs in **static + sandbox** mode:

- `veto/fixtures/demo_plugins/veto-hijack-static.zip` should have a medium `PHP010` static indicator and runtime front-page database changes.
- `veto/fixtures/demo_plugins/veto-hijack-runtime.zip` should have no `PHP010` static indicator, but runtime evidence should show the new page and changed `show_on_front` / `page_on_front` options.

The runtime fixture demonstrates a static blind spot. VETO reports evidence for review; it does not automatically approve or deploy plugins. Both ZIPs are controlled fake-prize demonstrations with no external scam destination or payment collection. To show the visible change on the public WordPress host, manually install **one** ZIP through **Plugins → Add New → Upload Plugin** after reviewing its VETO report. Deactivate it to restore the previous homepage. The WordPress server is independent of VETO's disposable sandbox.

The older `veto-homepage-hijack.zip` and `veto-hello-world.zip` fixtures remain available. To show the safe comparison, upload `veto-hello-world.zip` to VETO or WordPress; it adds `/veto-demo-hello-world/` without replacing the homepage.

## SSH, cost, and cleanup

Interactive SSH commands:

```powershell
ssh -i $KeyPath "ubuntu@$WpHost"
ssh -i $KeyPath "ubuntu@$VetoHost"
```

EC2 public DNS can change after stop/start because this setup does not allocate Elastic IPs. Refresh the Terraform outputs after a restart. WordPress stores its original site URL, so a new hostname may require updating `home` and `siteurl` or recreating this disposable demo. The Terraform state is local to `infra/`; keep it private because it contains infrastructure details.

When finished:

```powershell
terraform -chdir=infra destroy
```

Destroy terminates both instances and their root volumes, including the demo WordPress content and VETO reports.
