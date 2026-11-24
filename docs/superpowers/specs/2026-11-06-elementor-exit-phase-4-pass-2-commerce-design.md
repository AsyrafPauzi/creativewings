# Phase 4 Pass 2 — Cart / Checkout UX Revamp

**Date:** 2026-11-06  
**Status:** Shipped (2026-11-06) — child theme 0.11.0; Elementor off on cart/checkout 22/23; Pass 2 backlog cutover (148 / 842 / 3700 / 3459 / 119). Phase 5 shipped — Elementor stack deactivated (see Phase 5 cleanup spec).  
**Site:** https://creativewings.asia  
**Parent program:** [Elementor Exit Design](./2026-11-06-elementor-exit-design.md)  
**Depends on:** Phase 4 Pass 1 (campaign single-product theme shell — shipped)

---

## Goal

Replace the WooCommerce-looking cart and checkout experience with a **campaign registration** flow that matches Phase 4 product / join-modal language and visuals, while keeping WooCommerce as the commerce engine (cart session, coupons, guest join, school claim, CommercePay).

Also exit Elementor wrappers on Cart (`22`) and Checkout (`23`).

---

## Decisions (approved)

| Decision | Choice |
|---|---|
| Flow | **A — Two steps, same URLs** (`/cart` → `/checkout`) |
| Implementation | **Approach 2 — Theme Woo templates + registration shell** |
| Language | Registration / entry / fee — never “cart”, “product”, “Cart totals”, “Your order” as primary UI copy |
| Visual family | Phase 4 product stage (soft sky, cwd cards, CW blue + coral CTA) |
| Engine | Keep Woo hooks, payments, coupons, guest DOB, school claim, coupon-auth popup |

---

## Scope

### In scope

1. **Cart page `22` (`/cart`)** — custom “Review entry” UI via child-theme Woo cart template(s) + `phase4-commerce` assets; Elementor cutover.
2. **Checkout page `23` (`/checkout`)** — custom “Complete & pay” UI via child-theme Woo checkout template(s) + same asset family; Elementor cutover.
3. **Copy / chrome cleanup** — hide Woo table/qty/update-cart chrome for campaign entries; step rail; empty state.
4. **Light motion** — step/card entrance only; `prefers-reduced-motion` safe; no ScrollTrigger pin.

### Pass 2 backlog (cut over 2026-11-06 with theme 0.10.3)

- Add-to-Cart Elementor popup `148` → conditions `exclude/general` (Pass 1 join modal owns ATC)
- List competitions `842` → 301 redirect to `/competitions/` (page 460 + theme grid)
- Contest Theme Builder `119` → conditions `exclude/general` (was already empty)
- Guest complete `3700` / complete-profile `3459` → theme page templates + shortcodes; Elementor builder cleared

### Still out of scope

- Changing payment gateway, pricing, or join validation rules
- Elementor plugin removal (Phase 5 — after Phases 1–4 fully settle)

---

## Current state (baseline)

| Surface | Today |
|---|---|
| Cart `22` | Elementor `header_footer` canvas + text widget `[woocommerce_cart]`; `CW_Checkout` shell already wraps (“Your cart” / table / coupon / Proceed to checkout) |
| Checkout `23` | Same pattern + `[woocommerce_checkout]`; guest fields, DOB, artwork meta, CommercePay, “Join activity” |
| CSS | `assets/css/cw-style-checkout.css` + `body.cw-commerce-page` |
| Logic | `CW_Checkout`, `CW_Shop`, `CW_Guest_Join`, `CW_Design_Submission`, `CW_Coupon_Auth_Popup` |

Pass 1 product join already lands users in this cart with entry meta (name, artwork).

---

## Information architecture

### Shared

- Step rail: **1 Review entry** → **2 Complete & pay**
- Active step emphasized; other step links when valid (step 2 disabled/empty if cart empty)
- Empty cart: “No entry yet” + CTA to competitions — not Woo empty-cart prose

### Step 1 — `/cart` “Review entry”

- One **entry card** per cart line: thumb, campaign title, participant name, artwork link, fee
- Hide quantity controls for standard campaign products (qty stays 1)
- Coupon: collapsed “Have a school / sponsor code?” → inline apply
- Primary CTA: **Continue to payment** (Woo checkout URL)
- Remove: Update cart button, product table headers, “Cart totals” heading

### Step 2 — `/checkout` “Complete & pay”

- **Left:** participant / message / eligibility fields (existing CW field set — no field removal)
- **Right (desktop sticky):** entry summary + fee + pay CTA  
- **Mobile:** summary under step rail (or short accordion), then form, sticky fee + CTA foot
- Coupon remains available; school-claim notice remains
- Replace “Your order” table chrome with CW summary card (same line items / totals)
- Keep button intent: **Join activity** / pay via CommercePay

---

## Visual & layout

- Shell width: cart ~960–1100px; checkout two-column up to ~1300px (aligned with existing commerce max)
- Soft sky / stage atmosphere consistent with `cw-phase4--product` (lighter on commerce — no heavy parallax)
- Cards: cwd-style surfaces; coral/primary CTAs matching reg modal
- Payment methods: choice-card styling over raw radio list (gateway behavior unchanged)
- No glassmorphism, no pin-spacer, no generic purple AI gradients

---

## Technical approach

### Theme / templates

- Child theme Woo overrides under `themes/astra-child-creativewings/woocommerce/`:
  - `cart/cart.php` (and fragments as needed)
  - `checkout/form-checkout.php`, `checkout/review-order.php` (and related partials only if required)
- Extend / restyle via `CW_Checkout` hooks where markup already opens shells; prefer template overrides when Woo table structure must change
- New assets: `assets/css/phase4-commerce.css`, `assets/js/phase4-commerce.js` (enqueue on `is_cart()` / `is_checkout()` excluding thank-you if motion not needed there)
- Bump child theme version; keep plugin commerce CSS as base dependency

### Elementor cutover (22 / 23)

1. Backup page template + `_elementor_edit_mode` / data references (and any Theme Builder refs if present)
2. Set template to theme default (not `elementor_header_footer`)
3. Keep post content as `[woocommerce_cart]` / `[woocommerce_checkout]` **or** rely on Woo endpoint rendering via theme — prefer preserving shortcodes if pages stay as assigned WC endpoints
4. Clear builder mode so Elementor does not wrap output
5. Cache bust (Elementor CSS, CW page cache, host cache)

### Rollback (under 15 min)

- Restore page template + Elementor edit mode from backup meta
- Revert / disable `phase4-commerce` enqueue via version rollback of child theme
- Woo templates fall back to plugin/theme defaults when child files removed

---

## Non-goals

- Visual rebrand of the whole site
- Replacing WooCommerce
- Redesigning thank-you / order-received beyond existing success modal
- Changing boss join/checkout field requirements

---

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| Guest DOB / age gates break | Do not rename field IDs; restyle wrappers only; QA guest path |
| School claim + coupon auth popup | Keep existing hooks; visual QA with claim coupon |
| Multi-item carts / qty | Hide qty for campaign SKUs; if multi-entry exists, show one card per line |
| Payment button disabled until fields valid | Preserve Woo checkout JS validation; only restyle |
| Fragment refresh (cart update via AJAX) | Override cart fragments templates if custom markup needs refresh |
| CSS fighting `cw-style-checkout.css` | Phase4 commerce loads after; prefer BEM under `.cw-regflow` namespace |

---

## Success criteria

- Cart and checkout do not read as WooCommerce to a non-technical parent (no cart table / “Cart totals” / product-qty chrome)
- Step rail and registration copy are visible on desktop + mobile (~375 / ~768 / ~1280)
- Guest join, coupon, school claim, artwork preview, CommercePay still complete an order
- Elementor is not required to render cart/checkout chrome
- Rollback documented and under 15 minutes

---

## QA plan (minimum)

1. Open campaign (e.g. OSOW) → join as guest → land on Review entry with meta + artwork
2. Apply / remove coupon; Continue to payment
3. Fill checkout fields; pay path reaches gateway (or sandbox) without JS errors
4. Logged-in user path
5. School-claim / coupon-auth popup path if test coupon available
6. Empty cart state
7. Mobile sticky CTA + summary
8. Confirm pages 22/23 no longer depend on Elementor canvas

---

## Next step after spec approval

Write implementation plan:

`docs/superpowers/plans/2026-11-06-elementor-exit-phase-4-pass-2-commerce.md`

Then implement templates + assets + Elementor cutover + deploy/QA.
