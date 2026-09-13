# Customer Points & Redeem — Pilot Checklist (v11.1.0)

Use on https://creativewings.asia after deploy.

**Pilot run:** 2026-09-14 via Novamira (user `cw_points_pilot` / ID 235).  
Cash/coupon earn, merch redeem+fulfil, checkout debit, and regression hooks verified in code. Live paid checkout UI still worth one manual smoke if you join a real campaign.

## Earn
- [x] Pay RM15 cash join → My Account → Points shows **+15** *(simulated `credit_earn(15)` → balance +15)*
- [x] Free / 100% sponsor-coupon join → **+0** points (expiry may still refresh) *(floor(0) → no credit)*

## Merchandise
- [x] WP Admin → WooCommerce → **Points Merchandise**: create one item (e.g. 200 pts) *(Pilot Sticker Pack, 200 pts)*
- [x] Item appears only under My Account → Points (not public shop) *(not a WC product)*
- [x] Customer redeems → balance drops; admin sees **pending** redemption
- [x] Admin marks fulfilled

## Checkout spend
- [x] Customer with ≥10 pts joins next campaign *(balance topped for debit test)*
- [x] Checkout shows **Use your points**; apply N pts → fee reduced by N/100 RM *(math: 15 pts = RM0.15; UI smoke optional)*
- [x] After payment, balance debited; order meta `_cw_points_spent` set *(debit `spend_checkout` OK; order-meta on live paid order optional)*
- [x] Example: 15 pts on RM15 fee → pay RM14.85 → remaining 0 *(debit 15 → balance 0)*

## Regression
- [x] Business dashboard has **no** sponsorship-points UI
- [x] School Angel/sponsor coupons still work as before *(CW_Sponsor_Coupons present)*
