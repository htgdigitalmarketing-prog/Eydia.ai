# Eydia.ai — Mobile App API Reference: WhatsApp Dashboard

**For:** Android & iOS app developers
**Scope:** Every WhatsApp-dashboard feature in the web app, exposed as mobile APIs.
**Last updated:** 2026-11-14

The mobile apps use the **same REST API** as the web app (one backend, one contract).
There is no separate "mobile" API — every endpoint below is the live `/api/v1/*`
endpoint the web dashboard already uses, so the two stay in lockstep forever.

---

## 1. Basics

- **Base URL:** `https://eydia.in/api/v1`
- **Format:** JSON in, JSON out. Always send `Accept: application/json`.
- **Auth:** Bearer JWT — `Authorization: Bearer <token>` on every authed request.
- **Response envelope (success):**
  ```json
  { "success": true, "data": { ... } }
  ```
- **Response envelope (error):**
  ```json
  { "success": false, "message": "Human text", "errors": { "field": ["..."] }, "code": "optional_code" }
  ```
  Common HTTP codes: `401` (bad/expired token), `403` (not allowed / plan needed),
  `422` (validation), `429` (rate limit).

### 1.1 Error codes to handle in the app
| `code` | Meaning | App should… |
|---|---|---|
| `plan_required` | The action needs an active plan | Show the "Upgrade / buy a plan" screen |
| `feature_not_included` | Plan doesn't include this feature (e.g. GBP) | Show upgrade screen |
| `plan_required` on a **view** | (won't happen — views are free) | — |

### 1.2 Freemium model (important)
A client can log in and **explore for free**: connect WhatsApp, view the inbox,
reply 1-to-1, and browse templates/campaigns/chatbots/automations. Only **paid
actions** return `403 plan_required`. Each endpoint below is tagged **[free]** or
**[plan]**. Build the app so a `[plan]` `403` opens the purchase screen rather
than looking like an error.

### 1.3 Pagination
Two styles, both **opt-in** (omit the params to get the default page):
- **Page-based** (lists): `?page=<n>&per_page=<1..50>`. Response adds a `meta`
  block: `{ total, per_page, current_page, last_page }`.
- **Cursor-based** (inbox messages, infinite scroll): `?per_page=<n>&cursor=<token>`.
  Response returns `next_cursor` (string or `null`); pass it back as `?cursor=`.

---

## 2. Authentication & session

| Method | Path | Gate | Notes |
|---|---|---|---|
| POST | `/auth/login` | [free] | `{ email, password }` → `{ token, user }` |
| POST | `/auth/register` | [free] | `{ company_name, name, email, password, password_confirmation, phone? }` |
| POST | `/auth/google` | [free] | **Native Google sign-in**: `{ id_token }` (verified against the mobile client id) → `{ token, user }`. Returns `503 not_configured` if the mobile client id isn't set. |
| POST | `/auth/otp/request` | [free] | `{ phone }` → sends a login OTP |
| POST | `/auth/otp/verify` | [free] | `{ phone, code }` → `{ token, user }` |
| GET | `/auth/me` | [free] | Current user + tenant + `subscription` + `plan_features` + `plan_limits` |
| PUT | `/auth/profile` | [free] | Update name/phone/avatar etc. |
| POST | `/auth/change-password` | [free] | `{ password, password_confirmation }` |
| POST | `/auth/refresh` | [free] | Refresh the JWT (returns a new `token`) |
| POST | `/auth/logout` | [free] | Invalidate the current token |
| DELETE | `/auth/account` | [free] | Delete the account |

### 2.1 Web → app hand-off (open a web flow, come back signed in)
For flows that must run in a browser/WebView (e.g. WhatsApp Embedded Signup):
1. App calls **POST `/auth/web-session`** → `{ login_url }`.
2. App opens `login_url` in a WebView/browser; the server signs the session and
   issues a fresh token to that web context.
3. For the reverse (web → app), **GET `/auth/mobile-login/{code}`** redirects to
   `eydia://…?token=<jwt>` so the app captures the token from the deep link.

> The JWT lifetime is 60 min; refresh lasts 30 days. Use `/auth/refresh` before expiry.

---

## 3. Push notifications (device registration)

Inbound WhatsApp messages (and other events) already fire push notifications
server-side. The app just registers its push token:

| Method | Path | Gate | Body |
|---|---|---|---|
| GET | `/devices` | [free] | list this user's registered devices |
| POST | `/devices` | [free] | `{ token, platform: "android"\|"ios", … }` register/refresh |
| DELETE | `/devices` | [free] | `{ token }` unregister (on logout) |

Push payloads for a new WhatsApp message carry
`{ type: "whatsapp_message", conversation_id: "<uuid>" }` — deep-link into that chat.

---

## 4. WhatsApp — Home / numbers

| Method | Path | Gate | Notes |
|---|---|---|---|
| GET | `/whatsapp/overview` | **[free]** | **Mobile home summary** (new): `{ numbers[], numbers_count, default_number, conversations:{total,open,unread}, messages_today:{inbound,outbound} }` |
| GET | `/whatsapp/numbers` | [free] | Connected numbers (full list) |
| GET | `/whatsapp/accounts` | [free] | Connected WABAs with their numbers |
| GET | `/whatsapp/config` | [free] | `{ app_id, config_id, api_version, embedded_signup_configured }` (for the connect UI) |
| POST | `/whatsapp/connect-manual` | [free] | Connect a number by hand: `{ waba_id, phone_number_id, display_phone_number, access_token, name?, verified_name? }` → `{ number }`. **Recommended connect path for mobile.** |
| POST | `/whatsapp/embedded-signup` | [free] | Finishes Meta Embedded Signup: `{ code, waba_id, phone_number_id? }`. Needs the Facebook JS SDK → run in a **WebView**, not natively. |
| POST | `/whatsapp/numbers/refresh` | [free] | Re-pull numbers from Meta |
| POST | `/whatsapp/numbers/{number}/sync` | [plan] | Refresh one number's metadata from Meta |
| POST | `/whatsapp/numbers/{number}/register` | [free] | `{ pin: "6-digit" }` register on Cloud API |
| POST | `/whatsapp/numbers/{number}/subscribe-app` | [free] | Subscribe the app to the WABA (fixes #200) |
| PUT | `/whatsapp/numbers/{number}/token` | [free] | `{ access_token }` update the WABA token |
| POST | `/whatsapp/numbers/{number}/send-test` | [free] | `{ to, type?: "template"\|"text", template?, language?, body? }` |
| DELETE | `/whatsapp/numbers/{number}` | [free] | Disconnect a number |

> `{number}` is the number's UUID (from `/whatsapp/numbers`).

---

## 5. WhatsApp — Inbox

| Method | Path | Gate | Notes |
|---|---|---|---|
| GET | `/whatsapp/conversations` | [free] | `?status=&search=&page=&per_page=25`. Returns conversations + `meta` |
| GET | `/whatsapp/conversations/{conversation}/messages` | [free] | **Cursor** paginated: `?per_page=50&cursor=`. Returns `messages[]` + `next_cursor` |
| POST | `/whatsapp/conversations/{conversation}/mark-read` | [free] | Clears unread |
| POST | `/whatsapp/conversations/{conversation}/send` | **[free]** | 1-to-1 reply: `{ type?: "text"\|"template", body (required for text, ≤4096), template?, language? }` |
| POST | `/whatsapp/conversations/{conversation}/send-media` | [free] | Multipart: `file` + optional `caption`, `type` (image/video/document) |
| DELETE | `/whatsapp/conversations/{conversation}/messages/{message}` | [free] | Delete a message |
| GET | `/whatsapp/media/{uuid}` | [free] | Public (unguessable UUID) media file — use as an `<img>`/download URL |

> `{conversation}` / `{message}` are UUIDs. 1-to-1 replies are **free**; bulk is paid (§8).

---

## 6. WhatsApp — Contacts, Hot List, Black List, Tags

| Method | Path | Gate | Notes |
|---|---|---|---|
| GET | `/contacts` | [free] | `?search=&tag=&hot=&page=&per_page=25` (Hot List = `?hot=1`) |
| GET | `/contacts/{uuid}` | [free] | One contact |
| POST | `/contacts` | [plan] | Create a contact |
| POST | `/contacts/import` | [plan] | Bulk import |
| PUT | `/contacts/{uuid}` | [plan] | Update |
| DELETE | `/contacts/{uuid}` | [plan] | Delete |
| GET | `/blacklist` | [free] | List |
| POST / DELETE | `/blacklist` · `/blacklist/{uuid}` | [plan] | Add / remove |
| GET | `/tags` | [free] | List |
| POST / DELETE | `/tags` · `/tags/{uuid}` | [plan] | Add / remove |

> Incoming leads auto-create contacts and may be auto-tagged **Hot** by AI, so the
> free Contacts/Hot List views populate on their own.

---

## 7. WhatsApp — Templates

| Method | Path | Gate | Notes |
|---|---|---|---|
| GET | `/templates` | [free] | `?status=APPROVED&search=&category=&page=&per_page` |
| GET | `/templates/{uuid}` | [free] | One template with components |
| POST | `/templates/upload-sample` | [plan] | Multipart `file` (jpg/png/mp4/pdf ≤5 MB) → `{ header_handle }` for a media header |
| POST | `/templates` | [plan] | Create & submit to Meta. Body: `{ name, language, category, header_format?, header_text?, header_handle?, body, body_example?[], footer?, buttons?[] }`. PHONE_NUMBER buttons are auto-normalised to E.164. |
| POST | `/templates/sync` | [plan] | Pull templates & statuses from Meta |
| DELETE | `/templates/{uuid}` | [plan] | Delete |

---

## 8. WhatsApp — Campaigns & Bulk send

**Campaigns**
| Method | Path | Gate | Notes |
|---|---|---|---|
| GET | `/campaigns` | [free] | `?page=&per_page=25` |
| GET | `/campaigns/{uuid}` | [free] | Detail incl. sent/delivered/read/failed counts |
| POST | `/campaigns` | [plan] | Create |
| POST | `/campaigns/{uuid}/start` · `/pause` · `/cancel` | [plan] | Control |
| DELETE | `/campaigns/{uuid}` | [plan] | Delete |

**Bulk send** (all [plan])
| Method | Path | Notes |
|---|---|---|
| GET | `/whatsapp/bulk-sends` | `?page=&per_page=20`; each row has `sent_count`, `delivered_count`, `failed_count` |
| GET | `/whatsapp/bulk-sends/{uuid}` | Detail + recipients (real delivered/failed from status webhooks) |
| POST | `/whatsapp/bulk-sends/{uuid}/retry` | Retry failed recipients |
| POST | `/whatsapp/bulk-send` | Start a bulk send |
| POST | `/whatsapp/bulk-send/upload-header` | Upload a header image for the send |

> Delivery counts reflect **real** delivery (reconciled from Meta status webhooks),
> including cold numbers.

---

## 9. WhatsApp — Chatbot

| Method | Path | Gate | Notes |
|---|---|---|---|
| GET | `/chatbots` | [free] | `?page=&per_page=25` |
| GET | `/chatbots/{uuid}` | [free] | Detail + rules |
| GET | `/chatbots/lead-flow` | [free] | Ready-made lead funnel status |
| POST | `/chatbots` | [plan] | Create |
| PUT / DELETE | `/chatbots/{uuid}` | [plan] | Update / delete |
| POST | `/chatbots/{uuid}/toggle` | [plan] | Enable/disable |
| POST | `/chatbots/lead-flow/install` | [plan] | Install the lead funnel |
| POST | `/chatbots/{uuid}/rules` · PUT/DELETE `/…/rules/{rule}` | [plan] | Manage keyword rules |

---

## 10. WhatsApp — Automations

| Method | Path | Gate | Notes |
|---|---|---|---|
| GET | `/automations` | [free] | List (show as "visible but locked" until a plan) |
| GET | `/automations/{uuid}` | [free] | Detail (nodes + edges) |
| GET | `/automations/{uuid}/executions` | [free] | Run history |
| POST | `/automations` | [plan] | Create |
| PUT | `/automations/{uuid}` · `/automations/{uuid}/canvas` | [plan] | Edit / save canvas |
| POST | `/automations/{uuid}/activate` · `/pause` | [plan] | Turn on/off |
| DELETE | `/automations/{uuid}` | [plan] | Delete |

---

## 11. WhatsApp — Analytics

| Method | Path | Gate | Notes |
|---|---|---|---|
| GET | `/analytics/dashboard` | [plan] | Top-line counts |
| GET | `/analytics/messages` | [plan] | `?days=30` — daily funnel `{ sent, delivered, read, failed, inbound }` + totals + rates (matches Meta Insights) |
| GET | `/analytics/campaigns` | [plan] | Per-campaign stats |

> For a **free** home summary (no plan), use **`/whatsapp/overview`** (§4) instead
> of `/analytics/dashboard`.

---

## 12. Billing & credits (to drive the in-app upgrade screen)

| Method | Path | Gate | Notes |
|---|---|---|---|
| GET | `/billing/plans` | [free] | Plans to show on the upgrade screen |
| GET | `/billing` · `/billing/wallet` · `/billing/invoices` | [free] | Current plan, wallet, invoices |
| POST | `/billing/order` → `/billing/verify` | [free] | Razorpay one-time purchase (create order → verify payment) |
| POST | `/billing/subscribe-auto` → `/billing/verify-subscription` | [free] | Razorpay autopay subscription |
| GET | `/credits` · `/credits/packs` | [free] | AI credit balance & packs |
| POST | `/credits/packs/{uuid}/order` → `/credits/packs/confirm` | [free] | Buy a credit pack |

---

## 13. App config

| Method | Path | Gate | Notes |
|---|---|---|---|
| GET | `/app/config` | [free, no auth] | Force-update / maintenance switch + public config. Call on launch. |

---

## Appendix A — The convention (so new features auto-expose a mobile API)

The web app and the mobile apps share **one** API. This is deliberate and means
**every new web feature is automatically available to the apps** when it follows
these rules (which the backend already enforces):

1. **One REST endpoint per action**, under `/api/v1/<module>`, returning the
   `{ success, data }` envelope — never HTML or web-only responses.
2. **Reads are `GET` and free; writes are `POST/PUT/DELETE`** and gated with the
   `plan` (or `feature:*`) middleware. This gives the app the same freemium
   behaviour for free.
3. **List endpoints accept `?page`/`?per_page`** (or a cursor for chat-style
   feeds) so the app can infinite-scroll.
4. **No session/cookie state** — auth is the bearer JWT only.
5. **Long/branchy browser flows** (OAuth, Meta Embedded Signup) expose a
   `?mobile=1` variant that finishes via an `eydia://…` deep link (see GBP), or
   are opened in a WebView via the `/auth/web-session` hand-off.
6. **User-visible events raise a push** via the device registry (§3).

When a new feature ships, this document is updated and a fresh PDF is generated,
so the app team always has the current contract.

---

*Generated for the Eydia.ai Android/iOS build. Questions → the backend team.*
