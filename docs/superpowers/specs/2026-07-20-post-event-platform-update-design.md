# Creative Wings — Post-Event Platform Update Design Spec

**Date:** 2026-07-20  
**Status:** Approved for phased implementation  
**Source:** Post-event feedback discussion draft + clarifying Q&A  

## Goals

Reduce signup friction, simplify guest checkout, improve post-purchase success UX, replace the basic submission gallery with a KPI-capped world-map visualization, and deepen engagement via points, badges, and a site-wide leaderboard.

## Non-goals (this release)

- Points **redemption** (UI: Coming Soon only)
- Referral system / Referral Master badge (deferred)
- Real geographic pin placement (pins are random on a world map)
- Changing creator/business onboarding (they still use Get Started)

## Phased delivery

| Phase | Scope | Outcome |
|-------|--------|---------|
| **1** | Google auth fix + remove Get Started for contestants + success popup/redirect | Faster signup & clearer post-checkout path |
| **2** | Simplified guest checkout + per-campaign field modes | Minimal required fields; organiser flexibility |
| **3** | Points ledger, expiry, badges expansion, site-wide leaderboard | Engagement loop |
| **4** | World-map submission gallery (KPI fill + random pins + lightbox) | Visual progress toward campaign KPI |

---

## Phase 1 — Auth & success UX

### 1.1 Google Sign-Up / Login

**Problem:** Google button does nothing / errors (Nextend Social Login + redirect wiring).

**Behaviour**

1. Fix Nextend Google shortcode/redirect so signup and login complete successfully.
2. **New Google user**
   - Create WP user if missing
   - Role: `contestant`
   - Set `account_type` = `contestant`
   - Mark onboarding complete for contestant path (skip Get Started)
   - Redirect to **contestant dashboard** (my-account / contestant portal)
3. **Existing email (already registered by email/password or other)**
   - Do **not** create a second account
   - Do **not** change existing role
   - Show clear message: please log in with the existing account
4. Facebook (if still shown) follows the same role/redirect rules once Google is stable; Google is the priority fix.

**Edge cases**

- Soft-deleted / banned users: keep existing site policy.
- Incomplete profile (PDPA/DOB) for social users: retain `complete-profile` gate only if still required by policy; contestant Get Started plan picker is removed.

### 1.2 Remove Get Started (contestants)

**New journey**

1. Register (email or Google) → account created as contestant  
2. Redirect to contestant dashboard  

**Preserved**

- Creator and business still use onboarding / Get Started.
- Existing users keep their roles.

**Upgrade from contestant dashboard**

- Links to upgrade to **Creator** and **Business**.
- Choosing either starts that role’s onboarding (not skipped).

### 1.3 Success page enhancement

After successful paid registration (guest or logged-in):

1. Order-received / thank-you page shows a **congratulatory popup**
2. Short success message
3. Auto-redirect after **5 seconds** to the **campaign product detail page** they joined
4. Manual “Go to campaign” link available immediately

**Edge cases**

- Multi-campaign cart (if ever allowed): redirect to the first CW campaign line’s product page.
- Popup must not block accessibility (Escape / click close still works; timer continues unless cancelled).

---

## Phase 2 — Simplified guest checkout

### Required by default (guest CW registration cart)

| Field | Notes |
|--------|--------|
| Full Name | **Single** field (not first/last) |
| Email | Billing email |
| Date of birth | Plain text `dd/mm/yyyy`, **no date picker** |

All other WooCommerce billing/shipping fields are **hidden by default** for guest CW registration checkout.

### Organiser-controlled extra fields

In Campaign Settings, each optional profile/checkout field can be:

- **Hidden**
- **Optional**
- **Required**

Example catalogue (extensible):

- Phone Number  
- IC / Passport  
- Address (line / city / postcode / country as needed)  
- Gender  
- School  
- Parent Information  
- Emergency Contact  

**Validation:** server-side required checks for organiser-required fields; age eligibility for DOB remains when age brackets are enabled (text parse `dd/mm/yyyy`).

**Guest complete-registration** (existing token email flow) unchanged in spirit: account creation after pay; points are **not** awarded until account completion (Phase 3).

---

## Phase 3 — Points, badges, leaderboard

### 3.1 Points

**Earn**

- On paid campaign join for logged-in contestants: when entry/order is confirmed paid.
- For guests: **only after** they complete registration via the email link and the order/entry is attached to their user.
- Points = **floor(original campaign line price in RM)** (pre-coupon / pre-discount). Example: price RM100, pay RM80 → **100 points**.

**Expiry / wipe**

- Track `last_qualifying_join_at` per user (timestamp of last paid campaign join that awards or refreshes points).
- Each qualifying join **resets** expiry to **now + 12 months**.
- **Daily cron:** if `last_qualifying_join_at` is older than 12 months → **wipe all points** for that user (balance → 0) and clear related open point rows as needed.
- Purpose: encourage continued joining of competitions/activities.

**Ledger**

- Append-only (or auditable) point transactions: earn, wipe, (future redeem).
- Dashboard shows current balance + expiry date (“Points expire on {date} unless you join again”).

**Redemption**

- UI section: **Coming Soon** (merchandise, coupons, etc.). No spend logic in this release.

### 3.2 Leaderboard

- Site-wide contestants ranked by **current point balance**.
- Visible on contestant dashboard (and optionally a public page later).
- Top list size for display: configurable; default top 20–50 for UI (badge Top Contributor uses Top 10 — see badges).

### 3.3 Badges

Expand existing badges engine with these definitions:

| Badge | Rule |
|--------|------|
| First Competition | First paid join of **any** campaign (competition or activity) |
| 5 Competitions Joined | **5** paid **competition** joins |
| 10 Competitions Joined | **10** paid **competition** joins |
| Early Bird | Join within **7 days** of campaign submission start (default; per-campaign override later) |
| Top Contributor | User is in **Top 10** by current points (re-evaluated on cron / after point changes) |
| Champion | Organiser marks entry/user as winner on a campaign |
| Event Explorer | Has at least one paid **competition** and one paid **activity** join |
| Creative Legend | **50** paid campaign joins **OR** **10,000** lifetime points earned (either) |
| Referral Master | **Deferred** — not in this release |

Awards are automatic except Champion (organiser action) and Top Contributor (periodic recompute).

---

## Phase 4 — World-map submission gallery

### Replace

Basic post-join submission gallery on campaign page with a **world-map style** visualization.

### Behaviour

- Background: world map graphic.
- Each public submission = a **pin** placed at a **random** map coordinate (stable per entry ID so reload doesn’t reshuffle wildly — seed RNG from entry ID).
- **Fill / progress:** `min(1, submission_count / kpi_target)` drives a visual fill (overlay opacity, progress ring, or shaded regions). Never display above 100% even if submissions exceed KPI.
- KPI source: existing campaign KPI target (`cw_kpi_target` or equivalent).
- **Update:** refresh on page load (not live websocket).
- **Click pin:** lightbox with anonymous submission details (image + optional checkout message), consistent with current public gallery privacy rules.

### Edge cases

- KPI missing or 0: show map + pins without fill, or treat as uncapped count label only (prefer: require KPI for fill UI; show pins anyway).
- No submissions: empty map + CTA to join.
- Very large N: cluster or sample pins for performance (e.g. show up to N pins + “+more”).

---

## Data / security notes

- Guest checkout field config stored as campaign post meta (structured array of field_key → mode).
- Points wipe and Top Contributor recompute via WP-Cron (daily).
- Champion mark: capability check (campaign organiser / business owner).
- Do not expose PII in map lightbox beyond existing anonymous gallery rules.

## Success metrics (suggested)

- Higher Google signup completion rate
- Lower guest checkout abandonment
- More repeat joins within 12 months (points reset behaviour)
- Higher dashboard return rate (badges / leaderboard)

## Open items for UI (not blocking design)

- Exact world-map asset and fill animation style
- Leaderboard page vs dashboard widget only
- Copy for congratulations popup

---

## Spec self-review

- No placeholder TBD for behaviour decisions from Q&A.
- Referral explicitly deferred.
- Phases match agreed ship order (22B).
- Points wipe uses daily cron (23A).
- Guest DOB text-only; map random pins; Top 10 contributor; Creative Legend 50 or 10k.
