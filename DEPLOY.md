# Deploying Eydia.ai to Hostinger — single domain (`eydia.ai`)

Eydia.ai is **one Laravel API** + **one Next.js frontend**. The frontend is built
as a **static export** (`next build` → `out/`), so it needs **no Node at runtime** —
perfect for Hostinger shared/Business hosting.

**Single-domain layout:** Laravel's `public/` is the document root; the static
frontend lives inside it. `eydia.ai/api/v1/*` is served by Laravel; everything
else is the static frontend.

```
~/domains/eydia.ai/
├── app/            ← Laravel app code (app, bootstrap, config, database,
│                      routes, storage, vendor, artisan, .env)   [keep PRIVATE]
└── public_html/    ← DOCUMENT ROOT
    ├── index.php          (Laravel entry — paths edited to ../app)
    ├── .htaccess          (Laravel's, + DirectoryIndex)
    ├── index.html         (frontend home, from out/)
    ├── _next/ ...         (frontend assets)
    ├── login/ dashboard/ gbp/ ...   (frontend route folders)
    └── (no /api folder needed — /api/* is handled by index.php)
```

---

## 0. Prerequisites
- Hostinger plan with **PHP 8.2+** and **MySQL** (Business/Cloud recommended; SSH helps).
- Domain `eydia.ai` pointed to Hostinger, **free SSL enabled** (hPanel → SSL).
- Build the frontend locally (you need Node 18+ **only to build**).

---

## 1. Build the frontend (on your PC)
```powershell
cd frontend
# .env.production already set to: NEXT_PUBLIC_API_URL=https://eydia.ai/api/v1
npm install
npm run build        # produces the static site in  frontend/out/
```
`frontend/out/` is the whole static site. You'll upload its **contents** into `public_html/` in step 4.

---

## 2. Create the MySQL database (hPanel)
hPanel → **Databases → MySQL** → create a database + user, note:
`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, host (usually `localhost`).

---

## 3. Upload Laravel (the `backend/` folder)
Upload the **backend** project to `~/domains/eydia.ai/app/` (NOT inside public_html).
- If you have SSH: `cd ~/domains/eydia.ai/app && composer install --no-dev --optimize-autoloader`
- If no SSH: run `composer install --no-dev --optimize-autoloader` locally and upload the `vendor/` folder too.

### 3a. `.env` (in `~/domains/eydia.ai/app/.env`)
```env
APP_NAME="Eydia.ai"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://eydia.ai
APP_TIMEZONE=Asia/Kolkata

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_db
DB_USERNAME=your_user
DB_PASSWORD=your_pass

QUEUE_CONNECTION=sync
CACHE_STORE=file

# Same domain → where the backend redirects the user after Google/OTP login:
FRONTEND_URL=https://eydia.ai
# Same-origin, but set it anyway:
CORS_ALLOWED_ORIGINS=https://eydia.ai

# Google (GBP + login) — redirect URIs must match the Google Cloud console exactly:
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://eydia.ai/api/v1/auth/google/callback

# Meta WhatsApp / Razorpay / Gemini — your real keys:
META_APP_ID=...
META_APP_SECRET=...
RAZORPAY_KEY=...
RAZORPAY_SECRET=...
GEMINI_API_KEY=...
GEMINI_MODEL=gemini-3.6-flash
GEMINI_IMAGE_MODEL=gemini-2.5-flash-image
```
Then, via SSH (or Hostinger's "Terminal"):
```bash
cd ~/domains/eydia.ai/app
php artisan key:generate
php artisan jwt:secret
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\PlanSeeder --force
php artisan db:seed --class=Database\\Seeders\\RolePermissionSeeder --force
php artisan db:seed --class=Database\\Seeders\\SuperAdminSeeder --force   # admin@example.com / password (change in .env first)
php artisan storage:link
php artisan config:cache && php artisan route:cache
```
> No shell? Hostinger has a one-click "Terminal" on Business/Cloud. On pure shared
> hosting you can run artisan via a temporary route or Hostinger's Cron "run once".

---

## 4. Put the frontend + Laravel entry into `public_html/`
1. Copy **Laravel's `public/` contents** (`index.php`, `.htaccess`, `favicon.ico`, `robots.txt`) into `public_html/`.
2. Edit `public_html/index.php` — change the two require paths to point at `../app`:
   ```php
   require __DIR__.'/../app/vendor/autoload.php';
   $app = require_once __DIR__.'/../app/bootstrap/app.php';
   ```
   (and if present, the maintenance line `__DIR__.'/../app/storage/framework/maintenance.php'`)
3. Upload the **contents of `frontend/out/`** into `public_html/` (so `index.html`, `_next/`, `login/`, `dashboard/`, etc. sit next to `index.php`).
4. Make `public_html/.htaccess` serve the frontend at `/` while Laravel handles `/api`. Add this **line at the top** of `.htaccess`:
   ```apacheconf
   DirectoryIndex index.html index.php
   ```
   Keep Laravel's existing rewrite block below it (it sends only non-existent paths to `index.php`, i.e. `/api/v1/*`).

**Result:**
- `https://eydia.ai/` → `index.html` (frontend)
- `https://eydia.ai/dashboard/` → `dashboard/index.html` (frontend)
- `https://eydia.ai/api/v1/health` → Laravel ✅

---

## 5. Cron (scheduler — needed for follow-ups, campaigns, hot-lead scan)
hPanel → **Advanced → Cron Jobs**, every minute:
```
* * * * * php ~/domains/eydia.ai/app/artisan schedule:run >> /dev/null 2>&1
```

---

## 6. Update the Google / Meta / Razorpay consoles
- **Google Cloud** → OAuth client → Authorized redirect URIs: add
  `https://eydia.ai/api/v1/auth/google/callback` and
  `https://eydia.ai/api/v1/auth/gbp/callback`.
  Enable **Business Profile API** for GBP sync.
- **Meta** → WhatsApp webhook callback: `https://eydia.ai/api/v1/webhooks/whatsapp`
  (verify token = your `WHATSAPP_WEBHOOK_VERIFY_TOKEN`).
- **Razorpay** → webhook: `https://eydia.ai/api/v1/webhooks/razorpay`.

---

## 7. Verify
- `https://eydia.ai/api/v1/health` → `{"success":true,...}`
- `https://eydia.ai/` → the landing page loads
- Log in with the super admin (`admin@example.com` / the `SUPER_ADMIN_PASSWORD` you set).

---

## Redeploying later
- Backend change: upload changed files to `~/domains/eydia.ai/app/`, then
  `php artisan migrate --force && php artisan config:cache && php artisan route:cache`.
- Frontend change: `npm run build` locally → upload new `out/` contents into `public_html/` (overwrite).

## Alternative (if path-based `/api` is fiddly on your plan)
Create a subdomain **`api.eydia.ai`** in hPanel, point its document root to Laravel's
`public/`, set `NEXT_PUBLIC_API_URL=https://api.eydia.ai/api/v1` (rebuild), and host
the static `out/` on `eydia.ai`. Same registered domain, cleaner separation; add
`CORS_ALLOWED_ORIGINS=https://eydia.ai` and the subdomain to the Google/Meta URIs.
