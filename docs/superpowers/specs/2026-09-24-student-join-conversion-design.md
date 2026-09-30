# Student Join Conversion Design

**Date:** 2026-09-24  
**Updated:** 2026-09-24 (hardened via team Q&A)  
**Status:** Approved — decisions locked  
**Audience priority:** Students first  
**Problem type:** **B — discover but don’t register / join**  
**Team timeline:** `docs/superpowers/specs/2026-09-24-q4-creative-wings-timeline.md`  
**Phase B plan (show team):** `docs/superpowers/plans/2026-09-24-phase-b-student-growth.md`

---

## 1. Goal

Increase the rate of visitors who **finish joining** a competition or activity, then make it natural for them to **invite friends to register** on Creative Wings.

**Primary metric:** Campaign join conversion = (completed joins) ÷ (campaign page views)  
**Secondary:** Invite shares, invite-link → new register rate, 2nd-campaign join within 90 days  

**Completed join** = successful checkout / campaign registration via **cash, coupon, or school claim**.

---

## 2. Must-ship pillars (build order)

| Order | Pillar | Job |
|-------|--------|-----|
| 1 | Guest coupon → register popup | Members-only coupons without killing checkout |
| 2 | Points / rewards | Motive + honest preview |
| 3 | Campaign proof | Trust at Join CTA |
| 4 | **★ Platform invite** | Bring more students to register |

No separate full “friction audit” — popup + clear Join CTA is the MVP friction fix.

---

## 3. Points rules (locked)

| Event | Recipient | Points | Timing notes |
|-------|-----------|--------|--------------|
| First **register** (new account) | New student | **+50** | Available to spend at **first** checkout |
| First **campaign join** completes | That same student | **+50** | Awarded **after** join — not during that first checkout |
| Friend **registers via invite link** | **Student who shared** | **+50** | Each successful new register; **no cap** MVP |
| Cash paid on join | Joiner | RM1 = 1 pt (existing) | Show ~X in preview; **0** if 100% coupon (say so) |

- Bonuses (**+50** register / first-join / invite) **also apply** when the join paid **RM0**.  
- No separate “invitee +25” — new user only gets normal first-register **+50**.

---

## 4. Guest coupon → register popup

**Rule:** Guests cannot apply member/school coupons until registered and logged in.

**Flow:**

1. Guest at checkout enters a coupon.  
2. Popup: **Register first** to use this coupon.  
3. Fields: **email + password + name + DOB**.  
4. On success: create account → **auto-login** → re-apply coupon → continue checkout.  
5. **Email verification is NOT required** to unlock the coupon (verify→points is a later optional feature).

**Guest without coupon:** Still allowed to join and pay full price.

---

## 5. Points preview (join moment)

On campaign / join UI before pay (**option A**):

- “This join earns ~X points” (cash portion; honest **0** if fully couponed).  
- If eligible for first-campaign bonus: “You’ll get **+50** after you finish your first join” (so they don’t expect it mid-checkout).

After successful join: celebrate screen (points earned, badge progress, **Invite** CTA).

---

## 6. Campaign proof

**Where:** Campaign detail page only, next to main **Join** CTA.

**What:**

- **N joined** — **always show** (even if 1–2).  
- **N** = count of **successful checkouts / completed joins** (not cart starts, not design-submit-only).  
- **How it works** — 3 short steps under/near CTA (e.g. Register → Join & pay → You’re in).

**Out of MVP:** Entry gallery strip; homepage card counts.

---

## 7. Platform invite ★

**What the link is:** Invite to **register on Creative Wings** (site-wide sign-up), **not** a campaign deep-link.

**Who can send:** **Members only.**

**Where shown:** Contestant **dashboard** + **My Account** + **after successful campaign join**.

**Reward:** When a **new** account is created via `?cw_ref={code}` (or equivalent), the **sharer** receives **+50** immediately on that register (friend does not need to join a campaign for sharer to be paid).

**Anti-abuse (MVP):**

- Block self-invite (same user / same email).  
- One invite attribution per new account.  
- Admin can claw back points if abuse.  
- **No** max invite count for MVP.

**Later:** Class codes, campaign-scoped invites, school team boards.

---

## 8. Phase B schedule fit

| Window | Ships |
|--------|--------|
| 20 Oct – 2 Nov | Coupon → register popup |
| 3–16 Nov | Points/rewards + campaign proof |
| 17 Nov – 7 Dec | ★ Platform invite (+ Verify→points if ready) |
| 8–28 Dec | Optional: E-Trophy → Podcast → E-Talk |

**Optional order if capacity:** Verify→points → 10 E-Trophy → Podcast → E-Talk MVP.

---

## 9. Success criteria

- Join conversion ↑ vs 2-week baseline on pilot campaign(s).  
- ≥20% of successful joiners tap Invite at least once.  
- Invite→register rate tracked; target after 2 weeks live.  
- No P0 checkout / points regressions.

---

## 10. Decision log (Q&A)

| # | Topic | Locked |
|---|--------|--------|
| 1 | What counts as join | Cash **or** coupon **or** school claim |
| 2 | Bonuses on RM0 | Yes |
| 3–7 | Invite | Members send; link = platform register; sharer +50 on friend register; no cap |
| 8 | Register + first join | +50 then +50 after first join; first +50 spendable at checkout |
| 9–11 | Guest coupon | Popup register (email, password, name, DOB); no verify for coupon; guest full-pay OK |
| 12 | Invite surfaces | Dashboard + My Account + after join |
| 13 | N joined | Always show; N = successful checkout |
| 14 | Points preview | Cash ~X + first-join note |
| 15 | Proof | N + How it works at campaign Join CTA only |
| 16 | Build order | Coupon popup → rewards → proof → invite |
| 17 | Extra friction audit | No |
| 18 | Optional order | Verify→points → E-Trophy → Podcast → E-Talk |
| 19 | Anti-abuse | No self-invite; one sharer; admin clawback |
