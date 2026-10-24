#!/usr/bin/env bash
# Copy ExamSlot public assets (/notion/*, /logo.png) into WP docroot so Apache
# serves them at the absolute paths the SPA expects.
set -euo pipefail
SITE="${1:?original-wordpress|decoy-wordpress}"
docker compose exec -u root "$SITE" bash -lc '
  set -e
  mkdir -p /var/www/html/notion
  cp -a /var/www/html/wp-content/themes/cega/app/notion/. /var/www/html/notion/
  cp -f /var/www/html/wp-content/themes/cega/app/logo.png /var/www/html/logo.png
  chown -R www-data:www-data /var/www/html/notion /var/www/html/logo.png
  echo "synced public assets into '"$SITE"'"
'
