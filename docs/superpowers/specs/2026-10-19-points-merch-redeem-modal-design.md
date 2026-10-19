# Points merchandise — detail modal redeem (Physical + Digital)

**Date:** 2026-10-19  
**Status:** Approved — 2026-10-19  
**Parent:** Contestant My Points tab  

---

## 1. Goal

Let contestants browse merchandise with a clear **detail view**, **confirm** before spending points, and collect a **shipping address only when needed** (Physical). Digital rewards skip address. Merchandise stays on the Points page only — not the WooCommerce shop.

---

## 2. Locked decisions

| # | Topic | Decision |
|---|--------|----------|
| 1 | Fulfillment types | **Both** Physical and Digital from day one |
| 2 | Catalog surface | Merchandise **only** on My Points (not Explore / shop) |
| 3 | Checkout | **No WooCommerce checkout** for merch |
| 4 | UI pattern | **Single multi-step modal** (Approach 1) |
| 5 | Points debit | On final confirm (after address if required) |
| 6 | Address | Required for Physical if profile has none; prefill + save when present/submitted |
| 7 | Digital | Confirm → debit; no address |
| 8 | Woo shop products | Out of scope — campaigns remain the only shop/Explore products |

---

## 3. User flow

```
My Points → merch grid
  → click card (or “View”)
  → Modal step: Detail (image, title, description, pts, stock, Physical|Digital)
  → Redeem
  → Modal step: Confirm (“Spend X pts on Y?”)
  → if Physical AND no saved address
       → Modal step: Address form (required fields)
       → Save to user profile
  → Debit points (ledger redeem_merch) + create redemption pending
  → Success toast / flash; Recent activity shows Merchandise (−pts)
  → Merchandise redemptions table updates
```

Cancel / × closes modal without debiting.

---

## 4. Address

**When:** Physical reward and user has no usable shipping address on file.

**Fields (minimum):** full name, phone, address line 1, city, postcode, state, country.

**Storage:** Reuse Woo customer billing/shipping meta where practical (`billing_*` / `shipping_*`) so future profile edits stay consistent — without creating a WC order.

**Prefill:** If any of those fields exist, skip the address step (or show read-only summary with “Edit” — MVP: skip if complete).

**Complete =** name + line1 + city + postcode + country (phone recommended; require phone in MVP).

---

## 5. Data model

### Reward catalog (`cw_points_rewards`)

Add:

| Column | Type | Notes |
|--------|------|--------|
| `fulfillment` | `VARCHAR(20)` | `physical` \| `digital`; default `physical` for existing rows |

Admin: radio or select when saving a reward.

### Redemptions

Keep existing table. Optionally store a JSON snapshot of shipping address on the redemption `note` or a later column — MVP: copy address text into `note` for ops, or rely on user meta at redeem time + admin lookup.

**MVP ops:** On physical redeem, append formatted address into redemption `note` so fulfillment staff see it without hunting profile.

---

## 6. UI

### Grid

- Card shows thumbnail, title, short meta (pts + stock), fulfillment pill.
- Primary action: open detail (whole card clickable or explicit “View”).
- Do **not** submit redeem directly from the grid.

### Modal steps

1. **Detail** — large image, title, description, cost, stock, type pill, Redeem / Close  
2. **Confirm** — summary + Cancel / Confirm redeem  
3. **Address** (conditional) — form + Back / Continue  
4. **Done** (optional brief) — or close + page flash

Match contestant tokens (`cw-pts-*` / Overview restraint). Close button sized like other CW modals (28–32px).

---

## 7. Server

- Extend `CW_Points_Rewards::redeem()` to accept/require address for physical; reject if physical and address incomplete.
- Debit via existing `CW_Points::debit( …, 'redeem_merch', … )`.
- Keep stock reservation logic.
- AJAX preferred for modal steps (nonce + reward_id); fallback: multi-step POST if simpler in first ship.

**Security:** logged-in contestant only; verify balance + stock server-side; never trust client “can redeem”.

---

## 8. Explicit non-goals

- WooCommerce cart/checkout/orders for merch  
- Merch products in the public shop or Explore  
- Automated digital file delivery / download emails (ops fulfill manually)  
- Shipping rate calculation  
- Instant redeem without confirm  

---

## 9. Success criteria

- User can open rich merch detail before spending.  
- Confirm always shown before debit.  
- Physical without address cannot complete until address saved.  
- Digital never asks for address.  
- Shop/Explore unchanged (campaigns only).  
- Ledger + redemptions list update after success.  

---

## Spec self-review

- No placeholders left intentional.  
- No contradiction with “merch not in shop” + “no Woo checkout”.  
- Scope limited to Points modal redeem + admin fulfillment flag.  
