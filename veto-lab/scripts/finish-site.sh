#!/usr/bin/env bash
set -euo pipefail
ROLE="${1:?role}"
export WP_CLI_CACHE_DIR=/tmp/wp-cli-cache
mkdir -p "$WP_CLI_CACHE_DIR"

wp() {
  php -d memory_limit=512M /usr/local/bin/wp "$@"
}

# Light operations: skip loading Elementor/Woo/Rank Math
wp theme activate hello-elementor --skip-plugins --allow-root || true

for p in elementor seo-by-rank-math woocommerce wpforms-lite wp-mail-smtp google-site-kit akismet redis-cache; do
  if wp plugin is-installed "$p" --skip-plugins --skip-themes --allow-root 2>/dev/null; then
    # activate one at a time with high memory
    wp plugin activate "$p" --allow-root && echo "active: $p" || echo "activate-fail: $p"
  else
    echo "missing: $p"
  fi
done

create_if_missing() {
  local title="$1" slug="$2"
  local id
  id=$(wp post list --post_type=page --name="$slug" --field=ID --format=ids --skip-plugins --skip-themes --allow-root 2>/dev/null | awk '{print $1}')
  if [[ -z "${id:-}" ]]; then
    wp post create --post_type=page --post_title="$title" --post_name="$slug" --post_status=publish \
      --post_content="<p>${title} — Lumina Research Supply (VETO dummy).</p>" \
      --skip-plugins --skip-themes --allow-root >/dev/null
    echo "created /$slug"
  else
    echo "exists /$slug"
  fi
}

create_if_missing "Home" "home"
create_if_missing "Products" "products"
create_if_missing "COA" "coa"
create_if_missing "Contact" "contact"
create_if_missing "Shipping" "shipping"
create_if_missing "Terms" "terms"
create_if_missing "Refund Policy" "refund-policy"

HOME_ID=$(wp post list --post_type=page --name=home --field=ID --format=ids --skip-plugins --skip-themes --allow-root | awk '{print $1}')
wp option update show_on_front page --skip-plugins --skip-themes --allow-root
wp option update page_on_front "$HOME_ID" --skip-plugins --skip-themes --allow-root
wp option update blogname "Lumina Research Supply" --skip-plugins --skip-themes --allow-root
wp option update blogdescription "Research-grade compounds for laboratory use only." --skip-plugins --skip-themes --allow-root
wp option update veto_site_role "$ROLE" --skip-plugins --skip-themes --allow-root

HW=$(wp post list --post_type=post --name=hello-world --field=ID --format=ids --skip-plugins --skip-themes --allow-root 2>/dev/null | awk '{print $1}')
[[ -n "${HW:-}" ]] && wp post delete "$HW" --force --skip-plugins --skip-themes --allow-root || true

wp rewrite structure '/%postname%/' --skip-plugins --skip-themes --allow-root || true
wp rewrite flush --skip-plugins --skip-themes --allow-root || true

echo "DONE role=$ROLE front_page=$HOME_ID"
wp plugin list --status=active --fields=name,status --skip-themes --allow-root
