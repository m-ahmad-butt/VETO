#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

test -f web/docker-compose.yml
test -f web/docker-compose-prod.yml
test -d web/original
test -f scripts/ci/deploy-lab.sh
test -f scripts/ci/deploy-k8s.sh
test -f scripts/ci/validate-compose.sh
test -f deploy/k8s/namespace.yaml
test -d deploy/k8s/lab
test -d deploy/k8s/monitoring
bash scripts/ci/validate-compose.sh
echo "test-lab: ok"
