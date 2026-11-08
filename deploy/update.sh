#!/usr/bin/env bash
# Deploy the latest pushed code: bash /srv/flood-dss/deploy/update.sh
# (Model or data files that changed must be uploaded again with upload_model_files.ps1 first.)
set -euo pipefail
APP=/srv/flood-dss
WEB="$APP/nairobi-flood-dashboard"
PY="$APP/scripts"
artisan() { sudo -u www-data php "$WEB/artisan" "$@"; }

git -C "$APP" pull --ff-only
if [ -d "$HOME/flood-files" ]; then cp -r "$HOME/flood-files"/. "$PY"/; fi
"$PY/venv/bin/pip" install -q -r "$APP/deploy/requirements-server.txt"

cd "$WEB"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
artisan migrate --force
artisan config:cache
artisan route:cache
artisan view:cache

sudo systemctl restart php8.3-fpm
sudo systemctl restart flood-model
echo "Updated to $(git -C "$APP" log --oneline -1). The model server needs about 30 s to load."
