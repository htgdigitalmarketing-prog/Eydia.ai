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
# A prebuilt export is committed at deploy/frontend-out.tgz (built in a reliable
# environment). If the server build is skipped, or hangs/crashes (CloudLinux
# nproc limit → "spawn node EAGAIN"), we fall back to that tarball, so a deploy
# NEVER depends on the server being able to run `next build`.
#   * SKIP_FRONTEND_BUILD=1 → don't build here at all, use the committed tarball
#     (or an existing out/). This is the reliable default for this host.
PREBUILT_TGZ="$SRC/deploy/frontend-out.tgz"
cd "$SRC/frontend"
BUILD_TIMEOUT="${BUILD_TIMEOUT:-900}"   # seconds; override if your box is slow

extract_prebuilt() {
    if [ -f "$PREBUILT_TGZ" ]; then
        say "Frontend: using committed prebuilt export (deploy/frontend-out.tgz)"
        rm -rf out && mkdir -p out && tar -xzf "$PREBUILT_TGZ" -C out
        return 0
    fi
    return 1
}

if [ "${SKIP_FRONTEND_BUILD:-0}" = "1" ]; then
    if [ -f out/index.html ]; then
        say "Frontend: SKIP_FRONTEND_BUILD=1 — reusing existing out/ (no build)"
    else
        extract_prebuilt || say "Frontend: SKIP_FRONTEND_BUILD=1 but no out/ and no prebuilt tarball!"
    fi
elif command -v npm >/dev/null 2>&1; then
    say "Frontend: building static export (next build → out/)"
    npm ci
    # Back up the last good export, then build fresh. If the build hangs
    # (timeout) or crashes, we restore the backup so the site stays live.
    rm -rf out.prev && [ -d out ] && mv out out.prev || true
    # CI=1 + stdin from /dev/null keep `next build` NON-INTERACTIVE. On CloudLinux
    # the restricted pseudo-terminal makes Next's interactive output call
    # setRawMode on the TTY, which fails with "EIO" and crashes the build after
    # a clean compile. Running headless avoids that entirely.
    run_build() {
        CI=1 \
        NODE_OPTIONS="--max-old-space-size=${NEXT_HEAP_MB:-2048}" \
        NEXT_TELEMETRY_DISABLED=1 \
        UV_THREADPOOL_SIZE=1 \
        LIGHT_BUILD=1 \
            npm run build < /dev/null
    }
    if command -v timeout >/dev/null 2>&1; then
        timeout "${BUILD_TIMEOUT}"s bash -c "$(declare -f run_build); run_build" || true
    else
        run_build || true
    fi
    if [ -f out/index.html ]; then
        rm -rf out.prev
    else
        # Build hung/crashed (e.g. "spawn node EAGAIN" under the nproc limit).
        # Prefer the committed prebuilt export; else fall back to the last good one.
        say "Frontend: server build did not finish — falling back"
        if extract_prebuilt; then
            rm -rf out.prev
        elif [ -d out.prev ]; then
            say "Frontend: restoring the previous export"
            rm -rf out && mv out.prev out
        fi
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

   A) Build the frontend by hand (headless — avoids the CloudLinux TTY crash):
        cd "$SRC/frontend" && rm -rf out
        CI=1 NODE_OPTIONS="--max-old-space-size=2048" NEXT_TELEMETRY_DISABLED=1 \
          LIGHT_BUILD=1 npx next build < /dev/null
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
# The web root is public_html (not $APP/public), so `storage:link` alone leaves
# uploaded/generated media (GBP post images, AI photos) unreachable at
# https://domain/storage/... . Link the web root's /storage to the public disk
# so those files are publicly served (needed for the dashboard AND for Google to
# fetch post images).
mkdir -p "$APP/storage/app/public"
ln -sfn "$APP/storage/app/public" "$WEB/storage"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache

say "Deploy complete → https://$(basename "$DOMAIN_DIR")/"
echo "    API health: https://$(basename "$DOMAIN_DIR")/api/v1/health"

# ---- 5) Scheduler cron reminder --------------------------------------------
# GBP automations (AI posts/photos/replies), scheduled-post publishing and the
# drip follow-ups ALL run through Laravel's scheduler. Without this one cron they
# never fire — this is the usual reason "automation did nothing". Idempotent to
# print every deploy.
CRON_LINE="* * * * * php $APP/artisan schedule:run >> /dev/null 2>&1"
if crontab -l 2>/dev/null | grep -Fq "$APP/artisan schedule:run"; then
    say "Scheduler cron: already installed ✓"
else
    cat <<MSG

==> ℹ️  Scheduler cron not found in this user's crontab.
    NOTE: Hostinger cron jobs added in hPanel → Advanced → Cron Jobs are
    managed separately and do NOT show up in 'crontab -l', so if you
    already added it there, IGNORE this message — it's working.

    If you have NOT set it up yet, the scheduler runs GBP automations,
    scheduled-post publishing and drip follow-ups. Add it in
    hPanel → Advanced → Cron Jobs (every minute), command:

      /opt/alt/php82/usr/bin/php $APP/artisan schedule:run

    (or '$APP/artisan schedule:run' with your PHP binary).
MSG
fi
