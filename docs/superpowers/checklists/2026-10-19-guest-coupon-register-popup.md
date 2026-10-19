# B1 QA — Guest Coupon → Register Popup

**Spec:** `docs/superpowers/specs/2026-10-19-guest-coupon-register-popup-design.md`  
**Plan:** `docs/superpowers/plans/2026-10-19-guest-coupon-register-popup.md`

## Automated

- [ ] `php tests/test-cw-is-cw-school-coupon.php` exits 0

## Manual (logged-out guest)

- [ ] **Checkout** + school coupon → register modal → new account → coupon applied, stay on checkout
- [ ] **Cart** + school coupon → same register path → coupon applied
- [ ] Existing email on register → switches to Login → coupon applied after login
- [ ] Wrong password → inline error, stays in modal
- [ ] Non–CW coupon → no modal; WooCommerce handles as today
- [ ] Guest full-pay (no school coupon) unchanged
- [ ] JS disabled → school coupon blocked with login/register links in WC notice

## Manual (logged-in)

- [ ] School coupon applies with no modal / script not required
- [ ] No P0 regression on paid guest checkout without school coupon
