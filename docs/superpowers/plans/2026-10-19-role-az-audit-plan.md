# Role A–Z Functional + UI/UX Audit Plan

**Date:** 2026-10-19  
**Status:** Approved · executed 2026-10-19 via Novamira — see `docs/superpowers/checklists/2026-10-19-role-az-findings.md`  
**Scope:** Participant (contestant), Creator, Business — **exclude Administrator**  
**Site:** creativewings.asia (live) / local staging if provided  
**Constraint:** Do not break production. Prefer fix-forward over redesign.

---

## 0. How the attached design skills apply (resolved conflict)

The five skills disagree on some defaults (e.g. Inter ban vs allow, cinematic GSAP heroes vs restraint). For **this existing Creative Wings WordPress product**, skill priority is:

| Priority | Skill | Role in this audit |
|----------|--------|---------------------|
| 1 | **creative-web-design** | Preserve CW brand, art direction, existing system; intentional > trendy |
| 2 | **unslop-ui** | Kill decorative slop (icon boxes, emoji chrome, glass goo, nested cards) |
| 3 | **no-ai-slop** | Audit **copy** in dashboards/CTAs (filler, “elevate”, throat-clearing) |
| 4 | **design-taste-frontend-v1** | Hierarchy, contrast, loading/empty/error states, spacing math |
| 5 | **gpt-taste** | Use **only** as a severity lens for broken hierarchy/contrast — **not** as a mandate to rebuild dashboards into Awwwards landing pages |

**Hard rule:** No full-page visual reinvention of the dashboard/checkout in this audit cycle. Changes must be surgical (CSS/copy/a11y/bugs) that keep CW tokens (`--cwd-*`, Creative Wings blue `#125B9A`, existing card shells).

---

## 1. Roles & surfaces (code map)

| Role | WP role | Dashboard tabs | Critical public flows |
|------|---------|----------------|------------------------|
| **Participant** | `contestant` | Overview, Explore, Link submission, Activities, Points, Leaderboard, Badges, Upgrade, Settings | Register, campaign detail, join/checkout, guest coupon B1, claim code, points redeem |
| **Creator** | `creator_role` | Dashboard, Explore, My Activities, Link submission, Portfolio, Badges, Profile | Join campaigns, portfolio public `/profile/{login}/`, profile completeness gate |
| **Business** | `business_role` | Overview, Campaigns, Manage entries, Reports, Wallet, Badges, Company Profile | Campaign wizard create/edit, entries/judging, wallet withdrawal, template downloads |

Shared public (all roles may hit while logged out or as guest): homepage, campaign detail, directory, checkout, login/register.

---

## 2. UI audit checklist (applied per screen)

For each screen, score **P0 / P1 / P2** only — no redesign wishlist.

**Usability / a11y**
- [ ] Primary action obvious in &lt;2s
- [ ] Labels above inputs; errors inline
- [ ] Touch targets ≥44px on mobile
- [ ] Focus visible; Escape closes modals
- [ ] Contrast AA on text/CTAs

**Anti-slop (unslop-ui)**
- [ ] No emoji as chrome
- [ ] No icon-in-rounded-square decoration without meaning
- [ ] No glassmorphism / glow CTA shadows
- [ ] No card-in-card noise
- [ ] No purple-gradient AI aesthetic

**Brand / creative-web-design**
- [ ] Still reads as Creative Wings if nav removed (where branded)
- [ ] Typography/spacing consistent with sibling CW pages
- [ ] Real content fits (long campaign titles, prize lists)

**States (design-taste)**
- [ ] Empty, loading, error states exist and are readable

---

## 3. Functional A–Z matrices

### 3A — Participant (contestant)

| ID | Flow | Pass criteria |
|----|------|----------------|
| P01 | Register (email, password, name, DOB, PDPA) | Account created, contestant role, lands dashboard |
| P02 | Login / logout | Session works; redirect not broken |
| P03 | Dashboard Overview | Loads; key stats/links work |
| P04 | Explore Opportunities | Lists campaigns; open detail |
| P05 | Campaign detail | Hero, about prose, prizes, resources, Join CTA |
| P06 | Join paid campaign | Cart → checkout → pay → entry created |
| P07 | **B1** Guest school coupon | Guest apply → modal register/login → coupon applies |
| P08 | Guest full-pay (no school coupon) | Still works without modal |
| P09 | Link submission code | Claim/link form accepts valid code |
| P10 | My Activities | Shows joins/orders; open order/entry |
| P11 | Points | Balance visible; earn/redeem rules match live config |
| P12 | Leaderboard | Renders; no PHP notices |
| P13 | Badges | Progress grid loads |
| P14 | Upgrade Account | CTA/path clear (no dead end) |
| P15 | Settings | Save profile fields without blank redirect |
| P16 | Mobile sidebar | Toggle open/close; nav usable |

### 3B — Creator

| ID | Flow | Pass criteria |
|----|------|----------------|
| C01 | Login as creator | Creator portal label/menu |
| C02 | Overview | Loads |
| C03 | Explore + join | Can join like participant where allowed |
| C04 | Link submission | Same as P09 |
| C05 | My Activities | Shows engagements |
| C06 | Portfolio CRUD | Add/edit/delete portfolio items |
| C07 | Public portfolio | `/profile/{login}/` when profile complete |
| C08 | Incomplete profile gate | Missing avatar/tagline/address blocks public correctly |
| C09 | Profile Settings | Save basics |
| C10 | Badges | Loads |
| C11 | Mobile nav | Same as P16 |

### 3C — Business (non-admin)

| ID | Flow | Pass criteria |
|----|------|----------------|
| B01 | Login as business | Business portal |
| B02 | Overview | KPIs load |
| B03 | Campaigns list | Own campaigns only (not other orgs) |
| B04 | Create campaign wizard | Steps 1–N save without blank `admin-post.php` |
| B05 | Edit campaign | Ownership preserved (`post_author` + `organizer_id`) |
| B06 | Multi templates | Upload 2+; Resources card shows all |
| B07 | Design submission config | Variants + print area save |
| B08 | Manage entries | List, casing label, evaluate modal, score save |
| B09 | Reports | Filters/export if available |
| B10 | Wallet | Balance + withdrawal UX (no P0) |
| B11 | Company Profile | Save biz info |
| B12 | Badges | Loads |
| B13 | Mobile nav | Usable |

### 3D — Cross-cutting regression

| ID | Check |
|----|--------|
| X01 | Logged-in school coupon: no B1 modal |
| X02 | Non-CW coupon as guest: no B1 modal |
| X03 | Checkout left-align titles/labels |
| X04 | Campaign prose lists (Judging Criteria) consistent size |
| X05 | No PHP fatals in `debug.log` during suite |

---

## 4. Execution method (after approval)

**Phase A — Setup (blocked until credentials)**  
Need one of:
1. Three test accounts (participant / creator / business) + passwords on staging or production, **or**
2. Permission to create throwaway users via Novamira MCP / WP admin.

**Phase B — Functional pass**  
Browser walkthrough per matrix; screenshot failures; log P0/P1/P2.

**Phase C — UI audit pass**  
Same screens against §2 checklist; attach findings table (screen → issue → skill rule → proposed surgical fix).

**Phase D — Fix batch (only after you approve the findings list)**  
Implement P0 then P1 only. No speculative redesign. Re-test touched flows.

**Phase E — Sign-off**  
Update checklist with pass/fail; optional commit of fixes.

---

## 5. Out of scope (this cycle)

- Administrator / WP Admin tooling (you excluded)
- Full visual redesign of dashboard shell
- GSAP cinematic rebuild of campaign pages
- B2–B4 growth features (points bonuses, invite) beyond verifying existing points UI
- Changing brand palette/fonts site-wide

---

## 6. Deliverables

1. This plan (approved)  
2. Findings doc: `docs/superpowers/checklists/2026-10-19-role-az-findings.md`  
3. Optional fix PR/commit after findings approval  

---

## 7. Approval gate

Reply with:

**A)** Approve plan + provide **test accounts** (or “create via Novamira”)  
**B)** Approve plan + **staging URL** only first  
**C)** Changes to scope before we run tests  

No functional or UI code changes until you pick A/B/C.
