# Contestant Portal — Shell + Overview Revamp (Approach A)

**Date:** 2026-10-19  
**Status:** Approved — 2026-10-19  
**Scope:** Contestant role only — shared shell + Overview tab  
**Skills:** gpt-taste (creative structure / hierarchy) + unslop-ui (anti-slop restraint)  
**Brand:** Creative Wings tokens — `--cw-primary` `#125B9A`, accent coral, Montserrat (`cw-style-general.css`)

---

## 1. Goal

Make the Contestant Portal feel less like a rigid admin panel and more inviting for students — without turning it into decorative AI-slop (glass, icon tiles, glow, gradient soup).

**Success:** Overview reads as a clear home (welcome → status → recent → next action). Shell feels warmer and clearer on every Contestant tab. No data or tab IA changes.

---

## 2. Locked decisions

| # | Topic | Decision |
|---|--------|----------|
| 1 | Scope | **Shell + Overview** (Approach A). Other tab bodies later. |
| 2 | Roles | **Contestant only.** Creator / business shells untouched this pass. |
| 3 | Brand | Keep CW tokens + Montserrat. Do **not** swap to Inter / trendy serif. |
| 4 | Direction | Atmosphere upgrade via layout, type, spacing, hierarchy — not chrome effects. |
| 5 | Data | Same metrics: submissions count, pending review, points (+ rank/expiry), recent 5, upgrade CTA. |
| 6 | Motion | CSS transitions on interactive elements only (150–300ms). No entrance GSAP / scroll theater on dashboard. |
| 7 | Skills merge | gpt-taste for composition; **unslop-ui wins on banned patterns** (see §4). |

---

## 3. Design (reconciled)

### 3.1 gpt-taste structure (kept)

| AIDA slice | Overview mapping |
|------------|------------------|
| Attention | Wide welcome band: name + one support line + two solid CTAs (Explore · View activities) |
| Interest | Dense 2×2 metrics grid (submissions / pending / points / upgrade) — interlocking, no dead cells |
| Desire | Recent as **list cards** (name, type, date, status) — not a spreadsheet table |
| Action | Empty state → Explore; upgrade cell → Upgrade Account |

**Hero math:** Welcome headline uses full content width (`max-w` of `.cw-content-max`); 1–2 lines max. No stamp badges, no pill-tags under the title, no stats inside the welcome band.

### 3.2 Shell (all Contestant tabs)

- Sidebar: solid `--cw-sidebar-bg` (or one step warmer neutral). Optional **single** soft radial wash behind the main content only — not on every card.
- Nav: more vertical rhythm; active state = solid soft fill (`--cw-primary-light`) + primary text/icon. **No** frosted glass nav. Icons stay inline for nav (action-driven) — FA already in use.
- Logo block: simplify — less boxy nested chrome; keep CW mark + “Contestant Portal”.
- User footer: flatter; logout remains clear text+icon link.
- Mobile header: match shell neutrals; same brand mark.

### 3.3 Overview content

- **Welcome:** `h1`/`h2` hierarchy via size/weight only; support copy muted. Two CTAs: primary Explore, secondary text/outline View activities.
- **Metrics grid:** 4 cells. Values are large numbers + label text. **No** colored rounded-square icon wrappers. Upgrade cell is typography + solid button (accent or primary per brand), not a glowing card.
- **Recent:** card rows or compact list with whitespace separation; status as text/badge using existing CW status colors. Link row hover: subtle background only (no scale+translate combo).
- **Empty:** one icon optional **inline** (no colored box), short copy, one solid Explore button.

---

## 4. unslop-ui hard bans (this pass)

- No icon-in-rounded-square / circle containers on metric cards  
- No emojis as UI decoration  
- No glassmorphism (`backdrop-filter`, frosted cards, blur+border stacks)  
- No gradient text; no gradient buttons; at most **one** subtle page-level background wash  
- No colored/glowing button shadows  
- No card-in-card nesting without a real grouping need  
- No entrance fade/slide stacks; no multi-transform hover theater  
- No meta-labels (“SECTION 01”, “OVERVIEW”)  
- No `border-left` + `border-radius` accent-bar cards  
- Colors ~70% neutral / 20% primary soft fills / 10% accent CTAs  

**Checklist before ship:** run §9 of unslop-ui against Overview + Contestant shell.

---

## 5. Files to touch

| File | Change |
|------|--------|
| `includes/dashboard/class-cw-dashboard-manager.php` | Contestant-only class hook on layout (e.g. `cw-layout--contestant`); minor shell markup cleanup if needed |
| `includes/dashboard/class-cw-dashboard-contestant.php` | Rewrite `render_overview()` markup to welcome / bento / recent cards |
| `assets/css/cw-style-general.css` | Contestant shell refinements (scoped so creator/business unchanged) |
| `assets/css/cw-style-contestant.css` | Overview layout + recent cards; remove reliance on rigid table for home |
| JS | Prefer none. If needed: tiny CSS-class helpers only — **no** GSAP on this pass |

Bump `CW_VERSION` when deploying for cache bust.

---

## 6. Out of scope

- Explore, activities, points, leaderboard, badges, upgrade, settings **tab bodies**  
- Creator / business portals  
- Auth / complete-profile pages  
- Tab IA merges or new endpoints  
- Full gpt-taste GSAP scroll pinning / marquees (conflicts with unslop + dashboard UX)

---

## 7. Acceptance

1. Logged-in contestant on `/my-account/?tab=overview` sees new welcome + metrics + recent/empty — same numbers as before.  
2. Sidebar/nav look updated on **every** Contestant tab; Overview-only content change.  
3. Creator and business portals visually unchanged.  
4. Mobile: sidebar toggle still works; CTAs readable; no horizontal overflow.  
5. unslop checklist passes; contrast on primary/secondary buttons remains WCAG-friendly on CW blues.

---

## 8. Spec self-review

- [x] No placeholders / TBD in locked decisions  
- [x] gpt-taste vs unslop conflict resolved (structure vs banned chrome)  
- [x] Scope = shell + Overview only  
- [x] Brand tokens preserved  
- [x] Motion scoped down from earlier draft (no GSAP this pass)
