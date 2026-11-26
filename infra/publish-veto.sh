#!/usr/bin/env bash
set -euo pipefail

public_host="${1:?Pass the VETO EC2 public DNS hostname}"
if [[ ! "$public_host" =~ ^ec2-[0-9-]+\.[a-z0-9-]+\.compute\.amazonaws\.com$ ]]; then
  echo 'Expected an EC2 public DNS hostname.' >&2
  exit 1
fi

sudo apt-get update
sudo apt-get install -y nginx
sudo sed -i "s|^ExecStart=.*|ExecStart=/home/ubuntu/veto/.venv/bin/python -m wp_spectra serve --port 8765 --allowed-host $public_host|" /etc/systemd/system/veto.service

sudo tee /etc/nginx/sites-available/veto >/dev/null <<EOF
server {
    listen 80;
    server_name $public_host;
    client_max_body_size 50m;

    location / {
        proxy_pass http://127.0.0.1:8765;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_http_version 1.1;
    }
}
EOF
sudo ln -sfn /etc/nginx/sites-available/veto /etc/nginx/sites-enabled/veto
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl daemon-reload
sudo systemctl restart veto.service
sudo systemctl enable --now nginx
sudo systemctl reload nginx
echo "VETO dashboard: http://$public_host"
