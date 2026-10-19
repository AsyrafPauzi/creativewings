# Leaderboard pricing trio — Implementation Plan

> **For agentic workers:** Implement task-by-task. Spec: `docs/superpowers/specs/2026-10-19-leaderboard-pricing-trio-design.md`.

**Goal:** Contestant Leaderboard tab = three pricing-style cards (week / all-time / month).

**Files:**
- `includes/class-cw-points.php` — earned leaderboard + rank helpers + transient cache
- `includes/dashboard/class-cw-dashboard-contestant.php` — `render_leaderboard()` markup
- `assets/css/cw-style-contestant.css` — pricing trio styles
- `creativewings-core.php` — version bump + Vite rebuild dist

---

## Task 1: Points APIs

Add to `CW_Points`:
- `PANEL_SIZE = 8`
- `get_leaderboard_earned( $days, $limit )`
- `get_user_earned( $user_id, $days )`
- `get_user_earned_rank( $user_id, $days )`
- Transient cache 300s for list queries

SQL: `SUM(points) WHERE type='earn' AND points>0 AND created_at >= %s GROUP BY user_id ORDER BY sum DESC`.

## Task 2: Render trio

Rewrite `render_leaderboard()` with `.cw-lb-trio` grid, three `.cw-lb-card` panels (week / alltime featured / month), shared private renderer for list + you strip.

## Task 3: CSS + ship

Style pricing cards (featured center, medals, rows). Build Vite, bump `CW_VERSION`, deploy when asked / after local verify.
