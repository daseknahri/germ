#!/bin/bash
# Idempotent site setup. Runs on every container start:
#   1. wait for WordPress core + the database,
#   2. sync the vendored theme/plugin into wp-content,
#   3. install WordPress once (German), then run the seed (brand, pages, menus, stories) once per SEED_VERSION.
set -u
WP="wp --allow-root --path=/var/www/html"
log(){ echo "[site-init] $*"; }

for i in $(seq 1 120); do
  [ -f /var/www/html/wp-config.php ] && $WP db check >/dev/null 2>&1 && break
  sleep 3
done
$WP db check >/dev/null 2>&1 || { log "database not reachable, giving up"; exit 0; }

log "syncing theme + plugin"
for d in themes/viral-reader themes/lebensecht plugins/automation-hamri; do
  rm -rf "/var/www/html/wp-content/$d"
  mkdir -p "$(dirname /var/www/html/wp-content/$d)"
  cp -r "/opt/site/wp-content/$d" "/var/www/html/wp-content/$d"
done
chown -R www-data:www-data /var/www/html/wp-content

URL="${SITE_URL:-${SERVICE_FQDN_WORDPRESS:-http://localhost}}"
if ! $WP core is-installed >/dev/null 2>&1; then
  log "installing WordPress at $URL"
  $WP core install --url="$URL" --title="Oma Gerda" \
    --admin_user="${WP_ADMIN_USER:-redaktion}" --admin_password="${WP_ADMIN_PASSWORD:?WP_ADMIN_PASSWORD missing}" \
    --admin_email="${WP_ADMIN_EMAIL:-admin@example.com}" --skip-email
  $WP language core install de_DE --activate || log "language pack download failed (site stays English until retried)"
fi

$WP theme activate lebensecht
$WP plugin activate automation-hamri
# Google Site Kit (Analytics + AdSense reports in wp-admin). Installed once from wordpress.org, then kept on the volume.
$WP plugin is-installed google-site-kit || $WP plugin install google-site-kit || log "site kit download failed (retried next start)"
$WP plugin is-installed google-site-kit && $WP plugin activate google-site-kit
$WP eval-file /opt/site/seed/seed.php && log "seed done"
chown -R www-data:www-data /var/www/html/wp-content/uploads 2>/dev/null || true

# One-time image refresh (theme 2.0.3): generate the missing 480px "le-card" sub-size (as WebP) for stories imported
# before it existed. Idempotent via an option flag (set only after a clean finish), runs in the background so it never
# delays Apache, and --only-missing never touches or deletes existing files.
if [ "$($WP option get le_card_sizes_v1 2>/dev/null)" != "1" ]; then
  ( $WP media regenerate --only-missing --yes >/dev/null 2>&1 && $WP option update le_card_sizes_v1 1 >/dev/null 2>&1 \
      && chown -R www-data:www-data /var/www/html/wp-content/uploads && log "card image sizes generated" ) &
fi
