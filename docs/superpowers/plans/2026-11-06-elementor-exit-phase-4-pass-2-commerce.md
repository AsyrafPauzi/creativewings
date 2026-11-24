# Phase 4 Pass 2 — Cart / Checkout Registration Flow

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Woo-looking cart/checkout with a two-step campaign registration UI while keeping WooCommerce as the engine, and exit Elementor on pages 22/23.

**Architecture:** Child-theme Woo template overrides under `.cw-regflow` + `phase4-commerce` CSS/JS; reuse `CW_Checkout` / guest / claim / coupon hooks; Elementor cutover mirrors Pass 1 (backup meta → default template → clear builder mode).

**Tech Stack:** Astra child `astra-child-creativewings`, WooCommerce 10.x templates, existing `cw-style-checkout.css`, GSAP (light, optional), Novamira deploy.

**Spec:** `docs/superpowers/specs/2026-11-06-elementor-exit-phase-4-pass-2-commerce-design.md`

## Global Constraints

- Flow: `/cart` = Review entry → `/checkout` = Complete & pay (same URLs)
- Copy: registration / entry / fee — never primary “cart”, “product”, “Cart totals”, “Your order”
- Do not rename Woo field `name`/`id` attributes (guest DOB, billing, payment)
- No ScrollTrigger pin / glassmorphism
- Campaign line qty UI hidden (qty stays 1)
- Popup 148, list-competitions, guest pages: out of this plan
- Child theme version bump for cache bust (0.8.2 → 0.9.0)

## File map

| File | Responsibility |
|---|---|
| `themes/.../template-parts/commerce/regflow-steps.php` | Shared step rail partial |
| `themes/.../woocommerce/cart/cart.php` | Review-entry layout (cards, coupon, CTA) |
| `themes/.../woocommerce/cart/cart-empty.php` | Empty state copy + competitions CTA |
| `themes/.../woocommerce/checkout/form-checkout.php` | Complete & pay shell + step rail |
| `themes/.../woocommerce/checkout/review-order.php` | Summary card (not Woo table chrome) |
| `themes/.../assets/css/phase4-commerce.css` | Regflow visuals |
| `themes/.../assets/js/phase4-commerce.js` | Coupon disclosure, light motion |
| `themes/.../functions.php` + `style.css` | Enqueue on cart/checkout; version |
| `includes/class-cw-checkout.php` | Soft copy tweaks in shell headers if needed |

---

### Task 1: Shared step rail + enqueue scaffold

**Files:**
- Create: `themes/astra-child-creativewings/template-parts/commerce/regflow-steps.php`
- Create: `themes/astra-child-creativewings/assets/css/phase4-commerce.css` (stub tokens + step rail)
- Create: `themes/astra-child-creativewings/assets/js/phase4-commerce.js` (stub)
- Modify: `themes/astra-child-creativewings/functions.php` — enqueue when `is_cart()` or `is_checkout()` (not order-received)
- Modify: `themes/astra-child-creativewings/style.css` — Version `0.9.0`
- Modify: `CW_CHILD_VERSION` to `0.9.0`

**Interfaces:**
- Produces: `cw_child_regflow_steps( string $current )` where `$current` is `review`|`pay`
- Produces: handles `cw-child-phase4-commerce` CSS/JS

- [ ] **Step 1:** Add `cw_child_regflow_steps()` rendering ol/nav with two links (cart URL / checkout URL); mark current; disable pay link when cart empty
- [ ] **Step 2:** Enqueue CSS after `cw-style-checkout` when present; enqueue JS in footer; bump version
- [ ] **Step 3:** Verify locally that functions.php has no syntax errors (`php -l`)

---

### Task 2: Cart “Review entry” templates

**Files:**
- Create: `themes/astra-child-creativewings/woocommerce/cart/cart.php`
- Create: `themes/astra-child-creativewings/woocommerce/cart/cart-empty.php`
- Modify: `includes/class-cw-checkout.php` — `render_cart_shell_open()` copy → eyebrow “Registration”, title “Review your entry”, subtitle matching spec

**Interfaces:**
- Consumes: `cw_child_regflow_steps( 'review' )`
- Consumes: `WC()->cart`, `wc_get_formatted_cart_item_data()`, product thumbnails
- Produces: markup root `.cw-regflow.cw-regflow--review`

- [ ] **Step 1:** Copy Woo `cart/cart.php` structure as base; replace `shop_table` with entry cards loop
- [ ] **Step 2:** Per line: thumb, title, item data (name/artwork), line subtotal; remove qty inputs (hidden input qty=1); no Update cart button
- [ ] **Step 3:** Coupon block as disclosure; totals strip; CTA “Continue to payment” → `wc_get_checkout_url()`
- [ ] **Step 4:** Empty cart template with competitions CTA (`/competitions/`)
- [ ] **Step 5:** Update `CW_Checkout` cart shell header strings

---

### Task 3: Checkout “Complete & pay” templates

**Files:**
- Create: `themes/astra-child-creativewings/woocommerce/checkout/form-checkout.php`
- Create: `themes/astra-child-creativewings/woocommerce/checkout/review-order.php`
- Modify: `includes/class-cw-checkout.php` — checkout shell header → “Complete & pay” / registration eyebrow

**Interfaces:**
- Consumes: `cw_child_regflow_steps( 'pay' )`
- Consumes: existing checkout fields / payment hooks
- Produces: `.cw-regflow.cw-regflow--pay` wrapping form; summary `.cw-regflow-summary`

- [ ] **Step 1:** Override `form-checkout.php` with step rail + grid; keep `do_action` checkout hooks Woo expects
- [ ] **Step 2:** Override `review-order.php` as summary cards (lines + fee + total) without shop_table chrome classes as primary UI
- [ ] **Step 3:** Ensure `#order_review`, `#payment`, place-order button IDs preserved for Woo/CommercePay JS
- [ ] **Step 4:** Update checkout shell header copy

---

### Task 4: Visual system + motion

**Files:**
- Modify: `themes/astra-child-creativewings/assets/css/phase4-commerce.css`
- Modify: `themes/astra-child-creativewings/assets/js/phase4-commerce.js`

- [ ] **Step 1:** Style `.cw-regflow` stage, step rail, entry cards, coupon disclosure, sticky summary, payment choice cards, mobile sticky CTA
- [ ] **Step 2:** Override/neutralize leftover Woo table styles under `.cw-regflow` (hide qty, update cart, Cart totals headings)
- [ ] **Step 3:** JS: coupon disclosure toggle; optional GSAP fade-in if `gsap` present; honor `prefers-reduced-motion`

---

### Task 5: Elementor cutover pages 22 & 23

**Files:** live WP via Novamira `execute-php` (no repo file)

- [ ] **Step 1:** Backup for each page: `_wp_page_template`, `_elementor_edit_mode`, `_elementor_template_type` → `_cw_elementor_page_backup_p4p2` (JSON)
- [ ] **Step 2:** Set template to `default` (or empty); delete/clear `_elementor_edit_mode`; ensure content still has Woo shortcodes or WC endpoints resolve
- [ ] **Step 3:** Clear Elementor CSS cache / CW page cache; confirm front HTML has `.cw-regflow` and no Elementor canvas wrapper on main content

---

### Task 6: Deploy + QA

- [ ] **Step 1:** Deploy child theme zip (and plugin if `class-cw-checkout.php` changed) via Novamira
- [ ] **Step 2:** QA checklist from spec (guest join → review → checkout fields → pay button; coupon; empty cart; mobile; logged-in)
- [ ] **Step 3:** Mark spec status Approved/Shipped; note Pass 2 follow-ups still open (148, 842, guest pages)

---

## Rollback

1. Restore page 22/23 meta from `_cw_elementor_page_backup_p4p2`
2. Revert child theme to 0.8.2 (or remove `woocommerce/cart|checkout` overrides)
3. Bust caches
