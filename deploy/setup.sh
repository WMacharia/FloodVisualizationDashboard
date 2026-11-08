#!/usr/bin/env bash
# One-time set-up of the Nairobi Flood DSS on a fresh Ubuntu 24.04 server (e.g. an Azure VM).
#
#   bash setup.sh <domain> <email-for-the-https-certificate>
#
# Run it as your normal user (azureuser), not root; it uses sudo where needed. Upload the model
# files first (deploy/upload_model_files.ps1 on the laptop). Safe to run again: finished steps
# are skipped or repeated harmlessly, and an existing .env is never overwritten.
set -euo pipefail

DOMAIN="${1:?Usage: bash setup.sh <domain> <email>}"
CERT_EMAIL="${2:?Usage: bash setup.sh <domain> <email>}"
REPO="${REPO:-https://github.com/WMacharia/FloodVisualizationDashboard.git}"
BRANCH="${BRANCH:-surrogate-model-roles-alerts}"
APP=/srv/flood-dss
WEB="$APP/nairobi-flood-dashboard"
PY="$APP/scripts"
STAGE="$HOME/flood-files"          # where upload_model_files.ps1 puts the model and data files
RUN_USER="$(id -un)"
PHPV=8.3                           # Ubuntu 24.04's PHP (Laravel 12 needs 8.2+)
PYV=3.13                           # same Python as the laptop's model environment

step() { printf '\n==> %s\n' "$*"; }
artisan() { sudo -u www-data php "$WEB/artisan" "$@"; }   # as the web server's user, so it owns what it writes

if [ "$(id -u)" -eq 0 ]; then echo "Run this as azureuser (not root); it uses sudo itself."; exit 1; fi

NEEDED="models/unet_surrogate.pth models/unet_surrogate_config.json data/surrogate/static_inputs.npz
data/aligned/aligned_esa.tif data/dashboard/layers.npz data/dashboard/road_graph.npz data/dashboard/facilities.json
data/dashboard/places.json data/dashboard/subcounties.geojson data/validation/vehicle_margins.json"
for f in $NEEDED; do
  if [ ! -f "$STAGE/$f" ] && [ ! -f "$PY/$f" ]; then
    echo "Missing $f. Run deploy/upload_model_files.ps1 on the laptop first."; exit 1
  fi
done

step "Swap file (2 GB safety margin; the model server uses about 2.5 GB of the 4 GB RAM)"
if ! swapon --show | grep -q '^/swapfile'; then
  sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile
  sudo mkswap /swapfile >/dev/null && sudo swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab >/dev/null
fi

step "System packages (Nginx, PostgreSQL, PHP $PHPV, Python $PYV, certbot)"
sudo apt-get install -yq software-properties-common >/dev/null
sudo add-apt-repository -y ppa:deadsnakes/ppa >/dev/null        # Python 3.13 for Ubuntu 24.04
sudo apt-get update -q
sudo DEBIAN_FRONTEND=noninteractive apt-get install -yq \
  git unzip curl ufw nginx postgresql certbot python3-certbot-nginx composer \
  "php$PHPV-fpm" "php$PHPV-cli" "php$PHPV-pgsql" "php$PHPV-mbstring" "php$PHPV-xml" \
  "php$PHPV-curl" "php$PHPV-zip" "php$PHPV-bcmath" "php$PHPV-intl" \
  "python$PYV" "python$PYV-venv"

step "Code (branch $BRANCH)"
sudo mkdir -p "$APP" && sudo chown "$RUN_USER": "$APP"
if [ -d "$APP/.git" ]; then git -C "$APP" pull --ff-only; else git clone -b "$BRANCH" "$REPO" "$APP"; fi

step "Model and data files"
if [ -d "$STAGE" ]; then cp -r "$STAGE"/. "$PY"/; fi
mkdir -p "$PY/data/cache"

step "Python environment for the model server (CPU-only PyTorch; takes a few minutes)"
[ -d "$PY/venv" ] || "python$PYV" -m venv "$PY/venv"
"$PY/venv/bin/pip" install -q --upgrade pip
"$PY/venv/bin/pip" install -q -r "$APP/deploy/requirements-server.txt"

step "Model server service (starts now and on every reboot)"
sed "s|__USER__|$RUN_USER|g; s|__APP__|$APP|g" "$APP/deploy/flood-model.service" \
  | sudo tee /etc/systemd/system/flood-model.service >/dev/null
sudo systemctl daemon-reload
sudo systemctl enable flood-model >/dev/null 2>&1
sudo systemctl restart flood-model

step "Database (PostgreSQL)"
if [ ! -f "$WEB/.env" ]; then
  DB_PASS="$(openssl rand -hex 24)"
  if sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='flooddss'" | grep -q 1; then
    sudo -u postgres psql -qc "ALTER ROLE flooddss WITH LOGIN PASSWORD '$DB_PASS';"
  else
    sudo -u postgres psql -qc "CREATE ROLE flooddss WITH LOGIN PASSWORD '$DB_PASS';"
  fi
  sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='flooddss'" | grep -q 1 \
    || sudo -u postgres psql -qc "CREATE DATABASE flooddss OWNER flooddss;"
fi

step "Laravel settings (.env)"
if [ ! -f "$WEB/.env" ]; then
  echo "Google sign-in keys: Google Cloud Console > APIs & Services > Credentials (the same OAuth client as on the laptop)."
  read -rp  "  Google OAuth client ID: " G_ID
  read -rsp "  Google OAuth client secret (typing is hidden): " G_SECRET; echo
  read -rp  "  Administrator Google account(s), comma separated: " ADMINS
  cp "$APP/deploy/env.production" "$WEB/.env"
  set_env() {   # set_env KEY VALUE (value escaped for sed)
    local v; v="$(printf '%s' "$2" | sed -e 's/[&|\\]/\\&/g')"
    sed -i "s|^$1=.*|$1=$v|" "$WEB/.env"
  }
  set_env APP_URL "https://$DOMAIN"
  set_env GOOGLE_REDIRECT_URI "https://$DOMAIN/auth/google/callback"
  set_env GOOGLE_CLIENT_ID "$G_ID"
  set_env GOOGLE_CLIENT_SECRET "$G_SECRET"
  set_env ADMIN_EMAILS "$ADMINS"
  set_env DB_PASSWORD "$DB_PASS"
fi
sudo chown "$RUN_USER":www-data "$WEB/.env" && sudo chmod 640 "$WEB/.env"

step "Laravel (dependencies, database tables, caches)"
cd "$WEB"
# Shared by you (composer, deploys) and the web server (logs, compiled views, caches): you own
# them, the www-data group can write, and new files inherit the group (setgid folders)
sudo chown -R "$RUN_USER":www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} +
composer install --no-dev --optimize-autoloader --no-interaction --quiet
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
artisan migrate --force
artisan config:cache
artisan route:cache
artisan view:cache

step "Web server (Nginx) and firewall"
sed "s|__DOMAIN__|$DOMAIN|g; s|__WEB__|$WEB|g; s|__PHPV__|$PHPV|g" "$APP/deploy/nginx-flood-dss.conf" \
  | sudo tee /etc/nginx/sites-available/flood-dss >/dev/null
sudo ln -sf /etc/nginx/sites-available/flood-dss /etc/nginx/sites-enabled/flood-dss
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl restart "php$PHPV-fpm"
sudo ufw allow OpenSSH >/dev/null
sudo ufw allow 'Nginx Full' >/dev/null
sudo ufw --force enable >/dev/null

step "HTTPS certificate (Let's Encrypt; renews itself)"
sudo certbot --nginx -d "$DOMAIN" -m "$CERT_EMAIL" --agree-tos --non-interactive --redirect

step "Checks"
printf 'Waiting for the model server to load'
for _ in $(seq 1 60); do
  if curl -fs http://127.0.0.1:8001/health >/dev/null; then break; fi
  printf '.'; sleep 5
done; echo
if curl -fs http://127.0.0.1:8001/health >/dev/null; then echo "Model server: OK"
else echo "Model server is not answering yet. See: sudo journalctl -u flood-model -n 60"; fi
echo "Website: $(curl -s -o /dev/null -w '%{http_code}' "https://$DOMAIN/") (200 = OK)"

cat <<EOF

Done. Open https://$DOMAIN
Remaining step (once): add these in Google Cloud Console > Credentials > your OAuth client:
  Authorised JavaScript origin: https://$DOMAIN
  Authorised redirect URI:      https://$DOMAIN/auth/google/callback
Later updates: bash $APP/deploy/update.sh
EOF
