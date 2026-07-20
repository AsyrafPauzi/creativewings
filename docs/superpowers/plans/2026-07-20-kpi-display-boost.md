# KPI Display Boost Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add campaign setting for fake starting submission count; public KPI shows real + boost; map pins stay real-only.

**Architecture:** Store `cw_kpi_display_boost` on campaign product meta. Public page uses `get_public_participant_count()` = real + boost. Admin/internal stats keep `get_participant_count()`.

**Tech Stack:** WordPress post meta, existing campaign wizard + metabox, shortcode renderer.

---

### Task 1: Add public count helpers

**Files:**
- Modify: `includes/class-cw-campaign-admin.php`

- [x] Add `get_display_boost()`
- [x] Add `get_public_participant_count()` = real + boost
- [ ] Keep `get_participant_count()` real-only for admin/reports

### Task 2: Wire public campaign page

**Files:**
- Modify: `includes/class-cw-shortcodes.php`

- [ ] Hero KPI uses `get_public_participant_count()`
- [ ] Map gallery count label uses same boosted public count
- [ ] Map canvas/pins remain real IDs only

### Task 3: Organizer UI + save

**Files:**
- Modify: `includes/business/class-cw-business-form.php`
- Modify: `includes/business/class-cw-campaign-persistence.php`
- Modify: `includes/class-cw-campaign-admin.php` (metabox)
- Modify: `creativewings-core.php` (version bump)

- [ ] Add boost number field in wizard KPI section
- [ ] Persist meta on save
- [ ] Add same field in WP admin KPI metabox
- [ ] Bump plugin to 11.0.88
