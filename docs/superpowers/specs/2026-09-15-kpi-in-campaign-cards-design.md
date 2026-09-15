# KPI progress inside Open Campaign cards

**Date:** 2026-09-15  
**Status:** Draft for review  
**Goal:** Show simple campaign KPI progress on each Open Campaigns card, and remove the separate homepage “Campaign Progress” carousel so progress is not duplicated.

## Problem

Homepage currently has two surfaces for the same data:

1. `[cw_kpi_carousel]` — ring charts under “Campaign Progress”
2. Open Campaigns cards (`[cw_events …]` / `cwg-card`) — no progress

Users want progress visible on the campaign card itself, without a second section.

## Decision

- **In-card UI:** thin progress bar + `current / target label` (e.g. `79 / 500 submissions`)
- **Placement:** below card meta (dates / fee), above SDGs
- **Visibility:** only when `cw_kpi_show_progress === yes` and `cw_kpi_target > 0`
- **Homepage:** remove `[cw_kpi_carousel]` from Homepage (and Brand Story if present)
- **Shortcode:** keep `[cw_kpi_carousel]` registered for optional reuse elsewhere; do not delete the renderer

## Data

Reuse existing campaign KPI meta and count helper:

| Field | Source |
|--------|--------|
| Enabled | `cw_kpi_show_progress` = `yes` |
| Target | `cw_kpi_target` (int > 0) |
| Label | `cw_kpi_label` (fallback: “Participated” / existing carousel fallback) |
| Current | `CW_Campaign_Admin::get_public_participant_count( $pid )` (includes display boost) |

Percent = `round(current / target * 100, 1)`, bar fill clamped to `0–100`. Over-target still shows full bar and true fraction text.

## UI (card)

```
79 / 500 submissions
[████░░░░░░░░░░░░] 16%
```

- Row 1: pink current count + muted `/ target label`
- Row 2: 6–8px track, brand pink fill, optional small percent on the right
- Closed / joined cards: still show KPI if enabled (status ribbons unchanged)
- No SVG ring on the card

## Scope

**In**

- Markup + CSS on `cwg-card` in the events/gallery shortcode renderer
- Remove homepage Elementor/post shortcode for `[cw_kpi_carousel]`
- Rebuild / deploy general CSS as needed

**Out**

- Changing KPI admin settings or boost logic
- Redesigning the standalone KPI carousel component
- New REST endpoints

## Acceptance

1. Open campaign with KPI shows bar + fraction on its card.
2. Campaign without KPI shows no progress block.
3. Homepage no longer shows a separate “Campaign Progress” carousel.
4. Counts match what the old KPI carousel showed for the same campaign.
5. Mobile: bar remains readable; card layout does not break.

## Risks

- Homepage Elementor cache may need purge after shortcode removal.
- Dist CSS (`cw-core.*.css`) must be rebuilt if Vite pipeline is active on deploy.
