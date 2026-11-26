#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
bash ./install-docker.sh
sudo apt-get install -y python3 python3-venv python3-pip

app_dir="$HOME/veto"
if [[ ! -f "$app_dir/requirements-lock.txt" ]]; then
  echo "Copy the veto/ source directory to $app_dir before running this script." >&2
  exit 1
fi
python3 -m venv "$app_dir/.venv"
"$app_dir/.venv/bin/python" -m pip install -r "$app_dir/requirements-lock.txt"
sudo docker pull wordpress:6.8-php8.2-apache
sudo docker pull mysql:8.0
sudo docker pull wordpress:cli-php8.3
sudo docker build -t veto-sandbox:phase2 "$app_dir/sandbox"
sudo usermod -aG docker ubuntu

sudo tee /etc/systemd/system/veto.service >/dev/null <<EOF
[Unit]
Description=VETO analysis dashboard
After=network-online.target docker.service
Wants=network-online.target
Requires=docker.service

[Service]
Type=simple
User=ubuntu
Group=ubuntu
SupplementaryGroups=docker
WorkingDirectory=$app_dir
ExecStart=$app_dir/.venv/bin/python -m wp_spectra serve --port 8765
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload
sudo systemctl enable --now veto.service
sudo systemctl status veto.service --no-pager
echo 'VETO is running on 127.0.0.1:8765. Run publish-veto.sh to reach it through the EC2 hostname.'
