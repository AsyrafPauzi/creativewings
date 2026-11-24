# One Smile QR + Pre-register Email Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Amend the school QR upload flow (email gate + no coupon/verify + anti–double-entry), keep max 15 concurrent Saves with wait-queue, then draft and test the Bentong pre-register email to `acapsum41@gmail.com` only before any blast. Ship Sheree a same-day QR pack.

**Architecture:** Extend `/cw-school-upload/{token}/` to collect email, branch on whether the WP user exists, link pending emails on registration. Keep 15-slot concurrency (others wait). No timed cooldown — block duplicate entry for the same student/code instead. Email work uses cleaned Bentong Excel — draft first, preview only, blast after OK.

**Tech Stack:** WordPress plugin `creativewings-yibon` (`CW_School_Upload`, `CW_Upload_Concurrency` → cooldown), WooCommerce registration hooks, WP mail / existing CW email helpers, Excel cleaned list on Desktop, PowerPoint for Abner (later phase).

## Global Constraints

- Concurrent Saves: **max 15**; others wait in queue until a slot frees.
- **No 2‑minute cooldown.** Prevent **double entry** only (same code / same student already submitted).
- QR path: **no coupon**, **no artwork verification** — eligible by QR.
- Email page: existing platform email → submit now; missing → pending link until CW registration.
- Pre-register Excel: Bentong cleaned file — only valid emails for blast.
- Email preview recipient: **`acapsum41@gmail.com` only** until user says send.
- Sheree QR pack **today**: practical per-school counts from Excel (see Task C2).
- Abner PowerPoint flow slides (phase after amend).

---

## Phase A — Amend QR flow (code)

### Task A1: Keep 15 concurrent + harden anti–double-entry

**Files:**
- Keep: `includes/class-cw-upload-concurrency.php` (max 15, wait/queue UX)
- Modify: `includes/class-cw-school-upload.php` (`handle_save` / AJAX save)
- Modify: client JS — queue wait message (not cooldown)

- [x] Keep max **15** concurrent Saves; others wait and auto-retry (photo kept)
- [x] **No timed cooldown**
- [x] Block double entry: if submission code (or same student+campaign) already has a staged/claimed artwork → clear “already submitted” message, no second Save
- [x] Deploy and smoke-test: 16th waiter queues; duplicate code rejected

### Task A2: Add email field + branch on platform existence

**Files:**
- Modify: `includes/class-cw-school-upload.php` (form UI + save + lookup)
- Possibly: `includes/class-cw-claim-flow.php` / registration hooks for pending link

- [x] Add required **Email** on QR page (before/with name + artwork)
- [x] If email matches existing WP user → show “email exists” / account found → allow snap + text + submit
- [x] If email not on platform → store pending link (email ↔ submission code / staged row) → copy explaining they must register on Creative Wings; after register, auto-link
- [x] Skip coupon / verify gates for this QR token path
- [x] Deploy and test both branches

### Task A3: Wire pending email → account on successful CW registration

**Files:**
- Modify: registration / `user_register` / existing invite-claim hooks
- Possibly: `includes/class-cw-pending-parent-link.php` pattern

- [x] On successful register, if pending QR email matches → attach staged submission / mark linked
- [x] Optional confirmation toast/email “Your Bentong / One Smile artwork is linked”

---

## Phase B — Email draft + blast

### Task B1: Draft One Smile pre-register email (EN + short ZH if needed)

**Content:** Email1 = CW registration success; Email2 = HD infographic + per-child personal QR links (siblings share parent email, each gets own QR).

- [x] Write subject + body (HTML)
- [x] Show draft / preview for review

### Task B2: Send **preview only** to `acapsum41@gmail.com`

- [x] Send preview using live CW mail stack
- [x] Confirm delivery with user before blast

### Task B3: Blast (after explicit OK)

- [x] Load cleaned list: **107** distinct parent emails / **126** students
- [x] Send Email1 + Email2 per family (2 emails each)
- [x] Result: **107/107** both emails OK · **0** failed · **126** QR allocations
- [x] Logs: `wp-content/novamira-sandbox/osow_bentong_blast_log.json` · `osow_bentong_qr_allocations.json`

---

## Phase C — Abner slides + Sheree QR pack

### Task C1: Abner flow slides (real page screenshots)

- [x] Capture live phone screenshots of QR upload flow
- [x] PPTX for Abner: `docs/osow-abner-slides/OSOW-Bentong-Parent-Submit-Flow-Abner.pptx` (7 slides + screenshots)

### Task C2: Sheree QR pack today (One Smile codes by school)

Recommended same-day pack (buffer above form counts, not full 100×all):

| School | Form students | QRs today |
|--------|--------------:|----------:|
| SJKC Ketari | 75 | **100** |
| SJKC Khai Mun Chamang | 35 | **50** |
| SJKC Khai Mun Repas | 14 | **30** |
| SJKC Khai Mun Pagi | 3 | **20** |
| SJKC Perting | 3 | **20** |
| SJKC Karak | 3 | **20** |
| SJKC Bukit Tinggi | 2 | **20** |
| **Total** | | **~260** |

- [x] Generate personal One Smile codes + QR PNGs/PDF sheets per school (seq **30001+**, separate from email blast)
- [x] Zip for Sheree: `docs/OSOW-Sheree-QR-Pack-Bentong-260.zip` (folder/school + CSV + A4 print PDF)
- [ ] Top up to 100/school later if needed
### Task C3: Optional fix invalid emails / re-collect 9 missing emails

---

## Suggested order of work

1. Phase A (amend) → deploy → you test QR page  
2. Phase B1–B2 → you review email at `acapsum41@gmail.com`  
3. You say OK → Phase B3 blast  
4. Phase C (Abner PPT + 300 QRs)

## Out of scope for first amend pass

- Replacing directory/public pagination
- Changing non-QR coupon join for other campaigns
- Auto-fixing the 8 address-in-email rows without new data
