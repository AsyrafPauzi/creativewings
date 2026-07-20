# Post-Event Platform Update — Implementation Plan (Phased)

> **For agentic workers:** Implement **one phase at a time**. After each phase, review and QA before starting the next. Use subagent-driven-development or executing-plans per phase.

**Goal:** Deliver the approved post-event improvements across auth, guest checkout, gamification, and map gallery.

**Spec:** `docs/superpowers/specs/2026-07-20-post-event-platform-update-design.md`

**Architecture:** Extend existing `CW_Auth` / Nextend hooks, guest join checkout, badges engine, and campaign shortcodes. Add a points ledger + cron; replace gallery UI with a map visualization component.

**Tech stack:** WordPress, WooCommerce, Nextend Social Login, existing CW plugin modules.

---

## Phase 1 — Auth + Get Started removal + Success redirect

### Task 1.1 — Diagnose & fix Google Social Login

**Files (expected):**
- `includes/class-cw-auth.php` (Nextend shortcodes, redirects, `social_register_metadata`)
- Login/registration templates / shortcode output
- Possibly Nextend settings (document required admin config)

- [ ] Reproduce “button does nothing” on staging (console errors, redirect URL, `redirect="/"`)
- [ ] Fix shortcode redirect to contestant dashboard / my-account
- [ ] On new Google user: role `contestant`, skip Get Started, land on dashboard
- [ ] On existing email: block auto-link; message to log in; do not change role
- [ ] Manual QA: new Google signup, existing email Google, email/password login still works
- [ ] Commit: `Fix Google social login and contestant redirect for new users.`

### Task 1.2 — Remove Get Started for contestants

**Files:**
- `includes/class-cw-auth.php` (`login_redirect`, `enforce_onboarding_access`, registration redirects)
- `includes/class-cw-onboarding.php` (only if needed)
- Contestant dashboard: upgrade links to Creator + Business

- [ ] New email/Google contestants: set onboarding complete (or bypass Get Started checks)
- [ ] Keep Get Started for creator/business
- [ ] Add dashboard CTAs: upgrade to Creator / Business → respective onboarding
- [ ] Manual QA matrix: new contestant, existing contestant, creator, business
- [ ] Commit: `Skip Get Started for new contestants; keep creator and business onboarding.`

### Task 1.3 — Success popup + 5s redirect to campaign

**Files:**
- Thank-you / order received hooks (e.g. `CW_Checkout`, `CW_Shop`, shortcodes, or dedicated JS on `is_order_received_page`)
- CSS for modal

- [ ] Detect CW campaign product ID from order
- [ ] Congrats modal + countdown 5s + link to product permalink
- [ ] Works for guest and logged-in
- [ ] Commit: `Add post-checkout congratulations modal and redirect to campaign page.`

**Phase 1 exit criteria:** Google signup works; contestants never see Get Started; thank-you redirects to campaign.

---

## Phase 2 — Simplified guest checkout fields

### Task 2.1 — Minimal guest billing fields

**Files:**
- `includes/class-cw-guest-join.php`
- `includes/class-cw-checkout.php`
- Campaign settings UI (`class-cw-business-form.php` / persistence)

- [ ] Guest CW cart: require Full Name (single), Email, DOB text only (remove datepicker enqueue/JS for guest DOB)
- [ ] Hide other billing/shipping fields by default
- [ ] Map Full Name → WC billing first/last (e.g. first=full, last=`.` or split policy documented)
- [ ] Commit: `Simplify guest checkout to name, email, and text DOB.`

### Task 2.2 — Per-campaign field modes

- [ ] Meta schema: field_key → `hidden|optional|required`
- [ ] Wizard UI toggles for phone, IC, address, gender, school, parent, emergency contact
- [ ] Render + validate on guest checkout from campaign config
- [ ] Commit: `Add per-campaign hidden/optional/required checkout profile fields.`

**Phase 2 exit criteria:** Guest can pay with 3 defaults; organiser can require extras.

---

## Phase 3 — Points, badges, leaderboard

### Task 3.1 — Points ledger + earn + expiry cron

**Files (new/extend):**
- New e.g. `includes/class-cw-points.php` (+ activator table or user meta ledger)
- Hooks on paid order / guest account attach
- Daily cron wipe

- [ ] Schema: balance, last_qualifying_join_at, transactions
- [ ] Earn = original RM price; guest earn on complete registration only
- [ ] Join resets expiry +12 months; daily cron wipes if stale
- [ ] Dashboard balance + expiry copy
- [ ] Coming Soon redemption block
- [ ] Commit: `Add points ledger with join-based 12-month expiry.`

### Task 3.2 — Badges expansion

**Files:** badges CPT/engine

- [ ] Implement rules from design spec (defer Referral Master)
- [ ] Champion: organiser mark winner UI
- [ ] Top Contributor: recompute Top 10 by points
- [ ] Commit: `Expand badges for joins, early bird, champion, and legend tiers.`

### Task 3.3 — Site-wide leaderboard

- [ ] Contestant dashboard (and/or page) ranked by balance
- [ ] Commit: `Add site-wide contestant points leaderboard.`

**Phase 3 exit criteria:** Points earn/reset/wipe work; badges award; leaderboard visible.

---

## Phase 4 — World-map gallery

### Task 4.1 — Map UI on campaign page

**Files:**
- `includes/class-cw-shortcodes.php` (replace/augment public gallery)
- New JS/CSS assets for map + pins + lightbox
- KPI meta for fill ratio

- [ ] World map asset; pins seeded by entry ID; click → lightbox
- [ ] Fill = min(1, count/kpi); never >100%
- [ ] Refresh on load only
- [ ] Performance cap for large pin counts
- [ ] Commit: `Replace submission gallery with KPI-capped world map visualization.`

**Phase 4 exit criteria:** Map shows progress and anonymous lightbox on campaign page.

---

## Suggested QA checklist (all phases)

- [ ] Google new user → contestant dashboard
- [ ] Google existing email → login prompt, role unchanged
- [ ] Email register → no Get Started
- [ ] Creator/business still onboard
- [ ] Guest checkout minimal fields; organiser-required extras
- [ ] DOB text validation; age brackets still block when configured
- [ ] Thank-you → popup → campaign page
- [ ] Points from original price; guest after complete-reg
- [ ] No join 12 months → wipe; join resets clock
- [ ] Badges: First / 5 / 10 / Early Bird / Explorer / Legend / Champion / Top 10
- [ ] Map fill caps at KPI; random stable pins; lightbox

## Spec coverage

All approved Q&A decisions from 2026-07-20 discussion are mapped to a phase/task above.
