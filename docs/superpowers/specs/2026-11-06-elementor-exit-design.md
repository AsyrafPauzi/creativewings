# Creative Wings — Elementor Exit Design

**Date:** 2026-11-06  
**Status:** Approved (2026-11-06; backup taken before sign-off)  
**Phase 1:** Live on creativewings.asia (child theme `astra-child-creativewings` v0.1.1; Elementor chrome conditions cleared; pages 159/2468/460 off builder mode)  
**Site:** https://creativewings.asia  
**Owners:** Developer (code/templates only; no non-dev Elementor editing requirement)

### Pre-exit backup (taken 2026-11-06)

- **Local:** `docs/superpowers/backups/2026-11-06-elementor-pre-exit/` (zip + extracted JSON + README)
- **Server (private):** `wp-content/cw-private-backups/elementor-pre-exit-20261105-180709.zip` (not web-accessible)
- **Contents:** 41 Elementor-built posts/templates (`_elementor_data` + page settings), `manifest.json` (theme mods, plugins, front page), `menus.json`
- **Also on Brand Story (2119):** prior meta `_elementor_data_backup_20261106` from the logo swap

---

## Goal

Leave Elementor completely while keeping the **same visual design**. Optimize equally for:

- Faster / lighter pages
- Fewer plugin-update breakages
- Lower license cost (Elementor Pro + addons)
- Cleaner long-term developer control

This is a **phased full exit**, not a one-shot rebuild.

---

## Current state (inventory)

| Layer | Today |
|---|---|
| Theme | Astra (no child theme yet) |
| Builders | Elementor + Elementor Pro + Essential Addons + Happy Addons |
| Front page | Homepage `159` (Elementor; mostly shortcodes) |
| Content pages | ~28 Elementor-built pages |
| Theme Builder | Header `#389`, Footer `#163`, product/contest templates, kits |
| App logic | Already in `creativewings-core` (shortcodes, map, shop, entries, etc.) |

**Notable Elementor surfaces**

- Header `#389` — logos, mega-menu / nav, SDG icon, login, campaign/prize strip
- Footer `#163` — logos, links, contact
- Homepage `159` — thin shell + CW shortcodes
- Activities `2468`, Competitions `460` — thin shells + shortcodes/buttons
- Brand Story `2119` — heavy content (~156KB Elementor JSON)
- Organization & Leadership `3308` — heavy content
- Product / contest templates `122`, `1692`, Contest Page `119`, Add to Cart popup `148`

---

## Decisions locked

1. **Approach A:** Astra **child theme** + keep existing `creativewings-core` shortcodes for app UI.
2. **Split ownership:**
   - Child theme → design tokens, header, footer, marketing/page templates
   - Plugin → campaign/map/shop/contestant UI (unchanged ownership)
3. **Editors:** Developer-only. Layout and copy changes happen in code/templates (or simple WP fields later if needed — out of scope for now).
4. **Elementor stays installed** until every public surface is replaced and verified; then deactivate and remove.
5. **Visual rule:** Match live design unless explicitly asked to redesign. Logo on Brand Story already updated to transparent black-wordmark asset (`3147`); preserve that in rebuilds.
6. **Phase 1 scope (first deliverable):** Header + footer + Homepage + Activities + Competitions shells.

---

## Architecture

```
Astra (parent)
└── astra-child-creativewings/     ← NEW
    ├── style.css / assets/css/tokens.css
    ├── header.php / footer.php (or template-parts)
    ├── page-homepage.php
    ├── page-activities.php
    ├── page-competitions.php
    └── …later page templates…

creativewings-core/                ← EXISTING (keep)
└── shortcodes, map, shop, entries, assets
```

**Page pattern (preferred):**

```php
// Theme template
get_header();
// optional static chrome / intro matching Elementor
echo do_shortcode('[existing_cw_shortcode…]');
get_footer();
```

Do **not** reimplement map/shop/entry logic in the theme.

**Migration safety:**

- For each surface: build custom → visual QA → point WP/theme conditions to custom → leave Elementor template/page data inactive as rollback → delete Elementor artifacts only after a soak period.
- Never deactivate Elementor mid-phase while Theme Builder still owns header/footer or product templates.

---

## Phase roadmap

### Phase 1 — Foundation + main browse path

**Deliver**

1. Create Astra child theme with design tokens (colors, type, spacing, logo rules) extracted from live header / Brand Story / chrome.
2. Rebuild header `#389` and footer `#163` as theme templates (menus, logos, SDG mark, login/signup, site campaigns / prize pool strip if present).
3. Switch site chrome to child theme header/footer; disable Elementor Theme Builder header/footer display conditions once match is signed off.
4. Replace Homepage `159`, Activities `2468`, Competitions `460` with PHP templates wrapping the same shortcodes/CTAs.
5. Visual QA desktop + mobile; compare weight / console vs current.

**Exit criteria**

- Main browse path works without Elementor page builder content for those three pages.
- Header/footer match live closely enough to ship.
- Rollback path documented (re-enable Elementor header/footer conditions).

### Phase 2 — Auth + directories + light shells

Login `281`, Registration `265`, Get Started `2980`, Creators Directory `3543`, Organizers Directory `3542`, Sponsors `4066`, Contact `4044`, About `4045`.

Mostly shortcode/HTML shells — low design risk.

### Phase 3 — Heavy content pages

Brand Story `2119`, Organization & Leadership `3308`, SDGs `2117`.

Static PHP/HTML (or theme partials) matching current layout: timeline, mentors, partners, galleries. Preserve current logo treatment on Brand Story.

### Phase 4 — Woo / contest templates

Elementor product/contest templates (`122`, `1692`, `119`) and Add to Cart popup `148` → WooCommerce / plugin templates wrapping existing shortcodes and add-to-cart flow.

### Phase 5 — Cleanup + Elementor removal

**Status:** Shipped 2026-11-06 — see [Phase 5 cleanup spec](./2026-11-06-elementor-exit-phase-5-cleanup.md). Child theme `0.11.0`.

1. Remaining policy/legal pages (Privacy, Terms, Refunds, Service Delivery, PDPA, etc.) → theme legal shells.
2. Search results / leftover Theme Builder items (`408`, kits, unused libraries) → theme `search.php` + conditions `exclude/general`.
3. Deactivated Elementor, Elementor Pro, Essential Addons, Happy Addons, Jet Elements (files kept for rollback).
4. Confirmed no Elementor front assets / no critical fatals on smoke URLs.
5. Child-theme CSS is the chrome + legal/search shell source of styling.

---

## Non-goals (this program)

- Visual redesign / rebrand
- Headless / Next.js rewrite
- Moving campaign business logic out of `creativewings-core`
- Building a non-developer page builder replacement

---

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Header mega-menu / Happy Addons widgets hard to clone | Inspect live DOM + CSS; recreate with WP menus + child-theme CSS; drop addon-only flourishes only if they have no UX value |
| Shortcode markup depends on Elementor parent wrappers | Rebuild minimal wrapper classes in theme CSS so shortcode layout still holds |
| Woo single-product still Elementor while chrome is custom | Keep Elementor active until Phase 4; do not remove plugins early |
| Accidental design drift | Side-by-side screenshots per phase; treat live Elementor page as source of truth |
| Cache (Elementor CSS, CW_Cache, host cache) | Clear Elementor files cache + page cache after each cutover |

---

## Success metrics (per phase)

- Visual parity: key breakpoints (mobile ~375, tablet ~768, desktop ~1280)
- No broken primary CTAs (Join / Login / Activities / Competitions / product join)
- Front-end weight down vs Elementor baseline on migrated pages
- Zero reliance on Elementor widgets for that phase’s surfaces
- Documented rollback under 15 minutes

---

## Next step after spec approval

Write **Phase 1 implementation plan** only:

`docs/superpowers/plans/2026-11-06-elementor-exit-phase-1.md`

Later phases get their own plans after Phase 1 ships.
