#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

test -f web/docker-compose.yml
test -f web/docker-compose-prod.yml
test -d web/original
test -f scripts/ci/deploy-lab.sh
test -f scripts/ci/validate-compose.sh
bash scripts/ci/validate-compose.sh
echo "test-lab: ok"
