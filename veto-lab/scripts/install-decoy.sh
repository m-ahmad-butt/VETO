#!/usr/bin/env bash
set -euo pipefail
export WP_CLI_CACHE_DIR=/tmp/wp-cli-cache
mkdir -p "$WP_CLI_CACHE_DIR"

wp() {
  php -d memory_limit=512M /usr/local/bin/wp "$@"
}

URL="${1:-http://localhost:8082}"
USER="${2:-ahmad}"
PASS="${3:-cega-web}"
EMAIL="${4:-ahmad@veto-lab.local}"

if ! wp core is-installed --skip-plugins --skip-themes --allow-root 2>/dev/null; then
  wp core install \
    --url="$URL" \
    --title="Lumina Research Supply" \
    --admin_user="$USER" \
    --admin_password="$PASS" \
    --admin_email="$EMAIL" \
    --skip-email \
    --skip-plugins --skip-themes \
    --allow-root
fi

bash /scripts/finish-site.sh decoy
