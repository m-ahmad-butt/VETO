#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT/web"

docker compose -f docker-compose-prod.yml pull || true
docker compose -f docker-compose-prod.yml build
echo "build-lab: ok"
