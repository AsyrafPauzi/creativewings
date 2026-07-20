# Paid = Heavy, Async — Implementation Plan

> **For agentic workers:** Implement task-by-task. Spec: `docs/superpowers/specs/2026-07-20-paid-async-post-checkout-design.md`

**Goal:** On successful campaign payment, create entries sync; run points, badges, and post-pay emails via WP-Cron so the payment request stays short.

**Architecture:** New `CW_Post_Checkout` queues one job per paid order. Sync hooks only create entries + queue. Async handler awards points, badges, guest email, online-access email with existing idempotency meta.

**Tech stack:** WordPress, WooCommerce, WP-Cron, existing CW_Shop / CW_Points / CW_Badges_Engine / CW_Guest_Join / CW_Email.

## Global Constraints

- Do not create entries for pending/failed/cancelled
- Entry creation stays sync after paid
- Version bump when shipping (11.0.84)
- No new appointment CPT

---

### Task 1: CW_Post_Checkout queue + handler

**Files:**
- Create: `includes/class-cw-post-checkout.php`
- Modify: `includes/class-cw-loader.php`, `creativewings-core.php`

- [ ] Add class with `HOOK = cw_post_checkout_async`, meta `_cw_post_checkout_queued`, `_cw_post_checkout_done`
- [ ] `queue( $order_id )` — paid check, dedupe, `wp_schedule_single_event( time() + 30, ... )`
- [ ] `run( $order_id )` — ensure entries, points, badges, guest email, online emails
- [ ] Load + `CW_Post_Checkout::register_hooks()` in plugin init
- [ ] Bump `CW_VERSION` / header to `11.0.84`

### Task 2: Slim sync payment path

**Files:**
- Modify: `includes/class-cw-shop.php`, `includes/class-cw-guest-join.php`, `includes/class-cw-points.php`

- [ ] After successful `create_entries_from_order`, call `CW_Post_Checkout::queue( $order_id )`
- [ ] Skip `maybe_send_online_access_link` during sync create; call from async only
- [ ] Remove guest email from `payment_complete` / `processing` / `completed`; keep method public for async
- [ ] Points: if order has `_cw_post_checkout_queued` and not done, skip inline award on `cw_entry_created_from_order` (async awards)

### Task 3: Manual QA checklist

- [ ] Paid join → entry immediate; email/points within ~1–2 min (or spawn cron)
- [ ] Failed pay → no entry
- [ ] Double callback → no duplicates
