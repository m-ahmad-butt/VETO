#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

docker compose -f web/docker-compose.yml config >/dev/null
docker compose -f web/docker-compose-prod.yml config >/dev/null
echo "validate-compose: ok"
