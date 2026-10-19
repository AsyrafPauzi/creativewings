# Link submission polish + Overview quick-link

**Date:** 2026-10-19  
**Status:** Approved — 2026-10-19  
**Parent:** Contestant dashboard shell / Overview revamp (`2026-10-19-contestant-overview-shell-revamp`)  
**Approach:** **A** (user chose option 3 = polish page + Overview shortcut; recommendation locked)

---

## 1. Goal

Make **Link your submission code** feel finished (match Overview quality) and let contestants enter a code from Overview in one click — without turning the multi-step claim flow into a popup-only experience.

---

## 2. Locked decisions

| # | Topic | Decision |
|---|--------|----------|
| 1 | Scope | **Both** — polish full page **and** Overview quick enter |
| 2 | Architecture | **A** — Polished page is source of truth; Overview opens enter-code modal only |
| 3 | Confirm / waiting / pending | Stay on full `?tab=link-submission` page (existing redirects) |
| 4 | Modal submit | Same POST as page form (`cw_claim_lookup` → admin-post) → server redirects as today |
| 5 | Nav / emails / WC endpoint | Unchanged URLs (`tab=link-submission`, claim redirect map) |
| 6 | Full claim-in-popup | **Out of scope** |
| 7 | Design language | Contestant Overview tokens (`cw-ov-*` / existing CW CSS) — not Tailwind/shadcn |

---

## 3. User flows

### Full page (nav or deep link)

```
Contestant → Link submission code tab
  → polished enter UI + optional pending list
  → Continue → existing claim lookup → confirm / waiting / errors (unchanged)
```

### Overview shortcut

```
Overview → “Link a code” (secondary CTA)
  → modal: code field + Continue + link “Open full page”
  → Continue POSTs claim lookup
  → browser follows redirect to full claim page (confirm/waiting/feedback)
```

---

## 4. UI

### Page (`cw-claim-page`)

- Header aligned with Overview welcome (title + one short subtitle; long copy moved into 3 short how-it-works steps).
- Primary surface: large submission-code input + Continue (hero action, not a tiny settings card).
- Pending codes (if any): status rows — Ready vs Waiting — with existing Continue / Check again / Cancel actions.
- Confirm + waiting steps: same structure, restyled to match (no logic change).

### Overview modal

- Trigger: secondary button beside Explore in `cw-ov-welcome-actions` (“Link a code”).
- Content: title, one-line hint, code input, Continue, text link to full page.
- A11y: focus trap / Escape / overlay click close; restore focus to trigger.
- No confirm/waiting UI inside the modal.

---

## 5. Technical touchpoints

| Piece | Change |
|-------|--------|
| `class-cw-claim-flow.php` | Markup for enter/pending/confirm/waiting shells (`cw-claim-*` / light `cw-ov-*` composition) |
| `cw-style-contestant.css` (+ Vite dist if required) | Page + modal styles |
| `class-cw-dashboard-contestant.php` | Overview CTA + modal markup/JS (or shared small JS) |
| Claim handlers / redirects | **No behavior change** |
| Version | Bump `CW_VERSION` on ship |

Reuse existing claim form fields, nonces, and SweetAlert feedback on the full page.

---

## 6. Explicit non-goals

- Rewriting claim backend, staged submission, or email templates
- Modal for confirm / waiting / checkout
- Changing school code format or validation rules
- Creator/business dashboards (contestant shell first; creator tab can inherit page CSS)

---

## 7. Success criteria

- Link submission page no longer reads as a bare form + wall of text.
- Overview → enter code → Continue lands on the same post-lookup path as the full page.
- Pending / confirm / waiting / email deep links still work.
- Mobile: modal and page usable without horizontal scroll.

---

## Spec self-review

- No placeholders left intentional.
- No contradiction with claim redirects (modal does not own post-lookup steps).
- Scope limited to contestant Overview + claim enter UI polish.
