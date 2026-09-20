# Campaign Sponsor Tiers + Sponsors Page

**Date:** 2026-09-20  
**Status:** Draft for review  
**Campaign focus:** One Smile, One World (product ID `3361`)  
**Deck alignment:** Branding & Marketing Packages (Champion / Hero / Community Partner / Local Brand / In-kind)

## Goal

Give paid brand sponsors visible presence on Creative Wings that matches the sponsorship deck:

1. Tiered display on the **campaign page**
2. A public **`/sponsors/`** page (explicit Local Brand benefit: “Logo listed on creativewings.asia sponsor page”)
3. Keep **school sponsors** (coupon codes) separate from **brand sponsors**

## Decision

**Per-campaign tiers** (not a site-wide CRM in v1).

Extend each Supporting Partner row with a `tier` field. Existing partners without a tier default to `local`.

## Data model

Stored in existing meta `cw_supporting_partners` (array of rows):

| Field | Type | Notes |
|--------|------|--------|
| `name` | string | Required |
| `attachment_id` | int | Logo attachment |
| `url` | string | Optional website |
| `tier` | string | `champion` \| `hero` \| `community` \| `local` \| `inkind` |

Sanitize in `CW_Campaign_Showcase::sanitize_partners()`. Wizard UI: tier select per partner row.

### Tier labels (public)

| Key | Public heading |
|-----|----------------|
| `champion` | Presented by / Champion |
| `hero` | Hero Sponsors |
| `community` | Community Partners |
| `local` | Local Brands |
| `inkind` | In-kind & Supporting Partners |

## Campaign page UI (`[cw_event_detail]`)

For campaigns that have partners:

1. **Champion strip** — below hero title / above main body: eyebrow “Presented by” + large logo(s), linked if URL set. Hidden if no champions.
2. Replace flat “Supporting Partners” grid with **tiered sections** (Champion omitted from the lower grid if already in strip, or shown again only if preferred — **v1: strip only for Champion, grid starts at Hero**).
3. Mentors section unchanged.

Visual hierarchy: Champion ≫ Hero > Community ≈ Local > In-kind.

## `/sponsors/` page

- WordPress page `Sponsors`, slug `sponsors`, published.
- Content: shortcode `[cw_sponsors campaign_id="3361"]` (attr optional; if empty, use featured/open campaign with partners, else `3361` for One Smile launch).
- Layout: page intro (short) + same tier sections as campaign page (Champion first).
- Footer menu (id 52): add **Sponsors** after About/Contact.
- Optional About page link later — out of scope unless trivial.

## Shortcode

`[cw_sponsors campaign_id="" heading="Our Sponsors"]`

- Loads partners for that campaign, groups by tier, renders markup + CSS.
- Empty tiers omitted.
- Reuses logo + link patterns from homepage marquee / campaign partners.

## Admin / organizer UX

Campaign wizard Supporting Partners rows gain:

- Select: Champion / Hero / Community Partner / Local Brand / In-kind

No change to school sponsor UI.

## Out of scope v1

- Site-wide sponsor catalog / CRM
- Checkout or payment for packages
- Homepage marquee auto-weighting by tier (can come later)
- Certificate / email watermark branding
- Editing Elementor About page copy

## Acceptance

1. Organizer can set tier on a partner and save.
2. One Smile campaign shows Champion “Presented by” when a champion exists.
3. Partners appear under correct tier headings on campaign page.
4. `/sponsors/` lists the same tiers for campaign `3361`.
5. Footer links to Sponsors.
6. Existing partners without tier still show (as Local Brand).
7. School sponsors / coupons unaffected.

## Risks

- Flat partner grids elsewhere (homepage marquee) ignore tier until a follow-up — acceptable.
- Elementor cache may need purge after page/menu publish.
