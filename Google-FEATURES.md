# ReviewFlow — Complete Features & Conditions Reference

> Use this document to replicate any feature/module in another Laravel project.
> Stack: **Laravel 12, PHP 8.2, MySQL (XAMPP), Blade (zero-build frontend), Laravel Sanctum**

---

## Table of Contents

1. [Architecture & Multi-Tenancy](#1-architecture--multi-tenancy)
2. [Authentication & Authorization](#2-authentication--authorization)
3. [Role & Permission System](#3-role--permission-system)
4. [Plan & Subscription System](#4-plan--subscription-system)
5. [Credit System (Pay-per-use AI)](#5-credit-system-pay-per-use-ai)
6. [Payment Integration (Razorpay)](#6-payment-integration-razorpay)
7. [Google Business Profile (GBP)](#7-google-business-profile-gbp)
8. [Review Management](#8-review-management)
9. [AI Service (Gemini)](#9-ai-service-gemini)
10. [GBP Content (Posts & Photos)](#10-gbp-content-posts--photos)
11. [Social Media Publishing](#11-social-media-publishing)
12. [Lead Management (CRM)](#12-lead-management-crm)
13. [WhatsApp Integration](#13-whatsapp-integration)
14. [Ad Reports](#14-ad-reports)
15. [Business Insights](#15-business-insights)
16. [Google Audit](#16-google-audit)
17. [Competitor Analysis](#17-competitor-analysis)
18. [Local Rank Checker](#18-local-rank-checker)
19. [Keyword Generator](#19-keyword-generator)
20. [One-Click Optimization](#20-one-click-optimization)
21. [AI Chat Mode](#21-ai-chat-mode)
22. [AI Media Generation](#22-ai-media-generation)
23. [Invoicing & Accounting](#23-invoicing--accounting)
24. [Team Management](#24-team-management)
25. [Admin Panel (Super Admin)](#25-admin-panel-super-admin)
26. [Health Score Dashboard](#26-health-score-dashboard)
27. [Email Verification](#27-email-verification)
28. [Mobile API (Flutter)](#28-mobile-api-flutter)
29. [Environment Variables](#29-environment-variables)
30. [Database Schema (Models)](#30-database-schema-models)

---

## 1. Architecture & Multi-Tenancy

### Hierarchy
```
Agency (top-level tenant)
  ├── Users (multiple, each has a role)
  ├── Clients (multiple businesses under one agency)
  │     ├── GbpLocations (Google Business locations)
  │     │     ├── Reviews
  │     │     ├── GbpPosts
  │     │     └── GbpPhotos
  │     ├── Leads
  │     ├── Invoices
  │     └── Integrations (Google, Meta OAuth tokens)
  ├── Subscription (one per agency — plan, credits, status)
  ├── SocialPosts
  ├── WhatsappMessages
  ├── AdReports
  └── CreditLedger (audit trail)
```

### Scoping Pattern
Every query is scoped by `agency_id` from the logged-in user:
```php
$agencyId = $request->user()->agency_id;
Client::where('agency_id', $agencyId)->get();
```
Some users also have a `client_id` which further restricts their view to a single client.

### Key Models & Fields
| Model | Key Fields |
|---|---|
| `Agency` | name, slug, white_label (JSON) |
| `User` | agency_id, client_id, name, email, password, role, phone, avatar |
| `Client` | agency_id, name, industry, phone, email, gstin, billing_address |
| `Subscription` | agency_id, plan, credit_balance, monthly_credits, credits_reset_at, status, auto_reply, wa_notify |

---

## 2. Authentication & Authorization

### Web Auth (Session-based)
- **Register**: Creates Agency + Client + User + Subscription (TRIALING). Redirects to plans page.
- **Login**: Session-based. Admins go to `/admin`, trial users go to `/plans`, active users go to `/dashboard`.
- **Google OAuth Login**: Via Laravel Socialite (`google_login` driver). Auto-creates agency for new users.
- **Logout**: Invalidates session + CSRF token.

### API Auth (Token-based — Sanctum)
- `POST /api/login` — returns `{ token, user }`, token via `createToken('mobile')`
- `POST /api/register` — creates agency + user + returns token
- `POST /api/auth/google` — Google ID token verification for mobile (throttled 10/min)
- `POST /api/logout` — revokes current token
- All authenticated routes use `auth:sanctum` middleware

### Mobile Login Bridge
- `GET /mobile-login/{code}` — one-time code consumed to create web session from mobile app

### Conditions
- Email uniqueness enforced on register
- Password minimum 8 characters
- Google OAuth: existing email → login; new email → auto-register
- Registration creates a `TRIALING` subscription (must upgrade to unlock AI features)

---

## 3. Role & Permission System

### Roles
| Role | Access |
|---|---|
| `SUPER_ADMIN` | Full admin panel, all agencies, bypass plan check |
| `CLIENT_OWNER` | Full access to their agency's data, manage team |
| `CLIENT_STAFF` | Limited to their assigned client's data |

### Middleware

**`EnsureRole`** (`role:SUPER_ADMIN`):
```php
// Checks user->role against allowed roles
// Returns 403 if not in allowed list
Route::middleware('role:SUPER_ADMIN')->group(...)
```

**`EnsureActivePlan`** (`plan`):
```php
// Bypasses for SUPER_ADMIN
// Checks agency->subscription->status !== 'TRIALING'
// Returns 402 JSON or redirects to /plans page
```

### Registration in bootstrap/app.php
```php
$middleware->alias([
    'role' => \App\Http\Middleware\EnsureRole::class,
    'plan' => \App\Http\Middleware\EnsureActivePlan::class,
]);
```

---

## 4. Plan & Subscription System

### Plan Model
```php
// Fields: name, code, price, gst_rate, credits, features (JSON), permissions (JSON), is_active, sort
// Constants - all modules a plan can grant access to:
Plan::MODULES = [
    'reviews', 'gbp_content', 'ai_media', 'audit', 'competitors',
    'rank_checker', 'social', 'whatsapp', 'leads', 'keywords',
    'invoicing', 'ai_mode',
];
```

### Per-Plan Credits (hardcoded fallback)
```php
CreditService::PLAN_CREDITS = [
    'STARTER' => 100,
    'GROWTH'  => 500,
    'AGENCY'  => 2000,
];
```

### Subscription Statuses
| Status | Meaning |
|---|---|
| `TRIALING` | Just registered, no plan purchased. AI features blocked. |
| `ACTIVE` | Paid plan, all features unlocked |

### GST Handling (India)
```php
// Price is GST-inclusive
$base = $plan->price / (1 + $plan->gst_rate / 100);
$gst  = $plan->price - $base;
```

### Module-Level Access
```php
// Plan has a permissions array like ['reviews', 'audit', 'social']
// Empty permissions = all modules allowed (backward compatible)
$plan->allows('audit'); // checks if 'audit' is in permissions array
```

### Flow
1. User registers → gets `TRIALING` subscription
2. User visits `/plans` → sees available plans with prices
3. User clicks upgrade → Razorpay checkout → payment verified → subscription activated
4. Monthly credits allocated based on plan

---

## 5. Credit System (Pay-per-use AI)

### Cost Table
```php
CreditService::COSTS = [
    'ai_review_reply'   => 1,
    'ai_social_caption' => 1,
    'ai_keyword_gen'    => 2,
    'ai_rank_check'     => 1,
    'ai_media_generate' => 5,   // most expensive (image generation)
    'ai_chat'           => 1,
    'ai_post_caption'   => 1,
    'ai_photo_caption'  => 1,
    'ai_optimize'       => 3,
    'ai_audit'          => 2,
    'ai_competitor'     => 2,
];
```

### Pattern: canAfford → action → deduct
```php
// 1. Check before doing work
if (!$this->creditService->canAfford($agencyId, 'ai_audit')) {
    return response()->json(['error' => 'Insufficient credits'], 402);
}

// 2. Do the AI work...

// 3. Deduct after success
$this->creditService->deduct($agencyId, $userId, 'ai_audit', 'Client Name');
```

### Deduct (transactional, row-locked)
```php
DB::transaction(function () {
    $sub = Subscription::where('agency_id', $id)->lockForUpdate()->first();
    $sub->decrement('credit_balance', $cost);
    CreditLedger::create([...]);  // audit trail
});
```

### Credit Ledger (Audit Trail)
Every credit change (deduction or addition) is logged:
```
agency_id, user_id, amount (+/-), balance_after, action, description
```

### Top-up Methods
- **Monthly reset**: Plan-based, resets to plan's credit allocation
- **Manual top-up**: Admin adds credits via admin panel
- **Credit packages**: User buys a credit pack via Razorpay

---

## 6. Payment Integration (Razorpay)

### Service: `RazorpayService`
No SDK — direct REST API calls with Basic Auth.

### Flow
```
1. createOrder(amountRupees, receipt) → Razorpay Order ID
   - Amount in rupees × 100 = paise
   - Currency: INR
   - payment_capture: 1 (auto-capture)

2. Frontend: Razorpay Checkout popup opens with order_id

3. verifySignature(orderId, paymentId, signature) → bool
   - HMAC_SHA256(orderId + "|" + paymentId, keySecret)
   - hash_equals() for timing-safe comparison
```

### Payment Model
```
agency_id, plan, amount, currency, razorpay_order_id, razorpay_payment_id, status
```

### Credit Package Purchase
Same Razorpay flow but for credit packs instead of plan upgrades.

---

## 7. Google Business Profile (GBP)

### OAuth Flow
```
1. GET /clients/{client}/google/connect
   - Generates random state, stores in Cache (not session — survives redirect)
   - Redirects to Google OAuth consent screen
   - Scopes: business.manage, openid, email, profile

2. GET /google/callback?code=...&state=...
   - Exchanges code for access_token + refresh_token
   - Stores in Integration model (provider: GOOGLE_GBP)
   - Fetches account's business locations via GBP API
   - Creates GbpLocation records
```

### Service Architecture
```
GbpService (facade)
  ├── GoogleGbpProvider (real API — when Integration exists)
  └── MockGbpProvider  (fake data — when no connection)
```
Controllers only talk to `GbpService` — they never know which mode is active.

### Capabilities
- `listReviews(client, locationName)` — fetch reviews from Google
- `putReply(client, locationName, reviewId, comment)` — reply to a review
- `createPost(client, locationName, payload)` — publish a post
- `uploadPhoto(client, locationName, sourceUrl, description)` — publish a photo
- `fetchPerformanceMetrics(client, locationName, startDate, endDate)` — insights

### Conditions
- OAuth callback is OUTSIDE auth middleware (session can drop during redirect)
- State stored in Cache, not session
- Email verification required before connecting Google
- Disconnect removes the Integration record

---

## 8. Review Management

### Features
- **List reviews**: Per location, filterable, agency-scoped
- **Sync reviews**: Pulls latest from Google via GBP API
- **Reply to review**: Send reply through Google API
- **AI-generated reply**: Gemini generates a draft reply (credit-gated)
- **Draft reply**: Save AI reply as draft before sending
- **Sentiment analysis**: Local keyword matching (no AI call)
- **Auto-reply toggle**: Per-subscription setting
- **WhatsApp notification toggle**: Per-subscription setting

### Review Model
```
agency_id, gbp_location_id, google_review_id, reviewer_name, reviewer_photo,
star_rating, comment, sentiment, reply_text, draft_reply, replied_at, replied_by, review_time
```

### Sentiment (Local, No AI)
```php
// Positive words: wonderful, professional, best, great, recommend, excellent, love, good...
// Negative words: terrible, waited, frustrating, disappointed, expensive, rude, dirty...
// Score: +1 per positive, -1.2 per negative
// Result: POSITIVE (>0.5), NEGATIVE (<-0.5), NEUTRAL
```

### Conditions
- Sync requires a connected Google location
- AI reply generation requires: active plan + sufficient credits
- Reply is sent via `GbpService->putReply()` (real API or mock)
- Settings (auto_reply, wa_notify) stored on Subscription model

---

## 9. AI Service (Gemini)

### Configuration
```php
// config/services.php
'gemini' => [
    'key'         => env('GEMINI_API_KEY'),
    'model'       => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),  // cheapest
    'image_model' => env('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'),
],
```

### Cost Optimizations
1. **Cheapest model**: `gemini-3.1-flash-lite` ($0.25/$1.50 per million tokens)
2. **Thinking disabled**: `thinkingConfig => ['thinkingBudget' => 0]` in all calls
3. **Per-call token limits**: Review reply=150, Content=200, Keywords=300, Chat=400
4. **Local sentiment**: No AI call for sentiment analysis
5. **Short prompts**: All prompts condensed, no persona wrapper for JSON responses

### Core Method
```php
private function callGemini(string $prompt, int $maxTokens = 200, float $temperature = 0.7): ?string
{
    // Uses ?key= query parameter auth (not header-based)
    // URL: https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key=...
    // Returns null on failure (graceful degradation)
}
```

### Public Methods
| Method | Max Tokens | Use Case |
|---|---|---|
| `analyzeSentiment($text)` | 0 (no AI) | Local keyword matching |
| `generateReviewReply($name, $comment, $stars)` | 150 | AI review reply with fallback |
| `generateContent($prompt)` | 200 | Social media post copy |
| `rawPrompt($prompt, $maxTokens)` | varies | Direct prompt, no wrapper (JSON responses) |
| `chat($message)` | 400 | AI marketing assistant chat |
| `generateImage($prompt)` | N/A | Image generation (separate model) |
| `generateKeywords($business, $city, $industry)` | 300 | SEO keyword suggestions |

### Fallback Pattern
Every AI method has a local fallback:
```php
if ($this->key()) {
    $out = $this->callGemini(...);
    if ($out) return $out;
}
return "hardcoded fallback response";
```

### Image Generation
- Separate model: `gemini-2.5-flash-image`
- Returns base64 data URI
- Fallback: SVG placeholder with colored background

---

## 10. GBP Content (Posts & Photos)

### Posts
- **CRUD**: Create, read, update, delete posts
- **Publish**: Push to Google via GBP API
- **AI Generate**: AI-generated post body (credit-gated)
- **Statuses**: DRAFT, SCHEDULED, PUBLISHED
- **Fields**: gbp_location_id, type, body, image, cta_url, status, scheduled_at, published_at

### Photos
- **CRUD**: Upload, delete photos
- **Publish**: Push to Google via GBP API
- **AI Caption**: AI-generated caption (credit-gated)
- **Fields**: gbp_location_id, image, caption, status, scheduled_at

### Public Image Endpoints (outside auth)
```
GET /media/gbp-photo/{photo}      — raw photo bytes for Google to fetch
GET /media/gbp-post-image/{post}  — raw post image bytes for Google to fetch
```
These are public because Google's servers need to fetch the image via `sourceUrl`.

---

## 11. Social Media Publishing

### Platforms
- **Facebook**: Full publish via Meta Graph API (text + image)
- **Instagram**: Full publish via Meta Graph API (image required, two-step container flow)
- **LinkedIn**: Draft only (no API integration)
- **X (Twitter)**: Draft only (no API integration)

### Meta OAuth Flow
```
1. GET /clients/{client}/meta/connect → Meta consent screen
   Scopes: pages_show_list, pages_manage_posts, pages_read_engagement,
           instagram_basic, instagram_content_publish, business_management

2. GET /meta/callback → stores access_token in Integration (META_GRAPH)
   Stores: page_id, ig_user_id in Integration.meta JSON
```

### MetaService
```php
publish(Client $client, SocialPost $post): bool
// Facebook: /pageId/photos (image) or /pageId/feed (text)
// Instagram: Two-step — create container → publish container
// Not connected: returns true (mock success)
```

### SocialPost Model
```
agency_id, client_id, platform (FACEBOOK/INSTAGRAM/LINKEDIN/X),
body, media_urls (JSON array), status (DRAFT/PUBLISHED/FAILED), scheduled_at, published_at
```

### Features
- Post to multiple platforms
- AI caption generation (credit-gated)
- Retry failed posts
- Draft → publish workflow

---

## 12. Lead Management (CRM)

### Stages (Kanban)
```
NEW → CONTACTED → QUALIFIED → CONVERTED → LOST
```

### Lead Model
```
agency_id, client_id, name, phone, email, source, stage, score, notes
```

### Features
- Create leads manually
- Move between stages (drag/drop kanban)
- Delete leads
- Filter by stage/source

---

## 13. WhatsApp Integration

### Cloud API
- Uses Meta's WhatsApp Cloud API
- Send templated or free-form messages
- Track message status

### WhatsappMessage Model
```
agency_id, to_phone, from_phone, direction (OUTBOUND/INBOUND), template, body, status
```

### Config
```env
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_ACCESS_TOKEN=
```

---

## 14. Ad Reports

### AdReport Model
```
agency_id, client_id, network (GOOGLE_ADS/META_ADS),
campaign, spend, clicks, impressions, conversions, leads,
period_start, period_end
```

### Features
- Manual report entry (no API integration currently)
- View campaign performance metrics
- Filter by network/client/period

---

## 15. Business Insights

### Features
- Live Google performance metrics via GBP API
- Download as CSV
- Metrics: views, searches, calls, direction requests, website clicks

### Implementation
```php
GbpService->fetchPerformanceMetrics(client, locationName, startDate, endDate)
```

---

## 16. Google Audit

### Scoring (0-100)
| Check | Weight | Pass Condition |
|---|---|---|
| Google location connected | 15 | Has at least 1 location |
| Reviews collected | 15 | Total reviews > 0 |
| Healthy average rating | 20 | Average >= 4.0 (partial: >= 3.0) |
| Replying to reviews | 25 | Reply rate >= 80% (partial: >= 50%) |
| Negative reviews addressed | 25 | All negative reviews replied |

### Grades
```
>= 85: Excellent
>= 65: Good
>= 40: Needs work
<  40: Poor
```

### AI Tips
```php
$aiTips = $this->ai->rawPrompt(
    "Give 3 bullet-point tips to improve this business's Google ranking. {$summary}",
    150
) ?? 'Reply to all reviews, post weekly updates, keep business info accurate.';
```

### Conditions
- Requires active plan + 2 credits
- Uses `rawPrompt()` (no persona wrapper, JSON-friendly)
- Hardcoded fallback if AI unavailable

---

## 17. Competitor Analysis

### Flow
1. User enters competitor names (comma-separated, max 5) + city
2. System gathers user's review stats (total, average)
3. AI compares and returns JSON: summary, strengths, gaps, actions

### Prompt
```
Compare {name} ({avg}/5, {total} reviews) vs {competitors} in {city}.
Return ONLY JSON: {"summary":"2-3 sentences","strengths":["..."],"gaps":["..."],"actions":["4 steps"]}
```

### Conditions
- Requires active plan + 2 credits
- Max 5 competitors
- AI response parsed as JSON; falls back to static response

---

## 18. Local Rank Checker

### Two Modes

**Real (Google Places API)**:
```php
PlacesService->checkRank(keyword, location, businessName)
// Searches "{keyword} near {location}" via Places Text Search
// Scans top 20 results for business name match
// Returns: rank (1-20 or null), checked count, result list
```

**Fallback (AI Estimate)**:
```php
rawPrompt("Estimate Google local rank for {business} in {where} for keyword {keyword}...")
// Returns JSON: estimated_rank, summary, tips
```

### Conditions
- Requires active plan + 1 credit
- Places API key is separate from OAuth (GOOGLE_PLACES_API_KEY)
- Prefers real data; falls back to AI only when Places not configured or returns null

---

## 19. Keyword Generator

### Flow
1. User enters business name, city, optional industry
2. AI returns keyword groups: High intent, Local, Long-tail, Services

### Output Format
```json
{
  "groups": [
    {"group": "High intent", "keywords": ["best dentist in Mumbai", ...]},
    {"group": "Local", "keywords": [...]},
    {"group": "Long-tail", "keywords": [...]},
    {"group": "Services", "keywords": [...]}
  ]
}
```

### Conditions
- Requires active plan + 2 credits
- 5-7 keywords per group
- Fallback generates generic keywords from business name + city

---

## 20. One-Click Optimization

### Features
- Analyzes current business profile state
- Runs AI-powered optimization suggestions
- Credit-gated (3 credits per run)

---

## 21. AI Chat Mode

### Features
- Full-page marketing assistant chat
- Context: Google reviews, social media, local SEO, marketing advice
- 1 credit per message

### System Prompt
```
You are ReviewFlow AI, a marketing assistant for local businesses.
Help with Google reviews, social media, local SEO, and marketing advice.
Be practical and concise.
```

### Conditions
- Requires active plan
- Max 400 output tokens
- Returns `{ reply, source: 'ai'|'fallback' }`

---

## 22. AI Media Generation

### Features
- Text-to-image generation via Gemini
- Generated images stored as base64 data URIs
- "Use as Photo" — converts AI media to GBP photo
- Delete generated media

### AiMedia Model
```
agency_id, client_id, created_by, type, prompt, image_data (base64), model, source
```

### Conditions
- 5 credits per generation (most expensive action)
- Separate image model: `gemini-2.5-flash-image`
- Fallback: SVG placeholder (colored background with text)

---

## 23. Invoicing & Accounting

### Full Module
| Feature | Description |
|---|---|
| **Invoices** | Create, view, mark sent, mark paid, delete |
| **Customers** | CRUD (uses Client model) |
| **Services** | CRUD with categories and GST per service |
| **Service Categories** | Group services |
| **Expenses** | Track business expenses |
| **Billing Settings** | Company info, GSTIN, bank details, invoice prefix |
| **Tally Export** | Export data in Tally-compatible format |

### Invoice Model
```
agency_id, client_id, invoice_number, issue_date, due_date,
status (DRAFT/SENT/PAID), subtotal, gst_total, total, notes, paid_at
```

### InvoiceItem Model
```
invoice_id, service_id, description, quantity, unit_price, gst_percent, amount
```

### BillingSetting Model
```
agency_id, company_name, business_type, phone, email, gstin, address, state, logo,
bank_name, bank_account, ifsc, invoice_prefix, next_invoice_number,
default_gst, currency, round_total, tally_company_name, customer_ledger_group
```

### GST Features (India-specific)
- Per-service GST percentage
- Per-plan GST rate
- GST-inclusive pricing with base/GST split
- GSTIN stored on Client and BillingSetting
- Tally export for accounting integration

---

## 24. Team Management

### Features
- List team members (agency-scoped)
- Invite new team members (creates User with role)
- Update team member role/client assignment
- Remove team members

### Conditions
- Only `CLIENT_OWNER` can manage team
- New users get a random password (or invited via email)
- Team members can be assigned to specific clients

---

## 25. Admin Panel (Super Admin)

### Protected by
```php
Route::middleware(['auth', 'role:SUPER_ADMIN'])->prefix('admin')
```

### Features
| Feature | Routes |
|---|---|
| **Overview Dashboard** | GET /admin |
| **User Management** | CRUD users across all agencies |
| **Plan Management** | CRUD plans (name, price, GST, credits, features, permissions) |
| **Credit Packages** | CRUD packages, toggle active/inactive |
| **Billing/Payments** | View all payments, checkout, verify |
| **Manual Top-up** | Add credits to any agency |

### Admin bypasses
- `EnsureActivePlan` middleware skips for `SUPER_ADMIN`
- Admin can access all agencies' data

---

## 26. Health Score Dashboard

### Service: `HealthScoreService`

### Sub-Scores (6 categories)
| Category | What's Measured |
|---|---|
| **Reviews** | Rating + reply rate |
| **Google Posts** | Published vs total |
| **Photos** | Count (target: 10+) |
| **Social** | Published vs draft |
| **Leads** | Conversion rate |
| **Connections** | Google + Meta connected |

### Overall Score
Average of all 6 sub-scores (0-100).

### "Do Next" Actions
Prioritized list of up to 6 actions based on current state:
1. Connect Google (if not connected) — critical
2. Reply to pending reviews — high
3. Address negative reviews — high
4. Upload more photos (if < 10) — medium
5. Create Google posts (if < 4) — medium
6. Publish draft social posts — low
7. Follow up on new leads — high
8. Connect Meta — medium
9. Improve average rating (if < 4.0) — high
10. Add first location — critical

---

## 27. Email Verification

### Features
- Send verification email (signed URL)
- Verify email via signed link (no auth required for link)
- Change email address

### Routes
```
POST /email/send-verification   — send verification email
GET  /email/verify/{id}/{hash}  — verify (signed URL, no auth middleware)
POST /email/change              — change email
```

### Conditions
- Email verification required before connecting Google OAuth
- Uses Laravel's signed URL mechanism

---

## 28. Mobile API (Flutter)

### Auth: Laravel Sanctum Personal Access Tokens
```
Authorization: Bearer {token}
```

### Total Routes: 106+

### Structure
```
POST /api/login
POST /api/register
POST /api/auth/google  (throttle: 10/min)

middleware(auth:sanctum) {
    // All CRUD endpoints mirror web controllers
    // Same agency_id scoping
    // Same credit gating
    // JSON responses only

    middleware(plan) {
        // AI features
    }

    middleware(role:SUPER_ADMIN) {
        // Admin endpoints
    }
}
```

### API Controllers (18 total)
Located in `app/Http/Controllers/Api/`:
- AuthController, DashboardController, ClientController, ReviewController
- GbpContentController, LeadController, SocialController, BillingController
- WhatsappController, AdController, InsightsController, TeamController
- AuditController, CompetitorController, KeywordController, RankCheckerController
- OptimizationController, AiModeController, AiMediaController
- AdminController, InvoicingController

---

## 29. Environment Variables

```env
# ---- App ----
APP_NAME=ReviewFlow
APP_URL=http://localhost:8000
APP_TIMEZONE=Asia/Kolkata

# ---- Database (XAMPP MySQL) ----
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=reviewflow
DB_USERNAME=root
DB_PASSWORD=

# ---- Session/Cache/Queue ----
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

# ---- Google Gemini AI ----
GEMINI_API_KEY=                          # from https://aistudio.google.com
GEMINI_MODEL=gemini-3.1-flash-lite      # cheapest text model
GEMINI_IMAGE_MODEL=gemini-2.5-flash-image

# ---- Razorpay (Payment Gateway — INR) ----
RAZORPAY_KEY_ID=
RAZORPAY_KEY_SECRET=

# ---- Google Business Profile (OAuth) ----
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost:8000/google/callback

# ---- Google Login (separate credentials possible) ----
GOOGLE_LOGIN_CLIENT_ID=                  # falls back to GOOGLE_CLIENT_ID
GOOGLE_LOGIN_CLIENT_SECRET=              # falls back to GOOGLE_CLIENT_SECRET
GOOGLE_LOGIN_REDIRECT_URI=

# ---- Google Mobile (Flutter) ----
GOOGLE_MOBILE_CLIENT_ID=

# ---- Google Places API (Rank Checker) ----
GOOGLE_PLACES_API_KEY=

# ---- Meta / Facebook / Instagram ----
META_APP_ID=
META_APP_SECRET=
META_REDIRECT_URI=http://localhost:8000/meta/callback
META_GRAPH_VERSION=v21.0
META_CONFIG_ID=
META_VERIFY_TOKEN=

# ---- WhatsApp Cloud API ----
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_ACCESS_TOKEN=
```

---

## 30. Database Schema (Models)

### All 24 Models

| Model | Table | Key Relationships |
|---|---|---|
| `Agency` | agencies | hasMany: Users, Clients. hasOne: Subscription |
| `User` | users | belongsTo: Agency, Client. Uses HasApiTokens, Notifiable |
| `Client` | clients | belongsTo: Agency. hasMany: Locations, Leads, Invoices |
| `Subscription` | subscriptions | belongsTo: Agency. Tracks plan, credits, status |
| `Plan` | plans | Stores plan definitions (name, price, GST, features, permissions) |
| `GbpLocation` | gbp_locations | belongsTo: Client. hasMany: Reviews, Posts, Photos |
| `Review` | reviews | belongsTo: GbpLocation. Has sentiment, reply, draft_reply |
| `GbpPost` | gbp_posts | belongsTo: GbpLocation. Status: DRAFT/SCHEDULED/PUBLISHED |
| `GbpPhoto` | gbp_photos | belongsTo: GbpLocation. Status tracking |
| `Integration` | integrations | OAuth tokens (GOOGLE_GBP, META_GRAPH). JSON meta field |
| `SocialPost` | social_posts | Multi-platform posting. media_urls as JSON array |
| `Lead` | leads | Kanban stages: NEW/CONTACTED/QUALIFIED/CONVERTED/LOST |
| `WhatsappMessage` | whatsapp_messages | Direction: OUTBOUND/INBOUND |
| `AdReport` | ad_reports | Campaign metrics with date range |
| `Payment` | payments | Razorpay order + payment tracking |
| `CreditLedger` | credit_ledger | Full audit trail of credit changes |
| `CreditPackage` | credit_packages | Buyable credit packs with GST |
| `AiMedia` | ai_media | AI-generated images stored as base64 |
| `Invoice` | invoices | Status: DRAFT/SENT/PAID. GST split |
| `InvoiceItem` | invoice_items | Line items with per-item GST |
| `Service` | services | Billable services with categories |
| `ServiceCategory` | service_categories | Group services |
| `Expense` | expenses | Business expense tracking |
| `BillingSetting` | billing_settings | Company info, bank, GSTIN, Tally config |

---

## Quick Reference — Route Groups

### Public (no auth)
- Landing page, policy pages
- Google OAuth callback, Meta OAuth callback
- Email verification link
- Mobile login bridge
- GBP media endpoints (for Google to fetch)

### Auth (no plan required)
- Dashboard, Clients CRUD, Reviews (view/sync/reply), GBP Content (CRUD)
- Leads, Social (manual), WhatsApp, Ads, Insights
- Invoicing (full module), Team, Profile, Billing/Plans
- Optimization/Audit/Competitor/Keywords/RankChecker (view pages only)

### Auth + Plan (AI features)
- AI review reply generation
- AI post/photo caption generation
- AI social caption
- One-click optimize (run)
- Keyword generation
- Rank checker (check)
- AI media generation
- Google audit (run)
- Competitor analysis (run)
- AI chat mode
- Buy credits

### Auth + SUPER_ADMIN
- Admin overview
- User management (all agencies)
- Plan management
- Credit package management
- Billing management
- Manual credit top-up

---

## Key Patterns to Replicate

### 1. Multi-Tenancy via agency_id
Every model is scoped to an agency. Every controller reads `$request->user()->agency_id`.

### 2. Credit Gating
`canAfford()` → do work → `deduct()`. Transactional with row locking.

### 3. AI with Graceful Fallback
Every AI call has a static/hardcoded fallback. App works without API keys.

### 4. Dual Interface (Web + API)
Web controllers return Blade views. API controllers return JSON. Same business logic, different response format.

### 5. OAuth State in Cache
Google/Meta OAuth state stored in Cache (not session) to survive cross-domain redirects.

### 6. Service Provider Pattern
`GbpService` → `GoogleGbpProvider` (real) or `MockGbpProvider` (fake). Controllers never know which.

### 7. GST-Inclusive Pricing
All prices stored as GST-inclusive. Base and GST amounts computed on the fly.

### 8. Middleware Layering
```
auth → base features (free)
auth + plan → AI features (paid)
auth + role:SUPER_ADMIN → admin
```
