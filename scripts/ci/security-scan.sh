#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

if ! command -v trivy >/dev/null 2>&1; then
  curl -sfL https://raw.githubusercontent.com/aquasecurity/trivy/main/contrib/install.sh | sh -s -- -b /usr/local/bin
fi

trivy fs --severity HIGH,CRITICAL --exit-code 0 "$ROOT/web"
trivy fs --severity HIGH,CRITICAL --exit-code 0 "$ROOT/deploy"
echo "security-scan: ok"
