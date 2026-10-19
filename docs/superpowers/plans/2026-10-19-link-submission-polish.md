# Link Submission Polish + Overview Quick-Link Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Polish the contestant Link submission page to Overview quality and add an Overview “Link a code” modal for enter-only quick claim.

**Architecture:** Full `?tab=link-submission` page remains source of truth for confirm/waiting/pending. Overview opens a small enter-code modal that POSTs the existing `cw_claim_lookup` form; server redirects unchanged. Styles live in contestant CSS (`cw-claim-*` + `cw-ov-link-*`).

**Tech Stack:** WordPress/WooCommerce PHP, CW contestant dashboard CSS, Vite asset build if CSS is in the pipeline, Novamira deploy.

**Spec:** `docs/superpowers/specs/2026-10-19-link-submission-polish-design.md`

## Global Constraints

- Approach A only — no full claim-in-popup
- Claim handlers / redirects / nonces unchanged
- Contestant CW CSS tokens — not Tailwind/shadcn
- Bump `CW_VERSION` on ship
- unslop: no emoji decoration, no icon-in-colored-square, no purple glow

## File map

| File | Role |
|------|------|
| `includes/class-cw-claim-flow.php` | Enter/pending/confirm/waiting markup polish |
| `includes/dashboard/class-cw-dashboard-contestant.php` | Overview CTA + modal markup/JS |
| `assets/css/cw-style-contestant.css` | Page + modal styles |
| `creativewings-core.php` | Version bump |
| `assets/dist/*` | Rebuild if Vite bundles contestant CSS |

---

### Task 1: Polish claim enter + pending markup

**Files:**
- Modify: `includes/class-cw-claim-flow.php` (`render_claim_shell_open`, `render_enter_step`, `render_enter_step_form`, `render_pending_cards`)

**Interfaces:**
- Consumes: existing `cw_claim_lookup` / cancel / continue forms
- Produces: markup using `cw-claim-page`, `cw-claim-hero`, `cw-claim-steps`, `cw-claim-enter`, `cw-pending-list` / `cw-pending-row`

- [x] **Step 1:** Replace long subtitle with short subtitle + 3 how-it-works steps (Enter code → School uploads → Checkout when ready).
- [x] **Step 2:** Restructure enter form as hero: label, large input, Continue; keep same field names/nonce/action.
- [x] **Step 3:** Restyle pending list as status rows (Ready / Waiting) without changing actions.
- [x] **Step 4:** Smoke: page still posts to `admin-post.php?action=cw_claim_lookup`.

---

### Task 2: Contestant CSS for claim page + Overview modal

**Files:**
- Modify: `assets/css/cw-style-contestant.css` (claim section ~1146+)
- Run Vite build if plugin serves dist CSS

- [x] **Step 1:** Style `cw-claim-hero`, steps, enter field (large, letter-spaced), pending rows.
- [x] **Step 2:** Style `cw-ov-link-modal` (overlay, panel, close, focus) matching Overview restraint.
- [x] **Step 3:** Mobile: stack steps; full-width actions; modal fits viewport.
- [x] **Step 4:** Rebuild dist if required (`npm run build` or project equivalent).

---

### Task 3: Overview “Link a code” CTA + modal

**Files:**
- Modify: `includes/dashboard/class-cw-dashboard-contestant.php` (`render_dashboard` Overview welcome actions)

**Interfaces:**
- Consumes: `CW_Claim_Flow::get_link_submission_url()` (or `?tab=link-submission`)
- Produces: button `#cw-ov-link-code-open` + modal with form identical to enter step

- [x] **Step 1:** Add secondary button “Link a code” in `cw-ov-welcome-actions` (keep Explore primary; move “View activities” or keep all three cleanly).
- [x] **Step 2:** Render modal once on Overview: form with nonce `cw_claim_lookup`, input, Continue, link “Open full page”.
- [x] **Step 3:** JS: open/close, Escape, overlay click, focus code input on open.
- [x] **Step 4:** Manual: Overview → open modal → submit valid/invalid code → lands on claim page with existing feedback.

---

### Task 4: Version, deploy, verify

**Files:**
- Modify: `creativewings-core.php` (`CW_VERSION`)

- [x] **Step 1:** Bump version.
- [x] **Step 2:** Deploy changed PHP/CSS (and dist) via Novamira zip/extract pattern.
- [x] **Step 3:** Live QA: polished page, Overview modal, pending row if any, confirm redirect still works.

---

## Out of scope

- Confirm/waiting inside modal
- Claim backend / email changes
- Creator dashboard redesign (inherits page CSS if shared)
