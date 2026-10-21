#!/usr/bin/env bash
#
# One-command deploy for the single-domain layout on Hostinger (SSH).
#
# Usage (run from inside the cloned repo on the server):
#     bash deploy.sh
#     # redeploy later:  git pull && bash deploy.sh
#
# Override the domain folder if yours differs (default: eydia.in):
#     DOMAIN_DIR="$HOME/domains/eydia.ai" bash deploy.sh
#
# Layout it maintains:
#   $DOMAIN_DIR/app          ← Laravel app (code from backend/; .env + storage kept)
#   $DOMAIN_DIR/public_html  ← Laravel public (index.php/.htaccess) + static frontend
#
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
DOMAIN_DIR="${DOMAIN_DIR:-$HOME/domains/eydia.in}"
APP="$DOMAIN_DIR/app"
WEB="$DOMAIN_DIR/public_html"

say() { printf '\n==> %s\n' "$1"; }

mkdir -p "$APP" "$WEB"

# ---- 1) Backend (Laravel) ---------------------------------------------------
say "Backend: installing PHP dependencies"
cd "$SRC/backend"
if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --optimize-autoloader --no-interaction
else
    echo "!! composer not found on this server."
    echo "   Either install it, or run 'composer install --no-dev -o' locally and"
    echo "   make sure backend/vendor exists before syncing. Continuing anyway..."
fi

say "Backend: syncing code into $APP (keeping .env, storage, caches)"
rsync -a \
    --exclude='.git/' \
    --exclude='.env' \
    --exclude='storage/' \
    --exclude='bootstrap/cache/' \
    --exclude='tests/' \
    --exclude='database/database.sqlite' \
    "$SRC/backend/" "$APP/"

# ---- 2) Frontend (Next.js static export) ------------------------------------
cd "$SRC/frontend"
if command -v npm >/dev/null 2>&1; then
    say "Frontend: building static export"
    npm ci
    npm run build
    say "Frontend: syncing out/ into $WEB"
    rsync -a --exclude='index.php' --exclude='.htaccess' "$SRC/frontend/out/" "$WEB/"
else
    echo ""
    echo "!! npm not found on this server — cannot build the frontend here."
    echo "   Build it locally:  cd frontend && npm run build"
    echo "   then upload the CONTENTS of frontend/out/ into $WEB"
fi

# ---- 3) Laravel entry + .htaccess in the web root ---------------------------
say "Web root: installing Laravel index.php + .htaccess"
cp -f "$SRC/deploy/public_html/index.php" "$WEB/index.php"
cp -f "$SRC/deploy/public_html/.htaccess" "$WEB/.htaccess"

# ---- 4) Migrate, seed, cache ------------------------------------------------
say "Laravel: migrate + seed + cache"
cd "$APP"
php artisan migrate --force
php artisan db:seed --class='Database\Seeders\RolePermissionSeeder' --force
php artisan db:seed --class='Database\Seeders\PlanSeeder' --force
php artisan db:seed --class='Database\Seeders\SuperAdminSeeder' --force
php artisan storage:link || true
php artisan optimize:clear
php artisan config:cache
php artisan route:cache

say "Deploy complete → https://$(basename "$DOMAIN_DIR")/"
echo "    API health: https://$(basename "$DOMAIN_DIR")/api/v1/health"
