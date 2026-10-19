# Heltog SocialFlow — Features & Business Rules Reference

A complete map of every feature in this project and the **conditions/rules** that
govern it, so the same capabilities can be rebuilt in another project.

- **Backend:** Laravel 12 (PHP 8.2+), MySQL, JWT auth (`php-open-source-saver/jwt-auth`), Spatie laravel-permission.
- **Frontend:** Next.js 16 (React), inline styles + a global CSS file.
- **Channels:** WhatsApp Cloud API (Meta Graph API), Facebook Pages, Instagram Business.
- **AI:** Google Gemini and/or Anthropic Claude (pluggable).

---

## 1. Multi-tenancy & data isolation (foundational rules)

- Every business is a **tenant**. All tenant-owned tables carry `tenant_id`.
- A global **`BelongsToTenant`** scope auto-filters every query to the logged-in user's tenant (reads `auth()->user()->tenant_id`). Models use `HasUuid` (public id = uuid, never the internal auto-increment id) and `TracksBlame` (created_by/updated_by).
- **Condition:** cross-tenant or console/background work must use `Model::withoutGlobalScopes()` and pass `tenant_id` explicitly (the scope has no auth user there).
- **Condition:** an **agent** (non-admin) only ever sees the conversations **assigned to them**; admins/owners see the whole workspace. This is enforced server-side, not just in the UI.
- Super-admin is a **platform account with no tenant** — it manages companies/plans, not tenant feature pages.

## 2. Authentication & access

- **Login methods (all issue the same JWT):**
  - Email + password.
  - Google OAuth (browser redirect flow).
  - **Mobile OTP** — `POST /auth/otp/request {phone}` and `POST /auth/otp/verify {phone, code}`.
- **OTP rules:** 6-digit code, **5-minute** expiry, **5-attempt** limit, **30-second** resend cooldown. Code stored **hashed** (`login_otps` table). The user is matched by the **trailing 10 digits** of the phone saved on their account. **Condition:** OTP login works only for users who have a phone saved on their profile; an unregistered number gets a generic success response (no enumeration) but cannot log in.
- **OTP delivery (pluggable, in order):** WhatsApp **authentication template** from one platform number → MSG91 SMS → log only (dev). Config: `OTP_WHATSAPP_TEMPLATE`, `OTP_WHATSAPP_LANG` (must match the template language exactly, e.g. `en_US`), `OTP_WHATSAPP_PHONE_NUMBER_ID` (send all OTPs from one number so the template is approved on one account), `OTP_WHATSAPP_TYPE=authentication` (adds the code to the copy-code button).
- **Roles & permissions:** Spatie permissions. `Gate::before` grants super-admin everything; a `Gate::after` DB-fallback covers stale permission cache. Owners can create **Agents** only (not other Admins). Each agent's feature access is configurable (feature keys per user).
- **Profile:** name, email, phone, **address**, and **avatar** (stored as a base64 data URL in a `MEDIUMTEXT` column; the client downscales to ~400px JPEG on white before upload). Password change requires the current password (except super-admin).

## 3. Plans & billing (gating rules)

- Plans have **feature flags** (social, chatbot, automations, reports, …) and **numeric limits** (whatsapp_numbers, agents, contacts, campaigns, workflows, storage_mb, api_access).
- **Gating conditions:**
  - No active plan → the whole workspace is blocked except billing/support (a "no plan" gate).
  - Expired plan → a banner + most pages blocked until renewal.
  - A nav item/feature hides when the plan's feature flag is off; a numeric limit of 0 blocks that action.
  - `plan_features`/`plan_limits` are `null` for super-admin / no-tenant → no gating.
- Public pricing for a marketing page: `GET /public/plans` (no auth).
- Payments via Razorpay (order → verify). Invoices carry the company logo.

## 4. WhatsApp — numbers & inbox

- **Connect a number:** Embedded Signup (Facebook Login for Business, `META_CONFIG_ID` = a **WhatsApp Embedded Signup** variation config) **or** manual (waba_id, phone_number_id, access_token).
- **Shared inbox:** one thread per contact+number. Assign/transfer chats to agents.
- **Messaging rules (Meta's, enforced here):**
  - A **template** message can be sent to anyone, anytime.
  - A **free-form** (plain text/media) message can only be sent inside the **24-hour customer-service window**, which **opens only when the customer messages you** (resets on each inbound).
  - Inbound media is downloaded and stored; outbound sends record a `Message` row + update the conversation preview/unread.
- **Delivery status** comes from Meta status webhooks (sent → delivered → read, or **failed** with an error code). A failed outbound message shows a **plain-English reason** in the inbox (e.g. "number not on WhatsApp", marketing frequency cap, 24h window, template problem) derived from the Meta error code.
- **Condition:** a high "failed" count usually means messages to **cold/unreachable numbers** (purchased lists), not a bug — Meta genuinely rejects those.

## 5. Templates

- Build a template with a **live WhatsApp preview** (header image/video/doc with real media preview, body with `{{n}}` variables, footer, buttons) and submit to Meta for approval.
- A **sample media** upload returns a `header_handle` for image/video/document headers (Meta requires a sample for review).
- **Condition:** only **APPROVED** templates can be sent. Status syncs from Meta. The language code must match exactly when sending (`en` vs `en_US`).

## 6. Campaigns & bulk send

- Blast an approved template to many numbers (paste, or **CSV import** that pulls numbers from any column and de-dupes).
- Header image per send: upload (→ media id) or paste a URL; **retry** only the failed numbers.
- **Mirror into inbox:** a successful bulk send is mirrored as an outbound message **only into chats that already exist** — it does **not** create a chat/contact for every cold number (that previously flooded the inbox and inflated "failed" analytics).
- **Processing without a queue worker:** send runs inline for a few seconds, then continues in `app()->terminating()` after the HTTP response; a scheduled command resumes any send whose worker died (compare-and-swap on `updated_at` so no double-send). Sends older than 24h are closed out instead of resent.
- **Cleanup/restore (admin CLI, not API):** `whatsapp:cleanup-broadcasts` soft-deletes cold-broadcast chats (0 inbound ever); `whatsapp:restore-broadcasts` undoes it. Dry-run by default, confirmation required.

## 7. Contacts, Hot List, Black List

- Contacts are the business's customer directory: name, phone, email, company, **tags** (plain comma-separated `tag_list`), source, language, assigned agent. Bulk import (CSV / API, up to 5000).
- **Hot List** = contacts tagged `Hot` (the AI flags buying intent). **Black List** = numbers you won't message.
- **Condition:** importing contacts does **not** let you free-form message them — templates reach anyone, free text needs the 24h window. Contacts organise/segment/reuse the audience.

## 8. Chatbot (auto-reply)

- Per inbound message: if a **keyword rule** matches → reply instantly (text or template, **no AI, zero wait**); else if **AI is enabled** → the AI answers from the business's **instructions/knowledge** (now effectively **unlimited** length, `MEDIUMTEXT`) in the customer's language; else a **fallback message**.
- **AI provider rules:** tries a provider **chain** (preferred first via `AI_PROVIDER`, the other as fallback) — Gemini (`GEMINI_API_KEY`) and/or Anthropic (`ANTHROPIC_API_KEY`). If one fails (invalid/expired key, **ran out of prepaid credit**), it rolls over to the other.
- **Conditions:** the chatbot skips chats **assigned to an agent**; only text + image/PDF are handled (image/PDF read by AI vision). Keep a **fallback message** set so customers always get a reply when AI is down. Diagnose with `php artisan chatbot:diagnose`.
- **Latency:** the reply is sent first; hot-lead detection + automations run **after** the webhook responds (in `terminating()`), and the AI context is trimmed (fewer history turns, lower output cap) to keep replies fast. A few seconds is normal for an LLM; keyword rules are instant.
- **Hot-lead detection & follow-ups:** the AI tags buying-intent contacts `Hot`; quiet customers get up to two auto follow-ups (e.g. +6h, +12h) via scheduler.

## 9. Automations (visual workflow engine)

- Design a flow on a canvas: a **trigger** → **condition/action/wait** nodes connected by **edges**. Activate it and the engine runs it per contact.
- **Triggers:** `new_contact`, `incoming_message`, `keyword` (with a configured word), `tag_added`, `tag_removed`, `scheduled` (run_at).
- **Node types/config:**
  - **action:** `send_message{message}`, `send_template{template_name,language}`, `add_tag{tag}`, `remove_tag{tag}`, `assign_agent{agent_email}`, `update_contact{...}`, `webhook{url}`.
  - **condition:** `condition_has_tag{tag}`, `condition_keyword{keyword}`.
  - **wait:** `wait{minutes}`, `wait_for_reply{}`.
- **Edge branches:** a condition's edge can be labelled `yes`/`no`; unlabelled edges make the condition a **gate** (TRUE continues, FALSE ends).
- **Engine rules:** runs best-effort (never breaks message intake); a loop guard caps steps; a run **pauses** on wait/wait-for-reply and resumes later (timed waits via the `workflows:run` scheduler every minute, reply-waits when the customer messages); it won't stack a second run of the same workflow on a contact while one is in flight. Message text supports `{{name}}`/`{{phone}}`; free text still needs the 24h window (use send_template otherwise).
- **Chatbot vs Automations:** chatbot = simple keyword/AI auto-reply; Automations = multi-step flows. They are separate systems.

## 10. Social (Facebook & Instagram publishing)

- Publish photos (feed **Post**) and videos (**Reel**) to a **Facebook Page** and its linked **Instagram Business** account. Compose with caption, hashtags, optional location (folded into the caption), schedule-for-later, and a live preview. Post history with publish-now / edit / delete.
- **Connect:** "Continue with Facebook" (Facebook **Login for Business**, needs `META_SOCIAL_CONFIG_ID` = a **General** variation config, **separate** from the WhatsApp config) → pick a Page (searchable) → its Instagram is auto-detected. A **manual Page ID + token** option works without the login dialog.
- **Meta conditions (required for the login dialog to work for *customers*):** Facebook Login for Business product + a **configuration** (config_id) with the needed permissions (`pages_show_list`, `pages_manage_posts`, `pages_read_engagement`, `instagram_basic`, `instagram_content_publish`) using a **User access token**; the app must be **Live**, have **Advanced Access** (App Review) on those permissions, and **Business Verification**. Until then only app roles can use it; customers use manual connect.
- **Rule:** Instagram has **no standalone connect** — it is reached through its linked Facebook Page. Instagram feed video = Reel. Instagram posts can't be deleted via API (remove in the app).

## 11. Analytics

- `GET /analytics/dashboard` (totals), `GET /analytics/messages?days=N` (per-day sent/delivered/read/failed/inbound — **current message status**), `GET /analytics/campaigns` (per-campaign).
- **AI data assistant** (`POST /assistant/ask {question, language}`): answers only from the signed-in user's **own scoped snapshot** (an agent sees only their assigned chats) — structurally cannot reveal another business's or agent's data. Replies in a chosen language (English default + Hinglish and major Indian languages).

## 12. Agents & Team

- **Team:** add agents, toggle active, pick exactly which features each can access; see each member's avatar.
- **Agents roster:** per-agent live workload (active chats, total handled); assign/unassign chats.
- **Condition:** assigning a chat to an agent makes the chatbot skip it (a human owns it).

## 13. Mobile app (React Native) API

- The mobile app uses the **same** REST API + JWT. Push via **Expo** (no key) or **FCM** (`FCM_SERVER_KEY`): register the device token at `POST /devices`.
- Base URL (prod) `/api/v1`. Every response is `{ success, data }` or `{ success:false, message, errors? }`. Rate limit ~120/min. See the API reference PDF for every endpoint.

## 14. Cross-cutting operational conditions (gotchas to replicate)

- **No queue worker:** background work uses *inline + `app()->terminating()` + a stalled-resume scheduler*. Needs the host cron `* * * * * php artisan schedule:run`.
- **Deploy steps:** after a backend deploy run `php artisan migrate --force` and `php artisan optimize:clear` (route/config cache must be cleared or new routes/env won't load).
- **Meta app config is separate from code:** WhatsApp working ≠ Facebook/Instagram working — each product needs its own permissions/config/review. Keep **separate** config_ids per use case (`META_CONFIG_ID` for WhatsApp Embedded Signup, `META_SOCIAL_CONFIG_ID` for social).
- **AI costs money:** Gemini/Anthropic are prepaid/metered; set auto-reload and a fallback message so the chatbot never goes silent.
- **Large text in DB:** base64 avatars and long AI knowledge need `MEDIUMTEXT`, not `TEXT` (TEXT = 64 KB → "data too long" 500s). Downscale images client-side.
- **Security:** `APP_ENV=production` + `APP_DEBUG=false` in production. Dev-only conveniences (e.g. echoing an OTP in the response) are gated to local/testing. Choose privacy-preserving defaults; never put personal data in URLs.
- **Phone matching:** compare the **last 10 digits** (strip `+`, spaces, hyphens) so `+91 98765 43210`, `919876543210` and `9876543210` all match. Avoid `REGEXP_REPLACE` (MySQL 8+ only) — use nested `REPLACE`.

---

*Generated as a portable feature/rules reference for Heltog SocialFlow.*
