# Badges tabs + circle art — Implementation Plan

**Spec:** `docs/superpowers/specs/2026-10-19-badges-tabs-circle-art-design.md`

**Files:**
- `includes/badges/class-cw-badges-display.php` — tabs markup + `data-complete`
- `assets/css/cw-style-badges.css` — tabs + circle image medal
- `assets/js/cw-script.js` (or small inline) — tab filter
- Generate PNGs → WP media → `cw_badge_icon` for creator/`any` badges
- Version bump + deploy

## Tasks
1. Progress grid: tab bar + card `data-cw-badge-state=complete|incomplete`
2. CSS/JS filter; medal uses full-bleed circular img when present
3. Generate + attach AI circle art; ship
