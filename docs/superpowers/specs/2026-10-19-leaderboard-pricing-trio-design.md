# Leaderboard pricing trio (week / all-time / month)

**Date:** 2026-10-19  
**Status:** Approved — 2026-10-19  
**Surface:** Contestant dashboard → Leaderboard tab  

---

## 1. Goal

Replace the single balance table with a **pricing-style three-card** leaderboard: **This week** | **All-time** (featured center) | **This month**. Creative, dashboard-native polish (CW tokens) — not a marketing landing page.

---

## 2. Locked decisions

| # | Topic | Decision |
|---|--------|----------|
| 1 | Layout | Approach **A** — three cards always visible |
| 2 | Week / month metric | Points **earned** (`type = earn`, sum `points`) in last **7** / **30** days |
| 3 | All-time metric | Current **balance** (existing `get_leaderboard`) |
| 4 | Rows per card | Top **8** |
| 5 | You | Highlight row if in top 8; always show your rank + score under card title |
| 6 | Mobile | Stack: All-time first, then week, then month |
| 7 | Cache | Transient ~5 minutes per period query |
| 8 | Motion | Light CSS only; honor `prefers-reduced-motion` |

---

## 3. Layout

```
[ This week ]   [ All-time ★ ]   [ This month ]
     list            list              list
   Your #…         Your #…          Your #…
```

- Center card: featured (slight scale / primary rim / stronger header).
- Rank medals for #1–#3; compact name + pts rows (no table chrome).
- Header copy: short subtitle explaining week/month = earned, all-time = balance.

---

## 4. Data API (`CW_Points`)

- `get_leaderboard( $limit )` — unchanged (balance).
- `get_leaderboard_earned( $days, $limit )` — aggregate ledger earns since `now - $days`.
- `get_user_earned( $user_id, $days )` / `get_user_earned_rank( $user_id, $days )` — for “You” strip.
- Transient keys e.g. `cw_lb_earn_{days}_{limit}` TTL 300s.

---

## 5. Out of scope

- Public/shortcode leaderboard
- Changing badge Top Contributor logic
- Net (earn − spend) ranking
- Infinite scroll / pagination inside cards

---

## 6. Acceptance

- Three cards render on Leaderboard tab; center is visually featured.
- Week/month ranks match ledger earn sums for the windows.
- All-time matches current balance ranking.
- Current user strip correct even when outside top 8.
- Mobile order: all-time → week → month.
