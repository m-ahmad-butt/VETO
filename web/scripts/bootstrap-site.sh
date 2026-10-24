#!/usr/bin/env bash
# Bootstrap one WordPress site (original or decoy) with Top-Peak-like plugin stack.
set -euo pipefail

ROLE="${1:?role}"
URL="${2:?url}"
ADMIN_USER="${3:?user}"
ADMIN_PASS="${4:?pass}"
ADMIN_EMAIL="${5:?email}"

SITE_TITLE="Lumina Research Supply"
TAGLINE="Research-grade compounds for laboratory use only."
export WP_CLI_CACHE_DIR=/tmp/wp-cli-cache
mkdir -p "$WP_CLI_CACHE_DIR"

echo "==> Waiting for WordPress filesystem ($ROLE)..."
for i in $(seq 1 90); do
  if [[ -f /var/www/html/wp-load.php ]]; then
    break
  fi
  sleep 2
done

if wp core is-installed --allow-root 2>/dev/null; then
  echo "WordPress already installed."
else
  echo "==> Installing WordPress at $URL..."
  wp core install \
    --url="$URL" \
    --title="$SITE_TITLE" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASS" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email \
    --allow-root
fi

wp option update blogdescription "$TAGLINE" --allow-root
wp option update timezone_string "Asia/Karachi" --allow-root
wp rewrite structure '/%postname%/' --hard --allow-root

echo "==> Theme (Elementor-friendly)..."
wp theme install hello-elementor --activate --force --allow-root

echo "==> Plugins (Top-Peak-like free stack)..."
# Pin WooCommerce to a release compatible with WP 6.8 if core < 7.0
WP_VER=$(wp core version --allow-root)
echo "WordPress version: $WP_VER"

install_plugin() {
  local slug="$1"
  local extra="${2:-}"
  if wp plugin is-installed "$slug" --allow-root 2>/dev/null; then
    wp plugin activate "$slug" --allow-root || true
  else
    # shellcheck disable=SC2086
    wp plugin install "$slug" $extra --activate --force --allow-root
  fi
}

install_plugin elementor
install_plugin seo-by-rank-math
if wp core version --allow-root | grep -Eq '^6\.'; then
  install_plugin woocommerce "--version=9.8.5"
else
  install_plugin woocommerce
fi
install_plugin wpforms-lite
install_plugin wp-mail-smtp
install_plugin google-site-kit
install_plugin akismet
install_plugin redis-cache

wp plugin delete hello 2>/dev/null || true

wp option update woocommerce_store_address "100 Lab Avenue" --allow-root || true
wp option update woocommerce_store_city "Lahore" --allow-root || true
wp option update woocommerce_default_country "PK" --allow-root || true
wp option update woocommerce_currency "USD" --allow-root || true

echo "==> Dummy pages (Top-Peak-like IA)..."
ensure_page() {
  local title="$1"
  local slug="$2"
  local content="$3"
  local id
  id=$(wp post list --post_type=page --name="$slug" --field=ID --format=ids --allow-root 2>/dev/null | awk '{print $1}')
  if [[ -n "${id:-}" ]]; then
    echo "  /$slug -> $id"
  else
    id=$(wp post create \
      --post_type=page \
      --post_title="$title" \
      --post_name="$slug" \
      --post_status=publish \
      --post_content="$content" \
      --porcelain \
      --allow-root)
    echo "  /$slug created -> $id"
  fi
}

ensure_page "Home" "home" "<p>Welcome to Lumina Research Supply. Elementor-ready homepage for the VETO lab.</p>"
ensure_page "Products" "products" "<p>Browse research compounds. Powered by WooCommerce.</p>"
ensure_page "COA" "coa" "<p>Certificates of Analysis (dummy lab page).</p>"
ensure_page "Contact" "contact" "<p>Contact: lab@lumina-research.example</p>"
ensure_page "Shipping" "shipping" "<p>Dummy shipping policy.</p>"
ensure_page "Terms" "terms" "<p>Dummy terms of service.</p>"
ensure_page "Refund Policy" "refund-policy" "<p>Dummy refund policy.</p>"

HOME_ID=$(wp post list --post_type=page --name=home --field=ID --format=ids --allow-root | awk '{print $1}')
if [[ -n "${HOME_ID:-}" ]]; then
  wp option update show_on_front page --allow-root
  wp option update page_on_front "$HOME_ID" --allow-root
fi

wp option update veto_site_role "$ROLE" --allow-root
wp option update veto_lab_note "Dummy WordPress for VETO IS project — not a client production site." --allow-root

# Sample product without relying on wc REST (avoids hang if Woo wizard incomplete)
if ! wp post list --post_type=product --name=demo-compound-a --field=ID --format=ids --allow-root 2>/dev/null | grep -q .; then
  PROD_ID=$(wp post create \
    --post_type=product \
    --post_title="Demo Compound A" \
    --post_name="demo-compound-a" \
    --post_status=publish \
    --post_content="Dummy WooCommerce product for VETO lab." \
    --porcelain \
    --allow-root)
  wp post meta update "$PROD_ID" _regular_price 49 --allow-root || true
  wp post meta update "$PROD_ID" _price 49 --allow-root || true
  echo "  product demo-compound-a -> $PROD_ID"
fi

echo "==> Done: $ROLE @ $URL (admin: $ADMIN_USER / set in .env)"
wp plugin list --status=active --allow-root
wp theme list --status=active --allow-root
wp option get veto_site_role --allow-root
