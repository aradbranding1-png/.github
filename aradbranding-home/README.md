# aradbranding.app — Home page redesign (v1.12.5)

A redesign of **only the home page** (`/`) of aradbranding.app as a "global trade command center":
a real WebGL Earth (three.js) with day/night shading, atmosphere, clouds and city lights, curved
animated maritime and air routes, 3D container ships and cargo aircraft moving along them, floating
market cards anchored to the globe, and the rest of the page rebuilt in the dark navy / cyan / gold style.

No APIs, auth, database schema or other public pages were changed. v1.10.1 adds an admin editor for the home page
(**Admin → «صفحه اصلی سایت»**, `/admin/home`, requires the `settings.manage` permission): every text, link, list
(add / delete / reorder rows), image and section switch of the home page, stored as one JSON value in the existing
`settings` table (`home.content`), plus “reset to defaults”. Every save is audited.

![Desktop](screenshots/desktop-1536.webp)

## Install

**Option A — built-in updater (recommended):** Admin → «بروزرسانی سامانه» → upload
`dist/aradbranding-1.12.5.zip`. It contains only the files below plus `VERSION`, `CHANGELOG.md`
and an unchanged `bootstrap/app.php` (the updater requires it to recognise the package). No migrations.

**Option B — File Manager:** copy the contents of `site/` over the site root.

## Files

| Path | Change |
| --- | --- |
| `app/Views/public/landing.php` | Rewritten home view (all previous content kept: how it works, features, Stars, countries, FAQ + FAQ schema, CTA) |
| `app/Views/public/_trade_sprite.php` | New: SVG sprite (flags, market skylines, product art, icons, banner art) |
| `app/Modules/System/HomeContent.php` | New: home content defaults, merge and input sanitizing (links limited to `/…`, `#…`, `http(s)://…`) |
| `app/Modules/Admin/HomeAdminController.php`, `app/Views/admin/home.php`, `public_html/assets/admin-home.css` | New: `/admin/home` editor with image uploads (existing `ImageUploader`) |
| `routes/web.php`, `app/Views/admin/_nav.php` | 3 routes (`GET/POST /admin/home`, `POST /admin/home/reset`) and the admin menu item |
| `app/Modules/System/HomeController.php` | Also passes country ids (for `/discover/country/{id}` links) and per-country counts when public stats are on |
| `public_html/assets/trade-home.css` / `trade-home.js` | New page styles and small UI script (counters, menu, reveal) |
| `public_html/assets/trade-globe.js` | New: bundled 3D globe (three.js r186 tree-shaken, ~158 KB gzip) |
| `public_html/assets/globe/*.webp` | Earth textures (day, night lights, water mask, clouds) |

## How it maps to the existing site

- Header links: `/`, `/discover`, `/proposals`, `/connections`, `/letters`, `#how`, `#stars`, `#faq`; search posts to the existing `/search`; `/login`, `/register`.
- Market cards (globe + section) → `/discover/country/{id}` (ids from `ReferenceData`).
- «بازار بعدی خود را پیدا کنید» submits `q`, `country`, `type` to `/search` — the only filters the backend supports.
- Module cards → `/proposals`, `/discover`, `/connections`, `/letters`, `/pages`, `/wallet`.
- Numbers: when Admin → Settings → «نمایش آمار عمومی» is on, the strip, cards and bars show real cached totals (`MetricsService`). When off, the strip shows platform facts (243 countries, 27 languages, 6 proposal types, free membership) and cards show no counts. Nothing is invented.
- The page stays publicly cacheable and CSP-compliant (no inline styles/scripts, everything self-hosted).

## Performance / fallbacks

- Lower DPR, 1k textures, no clouds, fewer routes/ships on phones and low-memory devices; rendering pauses when the hero is off-screen or the tab is hidden.
- A textured poster globe shows while textures load and stays as the fallback without WebGL (or if the context is lost). Cards get static positions then.
- `prefers-reduced-motion`: no auto-rotation, slower movement, no reveal animations.

## Rebuilding the globe bundle

```
cd src/trade-globe && npm install && npm run build   # writes site/public_html/assets/trade-globe.js
```

Earth textures are from the three.js examples (NASA Blue Marble / Black Marble derived), converted to WebP.

## v1.11.0 — signed-in panel

- `layouts/app.php` + `public_html/assets/panel-theme.css` + `panel.js`: the whole signed-in app (dashboard, letters, proposals, admin) restyled like the home page; smaller type; mobile drawer; notifications dropdown (`GET /notifications/peek`).
- Sidebar: «ارتباطات اختصاصی» (`/letters?type=private`) under «خانه» with an unread badge (`unread_private`, added to the existing user query in `Core/Auth/Auth.php`, indexed); «پیشنهادات».
- Admin → Settings: «خرید Stars از درگاه پرداخت فعال باشد» (`payments.purchase_enabled`, default **off**). When off the purchase section and every «خرید Stars» button are hidden and `POST /wallet/buy` is refused. Files: `Core/Http/Controller.php`, `Wallet/WalletController.php`, `Pages/PublicPageController.php`, `Admin/SettingsController.php`, `admin/settings.php`, `wallet/index.php`, `public/page.php`, `letters/compose.php`, `letters/campaign.php`, `proposals/send.php`.

## v1.12.0 — admin command center and sign-in

- `layouts/app.php`, `panel-theme.css`, `panel.js`: light workspace (default) + dark navy sidebar holding every admin section (same permission checks as `admin/_nav.php`, whose in-page menu is now hidden); header with title, Jalali date, search, notifications, theme toggle, profile menu (role from `user_roles`, added to the user query in `Core/Auth/Auth.php`). `theme.js` honours `data-default-theme`.
- `/admin` dashboard (`DashboardAdminController::dashboard`, `admin/dashboard.php`): KPIs with week-over-week growth and sparklines, trend chart, today's checks, composition donut, latest platform events, newest proposals, revenue — all from `daily_metrics` (new `MetricsService::window()`, one query), cached totals, `proposal_feed` and `audit_logs`.
- `layouts/guest.php`, `auth/login.php`, `auth.css`, `auth.js`: login/register on the WebGL globe (`data-mode="login"` in `src/trade-globe/globe.js`: fewer routes, 3 ships, 2 planes, `tg:launch` zoom on submit). Same form fields and routes (email + password); no password-recovery link because the system has no recovery route.
- `public_html/assets/app.js`: the searchable select no longer auto-focuses its search box on touch screens (no keyboard pop / page jump).

## v1.12.1

- Auth pages fit one desktop frame (card scrolls inside), slogan no longer overlaps, login title/CTA texts, register field alignment, joined phone control, avatar hint under the button.
- `app.js` searchable select: list-only scrolling (no page jump) and no auto-focus on touch screens; home dropdown capped to its field width.
- Panel theme: `data-default-theme="system"` follows `prefers-color-scheme` live; the theme button cycles automatic → light → dark (`sadt-theme-mode`).

## v1.12.2

- Sign-in globe: green land corridors (`LAND_ROUTES` in `globe.js`, login mode only) Iran → Iraq / Afghanistan / Turkey with moving trucks; slimmer register card; auth brand text «سامانه توسعه تجارت».

## v1.12.3

- Home globe: Iran → Africa composite routes (`AFRICA_SEA` + green `AFRICA_LAND` with trucks) and Africa → USA/Canada sea lanes; desktop hero copy and globe no longer overlap.

## v1.12.4

- Flag cards for every route country on the home globe (`$routeCountries` in `landing.php`, skipped when the admin already lists the country); new flag symbols KE, TZ, ZA, NG, US, CA.

## v1.12.5

- Home globe: GB (London) and NL (Rotterdam) flag cards. Card declutter in `globe.js`: cards that collide (with each other or with `[data-tg-avoid]` page elements — the hero copy and side rail) move to a free slot; secondary cards with no free slot fade until the globe turns; `data-priority` overrides a card's rank.
- Target markets: a horizontal carousel (`.th-mk.is-carousel`) when more than four markets are listed — arrows, mouse drag, touch swipe and arrow keys; new default market cards DE, RU and IQ with skylines `sky-DE`, `sky-RU`, `sky-IQ`. Sites that already saved home content in the admin keep their own list: tick «نمایش در بخش بازارها» for the markets to add.
