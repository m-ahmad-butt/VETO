#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
bash ./install-docker.sh

if [[ ! -f .env ]]; then
  umask 077
  printf 'WP_DB_PASSWORD=%s\nWP_DB_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 24)" "$(openssl rand -hex 24)" > .env
fi
sudo docker compose --env-file .env -f wordpress.compose.yml up -d
sudo docker compose --env-file .env -f wordpress.compose.yml ps
echo 'Open the WordPress public DNS URL and complete its installer in the browser.'
