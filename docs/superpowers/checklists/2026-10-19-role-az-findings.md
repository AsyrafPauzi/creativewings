# Role A–Z Audit Findings — 19 Oct 2026

**Method:** Novamira `execute-php` + browser (participant / creator / business) on https://creativewings.asia/  
**Excluded:** Administrator  
**Design lens:** creative-web-design + unslop-ui + design-taste (surgical only; no redesign)

**Audit users created (throwaway):**
- Participant / Creator / Business IDs 276–278 — **deleted** after deploy verification

Temporary magic-login sandbox plugin was **removed** after the run. Conflicting duplicate sandbox PHP copies were renamed to `.auditbak` so they stop crashing the site (see Ops).

---

## Deploy status (19 Oct 2026 evening)

| Item | Status |
|------|--------|
| **P0 B1** | **FIXED / LIVE** — `CW_Coupon_Auth_Popup` loaded; checkout has `cwCouponAuth` + JS; AJAX nopriv hooks + `woocommerce_coupon_is_valid` reject registered; `CW_VERSION` **11.1.42** |
| **P1 DOB** | **FIXED / LIVE** — complete-profile `#birthdate` no longer `readonly` |
| Deploy zip | Uploaded, extracted into `wp-content/plugins/creativewings-yibon/`, then deleted |

---

## Summary

| Severity | Count | Notes |
|----------|-------|--------|
| **P0** | 0 (was 1) | B1 deployed — resolved |
| **P1** | 0 open (sandbox mitigated earlier) | DOB readonly fixed |
| **P2** | 3 | Content emoji in campaign WYSIWYG; GDPR banner friction; empty-state polish |
| **Pass** | Most | All role dashboard tabs render; campaign detail OK; ownership OK |

---

## Functional results

### Participant (contestant)

| ID | Result | Evidence |
|----|--------|----------|
| P01–P02 | PASS* | Magic login → Contestant Portal (*new users hit Complete Profile gate) |
| P03 Overview | PASS | Welcome + empty activities CTA |
| P04 Explore | PASS | Shortcode render OK (server) |
| P05 Campaign detail | PASS | ORBI loads, Resources download, carousel, FAQ, judging list |
| P06 Join paid | SKIP | ORBI CTA **Already Closed** (expected) |
| P07 **B1 coupon popup** | **PASS** (post-deploy) | Class live; checkout `cwCouponAuth` + JS present; school helper OK |
| P08 Guest full-pay | NOT RUN | Depends on open paid campaign |
| P09–P15 tabs | PASS | overview/explore/link/activities/points/leaderboard/badges/upgrade/settings — all render, no fatals/notices |
| P16 Mobile | PARTIAL | Sidebar present; full mobile gesture not deep-tested |

### Creator

| ID | Result | Evidence |
|----|--------|----------|
| C01–C02 | PASS | Creator Portal + overview |
| C03–C06 tabs | PASS | Server render all tabs OK |
| C07–C08 Profile gate | PASS | Banner: complete profile (image/tagline/address) before public directory |
| C09–C11 | PASS | Profile/badges render |

### Business (non-admin)

| ID | Result | Evidence |
|----|--------|----------|
| B01–B02 | PASS | Business Portal for One Morning Sdn Bhd |
| B03 Campaigns | PASS | Lists ORBI only; Manage entries link; Create New Campaign |
| B04 Wizard | NOT RUN | Avoid creating live campaign without approval |
| B05 Ownership | PASS | ORBI author=202 organizer_id=202; no mismatch on sample campaigns |
| B06 Multi templates | PASS | Live meta `cw_template_files` present; Resources card shows Download Template |
| B07–B08 Design/entries | PARTIAL | Manage entries link present; evaluate modal not deep-clicked this run |
| B09–B12 tabs | PASS | reports/wallet/badges/biz-info render server-side |
| B10 Wallet | PASS | Nav + overview revenue RM 75.00 shown |

### Cross-cutting

| ID | Result |
|----|--------|
| X01–X02 B1 surfaces | **PASS** — deployed |
| X03 Checkout | Coupon field + B1 script present |
| X04 Prose/judging | Judging criteria list present on ORBI |
| X05 Fatals | No fatals on home/login/register/campaign/checkout/dashboards |

---

## UI/UX findings (skill-based)

### P1 — Complete Profile: DOB input `readonly` — **RESOLVED**

- Removed `readonly` on register + complete-profile; live file confirmed no `readonly` on `#birthdate`

### P0 — B1 not on production — **RESOLVED**

- Deployed popup class/JS, loader require, core instantiate, school-coupon helper, auth DOB fix  
- Live verify: class, checkout localize, AJAX hooks, version 11.1.42  

### P2 — Campaign WYSIWYG content uses emoji-led section titles

- ORBI About body uses emoji-style headings in content (not plugin chrome)  
- **Skills:** unslop-ui / no-ai-slop — content hygiene for organizers  
- **Fix:** Optional content guidelines; do **not** force strip emojis from all WYSIWYG (would surprise organizers)  

### P2 — GDPR allow/decline overlays every first visit

- Friction for join conversion; not broken  
- Prefer remember consent cookie (already exists after Allow)

### P2 — Empty states are clear (good)

- Participant “No activities yet” + Explore CTA — passes empty-state check  

---

## Ops note (sandbox)

Novamira sandbox contained many duplicate `class-cw-*.php` / `creativewings-core-*.php` copies that **redeclared classes** and put the site into Novamira safe mode. Those files were renamed to `*.auditbak` during this audit. Only keep intentional sandbox plugins going forward.

Magic login helper was deleted after testing.

---

## Recommended next actions

1. ~~Deploy B1 + DOB fix~~ **done**  
2. ~~Delete throwaway users 276–278~~ **done**  
3. Optionally deep-test: open campaign join + checkout with a real school coupon as guest; business manage-entries evaluate modal; wallet withdrawal  
4. P2 content/GDPR polish only if product wants it  

**No production UI redesign** proposed — site structure is healthy.
