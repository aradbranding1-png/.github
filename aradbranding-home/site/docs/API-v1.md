# Arad Branding Trade Platform — Official API v1

Only **Super Admin** accounts can use this API. The Super Admin creates personal keys under **Profile menu → API و کلید دسترسی** (`/account/api`).

- Other members do not see this page.
- A key held by any other account is refused with `403 unauthorized_forbidden`.
- A key acts as its owner, and only within the scopes chosen for it.
- A key is shown once. Up to 5 active keys are allowed, with an expiry of 30, 90 or 365 days, or none.

Partner integrations such as Arad Contact use separate admin-issued keys (`ab_…`). See `API-arad-contact.md`.

## Basics

| | |
|---|---|
| Base URL | `https://aradbranding.app/api/v1` |
| Auth | `Authorization: Bearer ark_xxxxxxxx_…` |
| Format | JSON. Request bodies use `Content-Type: application/json`. |
| Envelope | `{"ok": true, "data": {…}, "message": ""}` |
| IDs | Threads and proposals use ULIDs. Traders use handles. Internal row ids are never exposed, except wallet transaction and message ids, which are used as cursors. |
| Times | UTC, ISO 8601 (`2026-10-03T08:15:00Z`) |
| Rate limit | 300 requests per minute per key, plus a per-IP limit. Every response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`. A `429` response comes with `Retry-After`. |
| Admin switch | When an administrator turns the members' API off, every call returns `503 api_disabled`. |

### Errors

Errors have `ok: false`. The `data.error` field holds a machine code; some errors also carry `data.details`.

| HTTP | `error` | Meaning |
|---|---|---|
| 401 | `unauthorized_invalid` / `_revoked` / `_expired` | The key is missing, wrong, revoked or expired. |
| 403 | `unauthorized_account` | The owner's account is suspended or banned. |
| 403 | `unauthorized_forbidden` | The key's owner is not a Super Admin. |
| 403 | `insufficient_scope` | The key lacks the endpoint's scope. `details.required_scope` names it. |
| 403 | `account_restricted` | The account is «محدود», so it is read-only and write endpoints refuse. |
| 402 | `insufficient_stars` | `details.required` and `details.missing` give the Star amounts. |
| 404 | `not_found` | The item does not exist or is not visible to this account. |
| 422 | `validation` / `not_allowed` / `idempotency_key_required` | `details` holds the per-field messages. `not_allowed` means, for example, that one side has blocked the other. |
| 429 | `rate_limited` | Too many requests for this key. |

## Scopes

| Scope | Endpoints |
|---|---|
| `profile.read` | `GET /me` |
| `wallet.read` | `GET /wallet` |
| `letters.read` | `GET /letters`, `GET /letters/{id}` |
| `letters.send` | `POST /letters`, `POST /letters/{id}/reply` |
| `proposals.read` | `GET /proposals/mine`, `GET /proposals/feed` |
| `notifications.read` | `GET /notifications` |
| `directory.read` | `GET /traders?q=`, `GET /traders/{handle}` |

## Endpoints

### `GET /me`
Returns the profile (`id`, `handle`, names, `company`, `trade_role`, `country`, `language`, `restricted`, `member_since`), the counters (unread letters and notifications, connections, letters sent and so on) and the Star balance (`stars`).

### `GET /wallet?before={transaction id}`
Returns the balance and 25 ledger rows, newest first.

- `type` is one of: `purchase`, `spend`, `reserve`, `release`, `refund`, `bonus`, `admin_credit`, `admin_debit`.
- A refund row carries `refund_of`, the id of the spend it returned.
- Pass `next` as `before` to get the next page.

### `GET /letters?folder=inbox|sent|archive&type=all|private|public|proposal|official&cursor=`
Returns 25 threads: `id`, `type`, `subject`, `preview`, `unread`, `last_message_at` and `peer` (`handle`, `name`, `company`, `country`). Pass `next` as `cursor` to get the next page.

### `GET /letters/{id}?before={message id}`
Returns one thread and its messages, oldest first. Reading a thread marks it as read.

- `from` is `"me"` or the sender's handle.
- A message hidden by moderation has `"hidden": true` and `"body": null`.
- When `older` is not null, pass it as `before` to get earlier messages.

### `POST /letters` — private letter (paid)
```http
POST /api/v1/letters
Authorization: Bearer ark_…
Idempotency-Key: crm-7f3a9c21
Content-Type: application/json

{"to": "atlas-trading", "subject": "Price list", "body": "Hello…"}
```
- `201` returns `{"replayed": false, "thread": "<id>", "stars_charged": 3, "balance": 117}`.
- Repeating the same `Idempotency-Key` returns `200` with `{"replayed": true, "thread": null, "stars_charged": 0}`: nothing is sent or charged again.
- The Star price is the same as on the site and depends on the sender's and recipient's countries.
- `Idempotency-Key` is required: 8–64 characters from `A–Z a–z 0–9 - _`. Retrying with the same key never sends or charges twice.
- Blocked pairs get `422 not_allowed`. A low balance gets `402 insufficient_stars`.

### `POST /letters/{id}/reply` — free
Send `{"body": "…"}`; a successful call returns `201`. Replies are refused when either side has blocked the other.

### `GET /proposals/mine`
Returns your proposals with `status` (`draft`, `published` or `inactive`), views, sends and dates.

### `GET /proposals/feed?category=&country=&type=&cursor=`
Returns the public proposal feed, newest first: `uid`, `title`, `type`, `owner`, `handle`, `country`, `tags` and `published_at`. Pass `next` as `cursor` to get the next page.

### `GET /notifications?cursor=`
Returns 30 notifications: `type`, `text` (Persian), `link`, `read` and `created_at`.

### `GET /traders?q=…` and `GET /traders/{handle}`
The search returns up to 20 active traders by name, company or handle. Each result has `handle`, `name`, `company`, `country`, `trade_role` and `verified`.

The single-trader endpoint also lists the trader's published pages with their `language`, `title`, `teaser` and `url`. Contact details are never returned.

## Security notes

- Keep keys on your server. Never put them in browser code or in a public repository.
- Revoke a key at once if it may have leaked: use `/account/api`, or ask an administrator, who can revoke any member key under **Admin → اتصال API**.
- Creating and revoking keys is recorded in the security audit log. Each key stores its last use time, last IP and request count.
- A key stops working while its owner is suspended or banned, and works again if the suspension is lifted.
