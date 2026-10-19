# Points Merch Redeem Modal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Multi-step My Points merchandise modal: detail → confirm → address (physical if missing) → debit; support Physical and Digital; no Woo checkout.

**Architecture:** Extend `cw_points_rewards` with `fulfillment`. Contestant grid opens a `cw-pts-merch-modal`. AJAX redeem after confirm; physical requires complete shipping profile (Woo `shipping_*`/`billing_*` meta). Debit via existing `CW_Points::debit( redeem_merch )`.

**Tech Stack:** WordPress PHP, Woo user meta for address, contestant CSS, admin-post/AJAX.

**Spec:** `docs/superpowers/specs/2026-10-19-points-merch-redeem-modal-design.md`

## Global Constraints

- No WooCommerce cart/checkout/orders for merch
- Merch only on My Points (not shop/Explore)
- Confirm always before debit
- Address only for Physical when incomplete
- Default existing rewards to `physical`
- Bump `CW_VERSION` on ship

## File map

| File | Role |
|------|------|
| `includes/class-cw-points-rewards.php` | Schema `fulfillment`, address helpers, redeem gate |
| `includes/admin/class-cw-points-rewards-admin.php` | Admin fulfillment field |
| `includes/dashboard/class-cw-dashboard-contestant.php` | Grid + modal markup, AJAX handlers |
| `assets/css/cw-style-contestant.css` | Modal styles |
| `creativewings-core.php` | Version |

---

### Task 1: Schema + redeem gate

**Files:** Modify `includes/class-cw-points-rewards.php`

- [x] Bump `DB_VERSION`; add `fulfillment` column; backfill `physical`
- [x] `save_reward` persists fulfillment
- [x] Helpers: `user_has_shipping_address( $uid )`, `get_user_shipping_address( $uid )`, `save_user_shipping_address( $uid, $fields )`
- [x] `redeem( $uid, $reward_id, $address = null )` — physical requires complete address; append formatted address to redemption note

---

### Task 2: Admin fulfillment field

**Files:** Modify `includes/admin/class-cw-points-rewards-admin.php`

- [x] Physical / Digital select on save form
- [x] Show type in catalog table

---

### Task 3: Contestant modal + AJAX

**Files:** Modify `includes/dashboard/class-cw-dashboard-contestant.php`

- [x] Merch cards open modal (data-json or data attributes); remove grid direct redeem submit
- [x] Modal steps: detail / confirm / address / done
- [x] `wp_ajax_cw_points_merch_redeem` + optional `cw_points_merch_save_address`
- [x] Enqueue inline JS on points tab

---

### Task 4: CSS + version

**Files:** `assets/css/cw-style-contestant.css`, `creativewings-core.php`, Vite build

- [x] Modal styles (panel, steps, address form, close 28px)
- [x] Include pending pts-steps 3-column layout if not already shipped
- [x] Bump version; deploy when asked
