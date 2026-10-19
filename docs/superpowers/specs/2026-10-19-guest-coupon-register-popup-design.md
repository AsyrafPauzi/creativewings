# B1 — Guest Coupon → Register Popup

**Date:** 2026-10-19  
**Status:** Approved — 2026-10-19 · Implemented (pending live QA)  
**Phase:** Q4 2026 Phase B (must-ship)  
**Parent:** `docs/superpowers/specs/2026-09-24-student-join-conversion-design.md` §4  
**Plan (team):** `docs/superpowers/plans/2026-09-24-phase-b-student-growth.md`

---

## 1. Goal

Let guests finish a **school/sponsor coupon** join without abandoning checkout: when they try to apply a CW school coupon while logged out, show an inline **Register / Login** popup, then auto-login and re-apply the coupon.

**Primary metric:** More completed joins that use school/sponsor coupons (guest path no longer dead-ends).

---

## 2. Locked decisions

| # | Topic | Decision |
|---|--------|----------|
| 1 | Trigger | **A** — Intercept on Apply coupon **before** WooCommerce applies it |
| 2 | Coupon scope | **A** — Only Creative Wings school / sponsor coupons (`_cw_campaign_id` set) |
| 3 | Existing email | **A** — Switch modal to **Login** (email prefilled), then re-apply coupon |
| 4 | Surfaces | **B** — Cart **and** checkout |
| 5 | Architecture | JS intercept + AJAX register/login (Approach 1) |
| 6 | Email verify | **Not required** to unlock coupon (verify→points = later / C1) |
| 7 | Guest without school coupon | Still allowed to pay full price |
| 8 | Social login in modal | **Out of B1** |

---

## 3. User flow

```
Guest on cart/checkout
  → enters coupon, clicks Apply
  → JS intercepts
  → AJAX: is CW school/sponsor coupon?
       NO  → let WooCommerce apply as today
       YES + logged out → open Register/Login modal
  → Register (or Login) succeeds
  → auto-login session
  → client re-applies stashed coupon via WC
  → stay on same cart/checkout page
```

Logged-in users never see the modal.

---

## 4. Architecture

### Components

| Piece | Responsibility |
|-------|----------------|
| `CW_Sponsor_Coupons` | Helper `is_cw_school_coupon( $code )` — true when coupon exists and has `_cw_campaign_id` |
| AJAX handlers | Classify coupon; register; login; return `{ ok, coupon }` |
| Cart/checkout JS | Intercept apply buttons; modal UI; stash + re-apply coupon |
| Server safety net | If guest applies CW school coupon without JS, reject with login/register hint |

### Prefer reuse

- Registration field rules and user meta: align with `CW_Auth::handle_registration` (`cw_full_name`, `birthdate`, PDPA, `contestant` role, `mark_contestant_onboarded`).
- Rate limit / honeypot patterns from `CW_Auth` / `CW_Security` where practical.

### Explicit non-goals (B1)

- Register +50 / first-join +50 points (**B2**)
- Platform invite (**B4**)
- Social login inside the modal
- Changing school coupon validity rules beyond guest gate

---

## 5. Modal UI

### Register mode (default)

- Title: “Register to use this school coupon”
- Fields: email, password, full name, DOB
- PDPA consent checkbox (required; same spirit as site register)
- Primary CTA: **Create account & apply coupon**
- Secondary: “Already have an account? **Log in**”

### Login mode

- Email (prefilled when switching from register after `email_exists`), password
- Primary CTA: **Log in & apply coupon**
- Secondary: “Need an account? **Register**”

### Edge cases

| Case | Behavior |
|------|----------|
| Email already exists on register | Switch to Login; keep email; message: “Account found — please log in” |
| Wrong password | Inline error; stay in modal |
| Invalid / missing fields | Inline + server validation |
| Coupon invalid after login | User stays logged in; WooCommerce shows normal coupon error |
| Non–school coupon | No modal |
| Already logged in | No modal |
| JS disabled | Server rejects CW school coupon for guests; message + link to login/register |

---

## 6. API contract

All endpoints: logged-out OK, nonce required, rate-limited.

| Action | Input | Success | Notable errors |
|--------|-------|---------|----------------|
| `cw_coupon_requires_auth` | `code` | `{ requires_auth, is_cw_school }` | invalid empty code |
| `cw_checkout_register` | email, password, name, dob, pdpa, `coupon` | `{ ok: true, coupon }` + auth cookie | `email_exists`, `missing_fields`, `pdpa_required`, `rate_limit` |
| `cw_checkout_login` | email, password, `coupon` | `{ ok: true, coupon }` + auth cookie | `invalid_credentials` |

After `ok: true`, client applies `coupon` through the normal WooCommerce coupon UI/API without leaving the page.

**User created on register:** role `contestant`; meta matching existing register path; session cookie set immediately; no email verification gate.

---

## 7. Server safety net

Hook WooCommerce coupon validation (or apply filter) so that:

- If current user is **not** logged in **and** coupon is CW school/sponsor → treat as invalid for guests.
- Error copy: short message that school coupons require an account, plus links to login and register pages.

This prevents bypassing the popup with DevTools / JS off. (The modal is JS-only; the safety net is text + links.)

---

## 8. Success criteria (QA)

1. Guest + school coupon on **checkout** → modal → register → coupon applied, still on checkout.  
2. Same on **cart**.  
3. Guest + existing email → login path → coupon applied.  
4. Guest + non-CW coupon → no modal; WC handles as today.  
5. Logged-in member → no modal.  
6. Guest full-pay (no school coupon) unchanged.  
7. JS off → school coupon blocked with login/register hint.  
8. No P0 regression on logged-in coupon apply or paid guest checkout.

---

## 9. Implementation sketch (for later plan)

Suggested touch points (not exhaustive):

- `includes/class-cw-sponsor-coupons.php` — `is_cw_school_coupon()` + guest reject filter  
- New or extended AJAX class / `CW_Auth` methods for register/login JSON  
- `assets/js/` cart+checkout coupon intercept + modal  
- Enqueue only on cart/checkout  
- Minimal CSS for modal (reuse checkout/modal tokens if present)

---

## 10. Decision log (this brainstorm)

| # | Question | Locked |
|---|----------|--------|
| 1 | When does popup open? | Intercept before WC apply |
| 2 | Which coupons? | CW school/sponsor only |
| 3 | Existing email? | Switch to Login in modal |
| 4 | Where? | Cart + checkout |
| 5 | Approach? | JS + AJAX register/login |
| 6 | Design §§1–3 | Approved 2026-10-19 |
