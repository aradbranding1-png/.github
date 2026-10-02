# aradbranding.app — Home page redesign (v1.10.1)

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
`dist/aradbranding-1.10.1-home.zip`. It contains only the files below plus `VERSION`, `CHANGELOG.md`
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
