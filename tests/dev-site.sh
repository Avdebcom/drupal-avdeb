#!/usr/bin/env bash
# Throw-away Drupal 11 site (Docker, SQLite) with this module mounted read-only and enabled.
#   tests/dev-site.sh [container-name] [port]      → http://localhost:<port>  (admin / admin)
# Run drush as the web user so the SQLite file stays writable:
#   docker exec -u www-data <container-name> vendor/bin/drush …
# Remove it with: docker rm -f <container-name>
set -euo pipefail
NAME="${1:-avdeb-drupal}"
PORT="${2:-8090}"
MODULE="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE="${AVDEB_DRUPAL_IMAGE:-drupal:11-apache}"

docker rm -f "$NAME" >/dev/null 2>&1 || true
# Apache starts only after the install, so the port refuses connections until the site is ready.
docker run -d --name "$NAME" -p "$PORT:80" -v "$MODULE:/opt/drupal/web/modules/contrib/avdeb:ro" "$IMAGE" sleep infinity >/dev/null
docker exec "$NAME" bash -c '
  set -e
  cd /opt/drupal
  [ -x vendor/bin/drush ] || composer require drush/drush --no-interaction --quiet
  vendor/bin/drush site:install standard --db-url=sqlite://localhost/sites/default/files/.ht.sqlite \
    --site-name="Maker Notes" --account-name=admin --account-pass=admin --yes --quiet
  # Drupal 11.4+ ships the Article and Basic page content types as recipes.
  for r in article_content_type page_content_type; do
    [ -d web/core/recipes/$r ] && (cd web && ../vendor/bin/drush recipe core/recipes/$r --quiet)
  done
  vendor/bin/drush pm:install avdeb --yes --quiet
  vendor/bin/drush cache:rebuild --quiet
  chown -R www-data:www-data web/sites/default/files
'
docker exec -d "$NAME" apache2-foreground
for _ in $(seq 1 60); do
  curl -s -o /dev/null "http://localhost:$PORT/user/login" && break
  sleep 0.5
done
echo "Drupal ready: http://localhost:$PORT (admin / admin)"
