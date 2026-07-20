# Auto Cover-Crop Artwork Upload — Design

**Date:** 2026-07-20  
**Status:** Approved — pending implementation  
**Scope:** All design-enabled campaigns (always on; no organizer toggle)

## Problem

Visitors reach design campaign pages but often abandon before joining. A major
friction point is the current artwork rule: **PNG only at exact campaign
pixels** (e.g. 425×2362). The client and server both reject wrong sizes with
no resize/crop helper. Multi-design copy (“Use the upload field inside each
participant row… PNG only, exactly W×H”) reinforces the barrier. Templates
exist in the sidebar Resources card but are disconnected from the upload step.

## Goal

Maximize joins while still producing a **print-ready PNG at exact campaign
W×H**. Accept any PNG (any dimensions), center cover-crop it into the print
frame, and store that exact-size file on the cart/entry as today.

## Decisions

| Topic | Choice |
|-------|--------|
| Formats | **PNG only** (no JPG/WebP) |
| Fit mode | **Center cover-crop** (fill frame, crop overflow; no drag/zoom) |
| Scope | **Always on** for every design-enabled campaign |
| Architecture | **Hybrid**: client preview + server authoritative crop |
| Upload timing | Unchanged — artwork still required before cart/checkout |

## Non-goals

- Accepting JPG / HEIC / WebP for artwork
- Manual pan/zoom crop UI
- Deferring artwork upload until after payment
- Changing optional vector source upload (AI/PDF/SVG/EPS)
- Changing claim/school flow (staged artwork still bypasses product-form upload)
- New organizer toggle to restore “exact size required”

## Participant flow

1. Join CTA → registration modal (auth/guest gate unchanged).
2. Choose a PNG of any pixel size (phone-exported PNGs OK).
3. Instant preview: image shown as **center cover-cropped** into the campaign
   print aspect / frame, with soft copy:
   *“PNG only — we’ll fit it to the case.”*
4. If the file is already exactly W×H, preview as-is (no crop messaging needed).
5. Upload proceeds; server produces the final print PNG at campaign W×H and
   returns the attachment ID into the form / cart item data as today.
6. Multi-participant rows: same behavior per row.
7. Checkout mockup / variant picker continues to use the stored print PNG.

Replace rejection-oriented copy such as *“PNG only, exactly 425 × 2362
pixels per design”* with the soft fit message above (single- and multi-design
intros).

## Technical design

### Client — `assets/js/cw-design-preview.js`

- Keep `accept="image/png"` and reject non-PNG before upload.
- **Remove** the hard reject when measured pixels ≠ `data-width` /
  `data-height`.
- On file select: decode PNG, draw a center cover-crop preview into a small
  canvas/img framed to campaign W×H aspect (preview only — do not replace
  the File used for upload).
- Always upload the **original** PNG; the server is the only place that
  writes the final print-size file.
- Surface clear errors only for: not PNG, decode failure, empty file.

### Server — `CW_Design_Submission` artwork AJAX

After `wp_handle_upload` for role `artwork`:

1. Still require `.png` extension + `image/png` MIME; enforce `MAX_UPLOAD_BYTES`.
2. `getimagesize()` must succeed with positive width/height.
3. Compare to campaign config `cw_design_artwork_w` × `cw_design_artwork_h`:
   - **Exact match:** keep uploaded file; attach as today.
   - **Mismatch:** center cover-crop with GD (preferred if available) or
     Imagick:
     - Scale so the image **covers** the target rectangle.
     - Crop the centered W×H window.
     - Write a new PNG over (or beside) the upload path used for the
       attachment.
   - Fail only if the image library cannot process the file.
4. Insert attachment + lean metadata via `CW_Image_Optimizer` as today
   (thumbnails only; do not run full WP size regeneration that OOMs).
5. Response shape unchanged: attachment ID + URL for the form hidden fields.

Cover-crop algorithm (integer pixels):

```text
scale = max(targetW / srcW, targetH / srcH)
scaledW = round(srcW * scale)
scaledH = round(srcH * scale)
srcX = floor((scaledW - targetW) / 2)  // in scaled space → map back to src
srcY = floor((scaledH - targetH) / 2)
// Draw scaled image, then clip/copy the centered targetW×targetH region
```

Prefer a single `imagecopyresampled` (or Imagick equivalent) from source
rectangle to a new truecolor PNG of size targetW×targetH, then
`imagepng` with reasonable compression. Preserve alpha when practical
(`imagesavealpha` / Imagick alpha).

### Copy / UI surfaces

Update participant-facing strings in design upload markup (shortcodes /
`CW_Design_Submission` product form helpers) for:

- Single-design intro
- Multi-design banner (“participant row” message)
- Client validation error strings that currently cite exact dimensions

Organizer metabox help text may note that participant uploads are
auto-fitted to the configured size (so organizers still set W×H for print).

### Cart / checkout / entries

No change to cart keys, checkout variant mockup, or entry meta. Downstream
code already assumes the artwork attachment is campaign W×H.

## Error handling

| Case | Behaviour |
|------|-----------|
| Not PNG | Reject (client + server) |
| Unreadable / corrupt PNG | Reject with clear message |
| File > 25 MB | Reject (existing) |
| Wrong dimensions | **Auto cover-crop** (no reject) |
| Exact dimensions | Pass through |
| GD and Imagick missing | Reject with admin-facing-safe message asking to retry / contact support; log once |

## Testing

- Exact W×H PNG → stored unchanged (same pixels).
- Wider PNG → center crop; output exactly W×H; no letterboxing.
- Taller PNG → same.
- Tiny PNG → upscaled cover-crop to W×H.
- Non-PNG → rejected.
- Multi-slot upload → each row independently cropped.
- Checkout canvas mockup still composites correctly after crop.
- Concurrent upload memory: lean metadata path still used after crop.

## Success criteria

- Participants can join with any PNG without knowing campaign pixels.
- Every stored artwork attachment for design campaigns is exactly campaign W×H.
- Join funnel no longer shows “must be exactly W×H” rejection as the default path.
