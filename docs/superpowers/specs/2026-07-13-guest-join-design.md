# Guest Join Flow — Design Spec

**Date:** 2026-07-13  
**Status:** Approved for implementation  
**Approach:** Session cart guest (Approach 1)

## Problem

Logged-out visitors must log in or register before joining a campaign. That friction reduces conversion. Organisers want a simpler path: submit and pay first, create an account later.

## Goals

- Keep the existing login / register / Join Now flow.
- Add **Join as guest** on every open campaign (normal registration only).
- Guest fills campaign fields → checkout → pay → entry is valid.
- After payment, email a secure link to complete account registration.
- Collect DOB on guest checkout for age eligibility and later account profile.

## Non-goals

- Guest claim / school link-submission flow (stays login-required).
- Per-campaign “allow guest” toggle (always available on open campaigns).
- Forcing account completion before entry is valid.
- Changing logged-in age/DOB behaviour.

## Decisions

| Topic | Decision |
|--------|----------|
| Complete-registration form | Account only (password); email/name/DOB prefilled from order |
| DOB collection | Guest checkout page (with billing) |
| Age check | Guest checkout only; logged-in users keep profile birthdate |
| Existing email at checkout | Block guest → login → restore cart/form (A+) |
| Scope | Every open campaign, normal Join only |
| Claim/school codes | Not guest |
| Complete-reg email | Immediately after successful payment |
| Never finishes account | Entry stays valid |

## User flows

### A. Logged out — choose path

1. CTA shows **Join competition** or **Join activity** (by product category).
2. Popup options: Login | Register | Join as guest.
3. Login / Register keep current auth pages (with `redirect_to` back to campaign).
4. Join as guest opens the same registration field modal as logged-in Join Now.

### B. Guest submit → checkout

1. Guest fills campaign fields (custom fields, uploads, design submission if enabled).
2. Submit adds product to cart under a guest WC session (same cart item data as logged-in).
3. Redirect to checkout.

### C. Guest checkout

1. Standard guest billing (WooCommerce guest checkout must be allowed for this path).
2. Required **Date of birth** field for guests with a CW campaign cart.
3. If campaign has age brackets enabled and configured: DOB must match a bracket; otherwise block place-order.
4. If billing email already belongs to a WP/WC user: block place-order; prompt login; after login restore cart + reopen join modal with data preserved when possible.
5. On success: create order as guest, save DOB on order meta.

### D. After payment

1. Create entry as today (valid without linked user account, or linked when account is later created).
2. Generate one-time secure token bound to order (+ email).
3. Email “Complete your registration” with token URL.
4. Entry remains valid even if account is never completed.

### E. Complete registration page

1. Token-gated page/shortcode; invalid/used/expired token cannot access the form.
2. Prefill email, name, DOB (read-only or locked).
3. User sets password (and confirm).
4. Create contestant user; set `birthdate` user meta from order; attach order + entries to user; mark token used; log user in → my-account / dashboard.

## Data

- Order meta: `cw_guest_dob`, `cw_guest_complete_token`, `cw_guest_complete_token_expires`, `cw_guest_account_completed` (yes/no).
- Optional entry meta mirror: `cw_guest_dob` for gallery/reporting convenience.
- Reuse existing age helpers in `CW_Staged_Submissions::age_from_birthdate` / `resolve_age_bracket` where possible (extract shared helpers if needed).

## Security

- Complete-registration tokens: cryptographically random, hashed at rest, single-use, time-limited (e.g. 14 days).
- Page only works for guest orders that are paid and not yet completed.
- Existing-email check prevents creating a second account for the same email via guest path.

## UI copy

- Competition CTA: “Join competition”
- Activity CTA: “Join activity”
- Gate popup: Login / Register / Join as guest
- Guest checkout DOB label: “Date of birth” (required)
- Age fail: clear message that age does not match this campaign
- Email subject: Complete your registration / similar

## Out of scope for v1

- Reminder emails if incomplete
- Guest claim-code flow
- Changing public gallery rules for guest entries
