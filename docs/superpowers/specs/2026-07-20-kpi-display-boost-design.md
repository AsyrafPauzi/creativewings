# Public KPI Display Boost — Design

**Date:** 2026-07-20  
**Status:** Implemented in 11.0.88

## Goal

Organizers can set a fake starting count so the public campaign page shows
`real successful joins + boost` (e.g. boost 100 + 45 real = **145**).

## Behaviour

- New meta: `cw_kpi_display_boost` (integer ≥ 0, default 0)
- Public hero KPI and map toolbar count/percent use boosted value
- Map pins/dots remain real submissions only
- Organizer dashboards, reports, points, badges use real count only

## UI

Campaign wizard KPI section + WP admin product KPI metabox: number field
“Display boost (fake starting count)”.
