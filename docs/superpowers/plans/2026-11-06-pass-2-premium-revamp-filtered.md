# Pass 2 Premium Revamp — Filtered Plan (from brief)

> Derived from the user’s Premium Cart & Checkout brief. **Irrelevant items removed** after inspecting live CW + Woo behaviour.

**Goal:** Premium UI/UX polish on existing regflow cart/checkout. No Woo rebuild. No field/key/gateway changes.

## Kept (relevant)

| Brief ask | CW reality |
|---|---|
| Preserve all fields / IDs / validation / AJAX / hooks | Required — CSS + template chrome only |
| Preserve coupons, totals, CommercePay | Keep; restyle only |
| Cart review + checkout pay, 2-column desktop | Keep / refine |
| Sticky order summary on checkout | Keep / refine |
| Empty-cart CTA to campaigns | Keep (update copy) |
| Premium forms, cards, hierarchy, brand colours | Implement |
| Payment options as selectable cards | Keep / refine |

## Removed (not relevant to CW)

| Brief ask | Why dropped |
|---|---|
| **3-step** progress (Review → Registration → Pay) | Live flow is **2 steps**: Review entry → Complete & pay. No separate registration page in this path. |
| Prominent **quantity** adjusters | Campaign entries are sold individually / qty 1; qty stays hidden (engine unchanged). |
| Promoted **shipping** UI | Campaign carts are typically virtual; Woo still calculates shipping if ever needed — no dedicated shipping redesign. |
| New “Registration Details” form block **on cart** | Participant data is collected in join modal + checkout; cart only **displays** cart item meta. |
| CTA label “Proceed to Checkout” | Keep CW copy: **Continue to payment**. |
| Inventing address/phone/company sections | Guest/organiser field modes already inject/hide via `CW_Guest_Join` — visual only, no structural regroup that breaks hooks. |
| Fake payment buttons / simulated gateways | Out — only style live `capgateway`. |
| Hardcoded campaign/prices | Out. |

## Copy (aligned + brief-inspired)

- Cart title: **Review your registration**
- Cart subtitle: Review your campaign entry before continuing to payment.
- Checkout title: **Complete your registration**
- Checkout subtitle: You’re almost there. Confirm details and pay securely.
- Empty: **Your registration is waiting** + Explore campaigns
- Cart primary: Continue to payment  
- Cart secondary: Explore campaigns  
- Steps: 1 Review entry · 2 Complete & pay

## Implementation surface

- `phase4-commerce.css` — form polish, summary, payment cards, secondary CTA
- `class-cw-checkout.php` — header strings
- `cart-empty.php`, `cart-totals.php`, `proceed-to-checkout-button.php` — empty/secondary CTA
- Theme version **0.9.3** (+ plugin bump if checkout strings change)

## Non-goals

Popup 148, list-competitions, guest profile pages, Elementor removal.
