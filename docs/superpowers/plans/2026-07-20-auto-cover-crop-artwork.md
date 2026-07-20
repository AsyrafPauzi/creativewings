# Auto Cover-Crop Artwork Upload — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Accept any PNG for design artwork; center cover-crop to campaign W×H on the server; show a soft client preview so joins are not blocked by exact pixels.

**Architecture:** Pure helper `CW_Design_Artwork_Crop::ensure_size()` (GD, Imagick fallback) rewrites the uploaded PNG when dimensions differ. AJAX upload calls it instead of rejecting. Client drops the exact-size reject, shows a cover-crop preview, uploads the original file. Copy softens to “PNG only — we’ll fit it to the case.”

**Tech Stack:** PHP GD/Imagick, WordPress AJAX upload, vanilla JS + canvas preview, existing design CSS.

## Global Constraints

- PNG only (no JPG/WebP)
- Center cover-crop, no drag/zoom
- Always on for all design-enabled campaigns
- Upload original PNG; server is authoritative for final W×H
- Artwork still required before cart/checkout
- Lean metadata path after attach unchanged

---

### Task 1: Cover-crop helper + unit tests

**Files:**
- Create: `includes/class-cw-design-artwork-crop.php`
- Create: `tests/test-cw-design-artwork-crop.php`
- Modify: `includes/class-cw-loader.php` (require the new class)

**Interfaces:**
- Produces: `CW_Design_Artwork_Crop::ensure_size( string $path, int $target_w, int $target_h ): true|string`
  - `true` = file is (now) exactly target size PNG
  - `string` = error message
  - Exact match = no-op (true)
  - Mismatch = center cover-crop in place, overwrite `$path`

- [ ] **Step 1: Write failing test** — create wide/tall/tiny/exact PNGs; assert exact pass-through and mismatch → exact W×H
- [ ] **Step 2: Run test — expect FAIL** (class missing)
- [ ] **Step 3: Implement helper** (GD preferred, Imagick `cropThumbnailImage` fallback)
- [ ] **Step 4: Run test — expect PASS**
- [ ] **Step 5: Commit**

### Task 2: Wire server upload AJAX

**Files:**
- Modify: `includes/class-cw-design-submission.php` (artwork dimension gate ~796–811)

- [ ] Replace exact-size reject with `CW_Design_Artwork_Crop::ensure_size()`
- [ ] On error string, unlink upload and `wp_send_json_error`
- [ ] Keep PNG ext/MIME and 25 MB checks
- [ ] Commit

### Task 3: Client preview + soft reject

**Files:**
- Modify: `assets/js/cw-design-preview.js`
- Modify: `assets/css/cw-style-design.css`
- Modify: `includes/class-cw-design-submission.php` (localize messages)

- [ ] Remove exact-dimension reject; after PNG decode, show cover-crop preview, then upload original
- [ ] Add `.cw-design-fit-preview` canvas/img under artwork feedback
- [ ] Replace `wrongDimensions` message with `fitting` hint: “Fitting to case size…”
- [ ] Commit

### Task 4: Soften participant copy + version bump

**Files:**
- Modify: `includes/class-cw-design-submission.php` (multi banner + single intro)
- Modify: `includes/business/class-cw-business-form.php` (optional metabox note if exact-size help text exists)
- Modify: `creativewings-core.php` → `11.0.90`

- [ ] Multi: “PNG only — we’ll fit each design to the case size.”
- [ ] Single: “Upload a PNG — we’ll fit it to the case (%1$d × %2$d).”
- [ ] Bump version
- [ ] Run `php tests/test-cw-design-artwork-crop.php` — PASS
- [ ] Commit
