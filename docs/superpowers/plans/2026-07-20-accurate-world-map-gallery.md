# Accurate World Map Gallery — Implementation Plan

> **For agentic workers:** Spec: `docs/superpowers/specs/2026-07-20-accurate-world-map-gallery-design.md`

**Goal:** Accurate country-border world map with one canvas point per real submission (safe up to 5,000–10,000) and 150 interactive lightbox pins.

**Architecture:** Local Natural Earth SVG + `CW_Map_Coordinates` country centroids; compact `points` array drawn on canvas; recent submissions remain HTML pins.

**Tech Stack:** WordPress, SVG, Canvas 2D, existing `CW_Cache` / shortcode gallery.

## Global Constraints

- No Google Maps / remote geo APIs
- No fake pins for KPI fill
- Max 150 interactive DOM pins
- Max 10,000 canvas points
- Basic gallery mode unchanged

---

### Task 1: Assets + coordinate helper

**Files:**
- `assets/img/world-map.svg` — accurate equirectangular map
- `includes/class-cw-map-coordinates.php` — country centroids + jitter
- `includes/class-cw-loader.php` — require helper

- [x] Accurate SVG with country borders
- [x] Stable `point_for_entry()` / `points_for_entries()`
- [x] `MAX_POINTS = 10000`

### Task 2: Shortcode rendering

**Files:**
- `includes/class-cw-shortcodes.php`

- [x] Query up to 10k entry IDs for canvas (cached)
- [x] Query latest 150 for interactive lightbox pins
- [x] Pass `cwdMapGallery.points` + KPI payload
- [x] Explain dots vs highlighted pins

### Task 3: Canvas JS + CSS

**Files:**
- `assets/js/cw-map-gallery.js`
- `assets/css/cw-style-map-gallery.css`

- [x] DPR-aware canvas draw of all points
- [x] Resize redraw; reduced-motion pin enter
- [x] Canvas layer styles

### Task 4: Verification

- [x] Node helper test (`tests/test-cw-map-gallery.mjs`)
- [ ] Manual: campaign with World map layout shows accurate map + country pins
- [ ] Manual: KPI 5000 with N submissions shows N dots and fill = min(100, N/5000*100)
