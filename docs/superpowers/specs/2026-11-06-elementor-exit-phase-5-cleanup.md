# Phase 5 — Cleanup + Elementor Removal

**Date:** 2026-11-06  
**Status:** Shipped (2026-11-06) — child theme 0.11.0; Elementor + Pro + Essential/Happy Addons + Jet Elements deactivated (data retained for rollback)  
**Site:** https://creativewings.asia  
**Parent program:** [Elementor Exit Design](./2026-11-06-elementor-exit-design.md)

---

## Goal

Finish remaining Elementor-owned public surfaces, then deactivate Elementor stack so the Astra child theme owns chrome and page shells.

---

## Shipped

1. **Legal / policy pages** (theme shells + existing `post_content`):
   - Privacy `3`, Refunds `25`, Service Delivery `983`, Terms `984`, PDPA `3395`
2. **Search** — theme `search.php`; Theme Builder Search `408` conditions → `exclude/general`
3. **Directory hub `3540`** — 301 → `/directory/creators-directory/`
4. **Theme Builder leftovers** — any remaining `include/*` conditions forced to `exclude/general`
5. **Plugin deactivation** (files kept installed):
   - `elementor`, `elementor-pro`
   - `essential-addons-for-elementor-lite`, `essential-addons-elementor`
   - `happy-elementor-addons`, `happy-elementor-addons-pro`
   - `jet-elements` (Elementor-dependent)
6. **Kept active:** `jet-engine` (CCT/meta; no published page shortcode dependency found)

---

## Rollback

1. Reactivate Elementor plugins in WP admin (or via `activate_plugin`).
2. Restore Theme Builder conditions from `_cw_elementor_page_backup_p5` where present.
3. Re-set `_elementor_edit_mode=builder` on policy pages only if needed.

---

## Non-goals

- Deleting Elementor plugin files / library posts
- Removing JetEngine
- Redesigning legal copy
