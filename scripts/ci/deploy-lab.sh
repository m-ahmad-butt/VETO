#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEPLOY_DIR="${DEPLOY_DIR:-/opt/veto-lab}"
WEB_SRC="$ROOT/web"

if [ -z "${PROD_ENV:-}" ]; then
  echo "Missing CI variable PROD_ENV (Type: File)." >&2
  exit 1
fi

sudo apt-get update -qq
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq unzip curl ca-certificates rsync

sudo mkdir -p "$DEPLOY_DIR"
sudo chown gitlab-runner:gitlab-runner "$DEPLOY_DIR"

if [ -d "$DEPLOY_DIR/original/wp-content" ]; then
  sudo chown -R gitlab-runner:gitlab-runner "$DEPLOY_DIR/original/wp-content" || true
fi

rsync -a --delete \
  --exclude '.env' \
  --exclude '.git' \
  --exclude 'logs/' \
  --exclude 'original/wp-content/uploads/' \
  --exclude 'original/wp-content/cache/' \
  --exclude 'original/wp-content/upgrade/' \
  --exclude 'original/wp-content/upgrade-temp-backup/' \
  --exclude 'terraform/' \
  "$WEB_SRC/" "$DEPLOY_DIR/"

mkdir -p "$DEPLOY_DIR/scripts"
cp -f "$ROOT/scripts/ci/overlay-env-from-ci.sh" "$DEPLOY_DIR/scripts/overlay-env-from-ci.sh"
chmod +x "$DEPLOY_DIR/scripts/"*.sh || true

if [ -e "$DEPLOY_DIR/.env" ]; then
  sudo chown gitlab-runner:gitlab-runner "$DEPLOY_DIR/.env"
  chmod u+rw "$DEPLOY_DIR/.env" || true
fi

cp "$PROD_ENV" "$DEPLOY_DIR/.env"
bash "$DEPLOY_DIR/scripts/overlay-env-from-ci.sh" "$DEPLOY_DIR/.env" || echo "overlay-env-from-ci skipped"
chmod 640 "$DEPLOY_DIR/.env"
sudo chown gitlab-runner:ubuntu "$DEPLOY_DIR/.env" || sudo chown gitlab-runner:gitlab-runner "$DEPLOY_DIR/.env"

if [ -d "$DEPLOY_DIR/original/wp-content" ]; then
  sudo chown -R 33:33 "$DEPLOY_DIR/original/wp-content"
fi

cd "$DEPLOY_DIR"
docker compose -f docker-compose-prod.yml up -d --build
docker compose -f docker-compose-prod.yml ps
