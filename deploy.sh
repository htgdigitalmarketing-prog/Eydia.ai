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

# ---- 0) First-run .env guard ------------------------------------------------
# The app's .env lives in $APP (kept across deploys). On the very first run it
# won't exist — seed it from the example and stop so you can fill the DB creds.
if [ ! -f "$APP/.env" ]; then
    cp "$SRC/backend/.env.example" "$APP/.env"
    cat <<MSG

==> No .env found — created one at:
      $APP/.env
    EDIT it now (at minimum):
      APP_ENV=production   APP_DEBUG=false   APP_URL=https://$(basename "$DOMAIN_DIR")
      DB_DATABASE / DB_USERNAME / DB_PASSWORD   (hPanel → MySQL)
      FRONTEND_URL=https://$(basename "$DOMAIN_DIR")
    Leave APP_KEY= and JWT_SECRET= empty (this script generates them).
    Then run:  bash deploy.sh   again.
MSG
    exit 1
fi

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
    say "Frontend: building static export (next build → out/)"
    npm ci
    rm -rf out
    # LIGHT_BUILD=1 trims worker processes so the build fits shared-hosting
    # (CloudLinux) process limits. A non-zero exit from a post-export worker
    # cleanup crash (EPERM/EAGAIN) is tolerated — we verify out/index.html below.
    LIGHT_BUILD=1 npm run build || true
fi
if [ -f "$SRC/frontend/out/index.html" ]; then
    say "Frontend: syncing out/ into $WEB"
    rsync -a --exclude='index.php' --exclude='.htaccess' "$SRC/frontend/out/" "$WEB/"
else
    cat <<MSG

!! No built frontend found at frontend/out/ and no Node on this server to build it.
   Fix with ONE of these, then re-run  bash deploy.sh :

   A) Install Node once on this server (recommended), then it builds automatically:
        curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
        . ~/.nvm/nvm.sh && nvm install 20

   B) Or build on your PC ( cd frontend && npm run build ) and upload the CONTENTS
      of frontend/out/ into:  $WEB
MSG
    exit 1
fi

# ---- 3) Laravel entry + .htaccess in the web root ---------------------------
say "Web root: installing Laravel index.php + .htaccess"
cp -f "$SRC/deploy/public_html/index.php" "$WEB/index.php"
cp -f "$SRC/deploy/public_html/.htaccess" "$WEB/.htaccess"

# ---- 4) Migrate, seed, cache ------------------------------------------------
say "Laravel: keys + migrate + seed + cache"
cd "$APP"
# First-time only: generate the app keys if they're still blank (idempotent).
if ! grep -q '^APP_KEY=base64:' .env; then
    say "Generating APP_KEY"; php artisan key:generate --force
fi
if ! grep -qE '^JWT_SECRET=.+' .env; then
    say "Generating JWT_SECRET"; php artisan jwt:secret --force
fi
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
