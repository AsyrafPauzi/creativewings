# Customer Points & Redeem — Pilot Checklist (v11.1.0)

Use on https://creativewings.asia after deploy.

## Earn
- [ ] Pay RM15 cash join → My Account → Points shows **+15**
- [ ] Free / 100% sponsor-coupon join → **+0** points (expiry may still refresh)

## Merchandise
- [ ] WP Admin → WooCommerce → **Points Merchandise**: create one item (e.g. 200 pts)
- [ ] Item appears only under My Account → Points (not public shop)
- [ ] Customer redeems → balance drops; admin sees **pending** redemption
- [ ] Admin marks fulfilled

## Checkout spend
- [ ] Customer with ≥10 pts joins next campaign
- [ ] Checkout shows **Use your points**; apply N pts → fee reduced by N/100 RM
- [ ] After payment, balance debited; order meta `_cw_points_spent` set
- [ ] Example: 15 pts on RM15 fee → pay RM14.85 → remaining 0

## Regression
- [ ] Business dashboard has **no** sponsorship-points UI
- [ ] School Angel/sponsor coupons still work as before
