# Paid = Heavy, Async — Post-Checkout Performance Design

**Date:** 2026-07-20  
**Status:** Approved — implemented in 11.0.84  
**Scope:** Campaign join via WooCommerce (normal join, not a separate appointment CPT)

## Problem

Under concurrent campaign joins (~12 users), PHP workers spike memory/CPU and the server can hang. Heavy work today is concentrated in:

1. **Pre-pay:** artwork / file uploads (`wp_generate_attachment_metadata` — already lean in 11.0.83)
2. **Post-pay (same HTTP request as payment return):** entry creation, points, badge evaluation, guest registration email, online-access email — often stacked on multiple WooCommerce hooks (`payment_complete`, `processing`, `completed`)

Failed payments are already cheap (no entries). Successful payments pay the full cost inside one worker.

## Goal

- **Payment fail / pending:** order only; no entries, points, badges, or join emails
- **Payment success:** create entries quickly in-request; move non-critical work to WP-Cron
- Keep UX: thank-you still shows; entry exists soon after paid
- Reduce peak post-pay worker time so concurrent paid checkouts are less likely to exhaust PHP-FPM

## Non-goals

- New “appointment” / booking CPT
- Deferring entry creation until cron (would break “I joined” UX and dashboards)
- Full-site redesign / theme optimization
- Changing upload-before-pay (artwork still required at cart/checkout)

## Target flow

```text
Join form + uploads → Cart
        ↓
Place order → WC order status: pending
        ↓
   ┌────┴────┐
   │         │
 fail     success
   │         │
pending/   Fast path (same request):
failed     - create entries (idempotent lock)
           - stamp order meta
           - queue async jobs
           - thank-you / redirect
                 ↓
           Async (WP-Cron ~30–60s):
           - points award
           - badge evaluate (user + organiser)
           - guest complete-registration email
           - online access link email
```

## Fast path (sync, payment success only)

**Trigger (stricter gate):**

- Prefer a single paid gate: `woocommerce_payment_complete` **or** first transition into `processing`/`completed` when gateway skips `payment_complete`
- Guard with existing `_cw_entry_lock` / `_cw_entries_created`
- Do **not** create entries for `pending`, `failed`, `cancelled`, `refunded`

**Work allowed:**

- `CW_Shop::create_entries_from_order()` (and design/guest meta stamps that must live on the entry)
- Queue one deferred job per order (or per user) — do not run points/badges/emails inline

**Work forbidden on this request:**

- `CW_Points::maybe_award_for_order` (move off `cw_entry_created_from_order` for paid path → queued)
- `CW_Badges_Engine::evaluate_user` (already deferrable; ensure always queued after entries)
- `CW_Guest_Join::maybe_send_complete_registration_email`
- `CW_Shop::maybe_send_online_access_link` / `CW_Email::send_online_access_link`

## Async job design

**Hook:** `cw_post_checkout_async` (single event args: `order_id`)

**Schedule:** `wp_schedule_single_event( time() + 30, 'cw_post_checkout_async', [ $order_id ] )` once per order (dedupe via order meta `_cw_post_checkout_queued`).

**Handler responsibilities (idempotent):**

1. Confirm order is paid (`is_paid()` or status in `processing|completed`)
2. Ensure entries exist (call create-entries if lock missing — safety net)
3. Award points once (`_cw_points_awarded` / existing flag)
4. Queue/run badge eval for customer + campaign organisers (reuse `queue_or_evaluate`)
5. Send guest complete-registration email (existing guards)
6. Send online access emails per product (existing `_cw_online_link_sent_pid_*` guards)
7. Mark `_cw_post_checkout_done = yes`

**Failure:** log; leave meta unset so a later paid-status hook or manual reschedule can retry.

## Hook cleanup

| Current | Change |
|---------|--------|
| Entry create on `payment_complete` + `processing` + `completed` | Keep multi-hook for gateway quirks, but body short-circuits after lock; no emails/points inside |
| Guest email on same three hooks | Remove from sync; only async handler |
| Online link from create-entries | Move to async |
| Points on `cw_entry_created_from_order` | Skip when `_cw_defer_points` / async path; award in async |
| Badges on `save_post_*` during create-entries | Keep `cw_defer_badge_eval` + end-of-create queue |

## Performance expectations (honest)

These are **estimates**, not guarantees — hang risk also depends on PHP-FPM children, `memory_limit`, MySQL, and gateway latency.

| Scenario | Expected effect |
|----------|-----------------|
| Failed / abandoned pay | Already cheap; **~0–5%** change |
| Successful pay **request duration** | **~30–60%** shorter when SMTP/email + badge/points leave the request (email alone often 0.5–3s) |
| Peak **memory** on pay return | **~15–35%** lower (less catalog/query/email stacks); uploads still dominate if concurrent |
| Hang risk with 12 concurrent **uploads** | Lean metadata (11.0.83) is the main lever; async post-pay helps the **second** spike after pay — together **meaningfully lower** hang risk, not “impossible to hang” |
| Hang risk with 12 concurrent **pays only** (uploads already done) | **High confidence** improvement — workers free faster for the next request |

**Still required on server:** `memory_limit` ≥ 256M, enough PHP-FPM `pm.max_children`, prefer Redis object cache. Plugin async cannot fix a 128M limit with 12× large PNG thumbnailing.

## Files likely touched

- `includes/class-cw-shop.php` — gate, queue, strip online email from sync create
- `includes/class-cw-guest-join.php` — email only from async (or callable from handler)
- `includes/class-cw-points.php` — defer award when async flagged
- `includes/badges/class-cw-badges-engine.php` — already deferred; wire into async completion
- New small helper optional: `includes/class-cw-post-checkout.php` — queue + cron handler (preferred for clarity)
- `creativewings-core.php` / loader — bootstrap handler class; version bump when shipping

## Testing

1. Guest join → pay success → entry exists immediately; email arrives within ~1–2 minutes (cron)
2. Guest join → pay fail → pending/failed; **no** entry; **no** complete-reg email
3. Logged-in join → pay success → entry + points after async; badges eventually
4. Online campaign → access email only after async, once
5. Double gateway callbacks → no duplicate entries/points/emails
6. Free/100% coupon order → still creates entries + queues async

## Rollout

Ship with plugin version bump (e.g. after 11.0.83). No DB migration required beyond order meta keys.
