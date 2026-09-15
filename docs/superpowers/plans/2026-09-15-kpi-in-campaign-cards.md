# KPI in Campaign Cards Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show a simple KPI progress bar on each Open Campaigns card and remove the homepage Campaign Progress carousel.

**Architecture:** Read existing KPI meta + `CW_Campaign_Admin::get_public_participant_count()` inside the events grid card renderers; inject a compact progress block into `cwg-card-body`. Keep `[cw_kpi_carousel]` registered but remove it from Homepage Elementor data.

**Tech Stack:** WordPress shortcodes (PHP), `cw-style-general.css` + Vite dist `cw-core`, Novamira for live Elementor edit/deploy.

**Spec:** `docs/superpowers/specs/2026-09-15-kpi-in-campaign-cards-design.md`

## Global Constraints

- Only show when `cw_kpi_show_progress === yes` and `cw_kpi_target > 0`
- Use public participant count (includes display boost)
- Thin bar UI — no SVG ring on cards
- Do not delete `render_kpi_carousel`; only remove homepage usage
- Bump plugin version and rebuild Vite CSS for deploy

---

### Task 1: In-card KPI markup (both grid renderers)

**Files:**
- Modify: `includes/class-cw-shortcodes.php` (`render_events_grid` ~3374, `render_event_grid` ~3731)
- Modify: `assets/css/cw-style-general.css`

**Interfaces:**
- Consumes: `get_post_meta( $pid, 'cw_kpi_*' )`, `CW_Campaign_Admin::get_public_participant_count( $pid )`
- Produces: `.cwg-kpi` block with fraction + bar + percent

- [ ] **Step 1:** After `.cwg-card-meta`, before SDGs, if KPI enabled compute count/target/percent/label
- [ ] **Step 2:** Output markup:

```html
<div class="cwg-kpi" aria-label="…">
  <div class="cwg-kpi__row">
    <span class="cwg-kpi__count">79</span>
    <span class="cwg-kpi__rest"> / 500 submissions</span>
  </div>
  <div class="cwg-kpi__bar" role="progressbar" aria-valuenow="16" aria-valuemin="0" aria-valuemax="100">
    <span class="cwg-kpi__fill" style="width:16%"></span>
  </div>
  <span class="cwg-kpi__pct">16%</span>
</div>
```

- [ ] **Step 3:** Apply same block in both card renderers
- [ ] **Step 4:** Add CSS for `.cwg-kpi*` (pink count, 6–8px track, coral fill, compact layout)
- [ ] **Step 5:** `npm run build`, bump to 11.1.12

### Task 2: Remove homepage KPI carousel

**Files:** Live Elementor on pages `159` (+ revisions 4019, 4037–4040 if still referenced)

- [ ] **Step 1:** Remove Elementor shortcode widgets containing `[cw_kpi_carousel …]` from Homepage `159`
- [ ] **Step 2:** Clean matching revision/draft Elementor data if they still surface on front
- [ ] **Step 3:** Purge caches; verify homepage HTML has no `cw-kpi-carousel` and cards have `cwg-kpi` where KPI is on

### Task 3: Deploy + verify

- [ ] **Step 1:** Deploy PHP + CSS + manifest via Novamira zip
- [ ] **Step 2:** Confirm Refresh a Better Journey / One Smile cards show bar + matching counts
- [ ] **Step 3:** Commit remaining local changes (partners marquee + this feature) if user already asked, or leave commit for user request
