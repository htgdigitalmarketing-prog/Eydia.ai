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
# On Hostinger / CloudLinux the build can HANG at "Generating static pages"
# (worker can't fork under the nproc limit) or thrash swap (OOM). We defend by:
#   * capping Node heap so it fails fast instead of thrashing,
#   * disabling telemetry (one less network/worker),
#   * running under `timeout` so a hang can never block the deploy forever,
#   * keeping the previous out/ as a backup and restoring it if the build
#     hangs/fails, so the site stays up on the last good export.
# Set SKIP_FRONTEND_BUILD=1 to reuse the existing out/ (e.g. you built it on
# your PC and committed/uploaded it) and skip the server build entirely.
cd "$SRC/frontend"
BUILD_TIMEOUT="${BUILD_TIMEOUT:-900}"   # seconds; override if your box is slow
if [ "${SKIP_FRONTEND_BUILD:-0}" = "1" ]; then
    say "Frontend: SKIP_FRONTEND_BUILD=1 — reusing existing out/ (no build)"
elif command -v npm >/dev/null 2>&1; then
    say "Frontend: building static export (next build → out/)"
    npm ci
    # Back up the last good export, then build fresh. If the build hangs
    # (timeout) or crashes, we restore the backup so the site stays live.
    rm -rf out.prev && [ -d out ] && mv out out.prev || true
    run_build() {
        NODE_OPTIONS="--max-old-space-size=${NEXT_HEAP_MB:-2048}" \
        NEXT_TELEMETRY_DISABLED=1 \
        LIGHT_BUILD=1 \
            npm run build
    }
    if command -v timeout >/dev/null 2>&1; then
        timeout "${BUILD_TIMEOUT}"s bash -c "$(declare -f run_build); run_build" || true
    else
        run_build || true
    fi
    if [ -f out/index.html ]; then
        rm -rf out.prev
    elif [ -d out.prev ]; then
        say "Frontend: build did not finish — restoring the previous export"
        rm -rf out && mv out.prev out
    fi
fi
if [ -f "$SRC/frontend/out/index.html" ]; then
    say "Frontend: syncing out/ into $WEB"
    rsync -a --exclude='index.php' --exclude='.htaccess' "$SRC/frontend/out/" "$WEB/"
else
    cat <<MSG

!! No usable frontend build at frontend/out/.
   The build likely HUNG at "Generating static pages" (CloudLinux limit).
   Fix with ONE of these, then re-run  bash deploy.sh :

   A) Build the frontend by hand with a smaller heap, then re-run deploy:
        cd "$SRC/frontend" && rm -rf out
        NODE_OPTIONS="--max-old-space-size=2048" NEXT_TELEMETRY_DISABLED=1 \
          LIGHT_BUILD=1 npx next build
        cd "$SRC" && SKIP_FRONTEND_BUILD=1 bash deploy.sh

   B) Give the deploy's own build more/less room (default heap 2048MB, timeout 900s):
        NEXT_HEAP_MB=1536 BUILD_TIMEOUT=1200 bash deploy.sh

   C) Or build on your PC ( cd frontend && npm run build ), upload the CONTENTS of
      frontend/out/ into  $WEB , or into  $SRC/frontend/out  then:
        SKIP_FRONTEND_BUILD=1 bash deploy.sh
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
