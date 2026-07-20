/* global cwDesignVars */
/**
 * Design Submission front-end logic.
 *
 *   1. Campaign product page (and the [cw_event_detail] modal) — accepts any
 *      PNG, shows a center cover-crop preview into campaign W×H, then
 *      AJAX-uploads the original to cw_design_artwork_upload (server crops).
 *      Supports one upload widget per participant row via `data-slot`.
 *   2. Checkout page — composites the participant's uploaded PNG onto each
 *      organizer-uploaded variant image on a <canvas>, with live preview as
 *      the participant clicks between swatches. Multi-design lines render one
 *      picker per participant slot.
 *
 * Vanilla — no jQuery dependency.
 */
(function () {
    'use strict';

    if (typeof cwDesignVars === 'undefined') {
        return;
    }

    // ─────────────────────────────────────────────────────────────────
    // CAMPAIGN PAGE — upload + dimension validation
    // ─────────────────────────────────────────────────────────────────

    function initAllUploadContainers() {
        document.querySelectorAll('.cw-design-upload').forEach(initUploadContainer);
    }

    /**
     * Bind the file-input change handlers on a single uploader container.
     * Idempotent — flagged with `data-cw-design-bound` to avoid double-binding
     * when the same container gets reprocessed (e.g. after row removal).
     * Skips template containers (slot === "{SLOT}").
     */
    function initUploadContainer(container) {
        if (!container) return;
        if (container.getAttribute('data-cw-design-bound') === '1') return;
        var slotAttr = container.getAttribute('data-slot') || '0';
        if (slotAttr === '{SLOT}') return; // unfilled JS template

        container.setAttribute('data-cw-design-bound', '1');

        var productId = parseInt(container.dataset.productId, 10);
        var requiredW = parseInt(container.dataset.width, 10);
        var requiredH = parseInt(container.dataset.height, 10);

        container.querySelectorAll('input.cw-design-file').forEach(function (input) {
            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (!file) return;
                var role = input.dataset.role || 'artwork';
                var feedback = container.querySelector('.cw-design-feedback[data-role="' + role + '"]');
                var hidden = container.querySelector('input.cw-design-aid[data-role="' + role + '"]');
                var slot = parseInt(container.getAttribute('data-slot') || '0', 10);

                clearFeedback(feedback);

                if (role === 'artwork') {
                    if (!/\.png$/i.test(file.name) || (file.type && file.type !== 'image/png')) {
                        clearFitPreview(container);
                        showError(feedback, cwDesignVars.messages.wrongExtension);
                        input.value = '';
                        return;
                    }
                    measurePng(file).then(function (dims) {
                        showFitPreview(container, file, dims, requiredW, requiredH);
                        uploadFile(input, role, productId, slot, file, hidden, feedback);
                    }).catch(function () {
                        clearFitPreview(container);
                        showError(feedback, cwDesignVars.messages.wrongExtension);
                        input.value = '';
                    });
                    return;
                }

                if (!/\.(ai|pdf|svg|eps)$/i.test(file.name)) {
                    showError(feedback, 'Source file must be .ai, .pdf, .svg, or .eps');
                    input.value = '';
                    return;
                }
                uploadFile(input, role, productId, slot, file, hidden, feedback);
            });
        });

        // Wire the "use participant 1's artwork" prefill button (multi-design,
        // rows 2+). Copies the slot-1 hidden id into this slot, mirrors the
        // feedback message, and lets the user override later by picking their
        // own PNG.
        var prefillBtn = container.querySelector('.cw-design-prefill-btn');
        if (prefillBtn) {
            prefillBtn.addEventListener('click', function () {
                var fromSlot = parseInt(prefillBtn.getAttribute('data-fill-from-slot') || '1', 10);
                var source = document.querySelector('.cw-design-upload[data-slot="' + fromSlot + '"]');
                if (!source) {
                    showError(container.querySelector('.cw-design-feedback[data-role="artwork"]'),
                        cwDesignVars.messages.prefillMissing || 'No artwork uploaded yet for participant ' + fromSlot + '.');
                    return;
                }
                var srcHidden = source.querySelector('input.cw-design-aid[data-role="artwork"]');
                var srcFb     = source.querySelector('.cw-design-feedback[data-role="artwork"]');
                if (!srcHidden || !srcHidden.value) {
                    showError(container.querySelector('.cw-design-feedback[data-role="artwork"]'),
                        cwDesignVars.messages.prefillMissing || 'No artwork uploaded yet for participant ' + fromSlot + '.');
                    return;
                }
                var dstHidden = container.querySelector('input.cw-design-aid[data-role="artwork"]');
                var dstFb     = container.querySelector('.cw-design-feedback[data-role="artwork"]');
                dstHidden.value = srcHidden.value;
                setFeedback(dstFb,
                    (cwDesignVars.messages.prefilled || 'Using participant 1\'s artwork') +
                    (srcFb && srcFb.textContent ? ' (' + srcFb.textContent.replace(/^.*?—\s*/, '') + ')' : ''),
                    'is-ok');
            });
        }
    }

    function measurePng(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                var w = img.naturalWidth;
                var h = img.naturalHeight;
                URL.revokeObjectURL(url);
                resolve({ w: w, h: h });
            };
            img.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error('decode'));
            };
            img.src = url;
        });
    }

    /**
     * Show a small center cover-crop preview framed to campaign W×H aspect.
     * Preview only — the original File is still uploaded; server crops.
     */
    function showFitPreview(container, file, dims, requiredW, requiredH) {
        if (!container || !file || !dims || !requiredW || !requiredH) return;

        var wrap = container.querySelector('.cw-design-fit-preview');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'cw-design-fit-preview';
            wrap.setAttribute('aria-hidden', 'true');
            var feedback = container.querySelector('.cw-design-feedback[data-role="artwork"]');
            if (feedback && feedback.parentNode) {
                feedback.parentNode.insertBefore(wrap, feedback.nextSibling);
            } else {
                container.appendChild(wrap);
            }
        }

        var canvas = wrap.querySelector('canvas');
        if (!canvas) {
            canvas = document.createElement('canvas');
            wrap.appendChild(canvas);
        }

        var hint = wrap.querySelector('.cw-design-fit-preview__hint');
        if (!hint) {
            hint = document.createElement('p');
            hint.className = 'cw-design-fit-preview__hint';
            wrap.appendChild(hint);
        }

        var exact = dims.w === requiredW && dims.h === requiredH;
        hint.textContent = exact
            ? (cwDesignVars.messages.exactFit || 'Exact case size — ready to upload.')
            : (cwDesignVars.messages.fitting || 'PNG only — we’ll fit it to the case.');

        // Display canvas capped for UI; draw cover-crop of source into target aspect.
        var maxDisplayH = 160;
        var displayScale = Math.min(1, maxDisplayH / requiredH);
        var displayW = Math.max(1, Math.round(requiredW * displayScale));
        var displayH = Math.max(1, Math.round(requiredH * displayScale));
        canvas.width = displayW;
        canvas.height = displayH;

        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function () {
            var ctx = canvas.getContext('2d');
            if (!ctx) {
                URL.revokeObjectURL(url);
                return;
            }
            ctx.clearRect(0, 0, displayW, displayH);

            var dstAspect = requiredW / requiredH;
            var srcAspect = img.naturalWidth / img.naturalHeight;
            var sx, sy, sw, sh;
            if (srcAspect > dstAspect) {
                sh = img.naturalHeight;
                sw = Math.max(1, Math.round(img.naturalHeight * dstAspect));
                sx = Math.max(0, Math.floor((img.naturalWidth - sw) / 2));
                sy = 0;
            } else {
                sw = img.naturalWidth;
                sh = Math.max(1, Math.round(img.naturalWidth / dstAspect));
                sx = 0;
                sy = Math.max(0, Math.floor((img.naturalHeight - sh) / 2));
            }
            ctx.drawImage(img, sx, sy, sw, sh, 0, 0, displayW, displayH);
            URL.revokeObjectURL(url);
        };
        img.onerror = function () {
            URL.revokeObjectURL(url);
            clearFitPreview(container);
        };
        img.src = url;
    }

    function clearFitPreview(container) {
        if (!container) return;
        var wrap = container.querySelector('.cw-design-fit-preview');
        if (wrap && wrap.parentNode) {
            wrap.parentNode.removeChild(wrap);
        }
    }

    function uploadFile(input, role, productId, slot, file, hidden, feedback) {
        var fd = new FormData();
        fd.append('action', cwDesignVars.action);
        fd.append('security', cwDesignVars.nonce);
        fd.append('product_id', productId);
        fd.append('role', role);
        fd.append('slot', slot);
        fd.append('file_data', file);

        setFeedback(feedback, cwDesignVars.messages.uploading, 'is-pending');
        input.disabled = true;

        fetch(cwDesignVars.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                input.disabled = false;
                if (res && res.success && res.data && res.data.attach_id) {
                    if (hidden) hidden.value = res.data.attach_id;
                    var label = (role === 'source')
                        ? cwDesignVars.messages.sourceUploaded
                        : cwDesignVars.messages.uploaded;
                    setFeedback(feedback, label + ' — ' + file.name, 'is-ok');
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : cwDesignVars.messages.genericError;
                    showError(feedback, msg);
                    input.value = '';
                    if (hidden) hidden.value = '';
                    if (role === 'artwork' && input.closest) {
                        clearFitPreview(input.closest('.cw-design-upload'));
                    }
                }
            })
            .catch(function () {
                input.disabled = false;
                showError(feedback, cwDesignVars.messages.genericError);
                input.value = '';
                if (hidden) hidden.value = '';
                if (role === 'artwork' && input.closest) {
                    clearFitPreview(input.closest('.cw-design-upload'));
                }
            });
    }

    function clearFeedback(el) {
        if (!el) return;
        el.classList.remove('is-ok', 'is-error', 'is-pending');
        el.textContent = '';
    }
    function showError(el, msg) {
        if (!el) return;
        el.classList.remove('is-ok', 'is-pending');
        el.classList.add('is-error');
        el.textContent = msg;
    }
    function setFeedback(el, msg, cls) {
        if (!el) return;
        el.classList.remove('is-ok', 'is-error', 'is-pending');
        if (cls) el.classList.add(cls);
        el.textContent = msg;
    }

    // ─────────────────────────────────────────────────────────────────
    // CHECKOUT — variant swatches + canvas mockup compositing
    // ─────────────────────────────────────────────────────────────────

    function initCheckoutPickers() {
        document.querySelectorAll('.cw-design-picker').forEach(initOnePicker);
    }

    function initOnePicker(root) {
        if (root.getAttribute('data-cw-picker-bound') === '1') return;
        root.setAttribute('data-cw-picker-bound', '1');

        var raw = root.getAttribute('data-config');
        if (!raw) {
            return;
        }
        var cfg;
        try {
            cfg = JSON.parse(raw);
        } catch (e) {
            return;
        }

        var canvas = root.querySelector('.cw-design-picker__canvas');
        var loading = root.querySelector('.cw-design-picker__loading');
        var hidden  = root.querySelector('.cw-design-picker__field');
        if (!canvas || !cfg) return;

        var ctx = canvas.getContext('2d');
        var current = cfg.default || (cfg.variants[0] && cfg.variants[0].slug) || '';
        var artwork = null;
        var variantImages = {};
        var pendingLoads  = 0;

        root.setAttribute('data-current-variant', current);

        if (cfg.artwork) {
            pendingLoads++;
            loadImage(cfg.artwork).then(function (img) {
                artwork = img;
                pendingLoads--;
                redraw();
            }).catch(function () {
                pendingLoads--;
                redraw();
            });
        }

        cfg.variants.forEach(function (v) {
            if (!v.url) return;
            pendingLoads++;
            loadImage(v.url).then(function (img) {
                variantImages[v.slug] = img;
                pendingLoads--;
                redraw();
            }).catch(function () {
                pendingLoads--;
                redraw();
            });
        });

        root.querySelectorAll('.cw-design-swatch').forEach(function (sw) {
            sw.addEventListener('click', function (e) {
                var btn = e.currentTarget;
                var slug = btn.getAttribute('data-variant');
                if (!slug || slug === current) return;
                current = slug;
                root.setAttribute('data-current-variant', current);
                root.querySelectorAll('.cw-design-swatch').forEach(function (other) {
                    var isMe = (other === btn);
                    other.classList.toggle('is-selected', isMe);
                    other.setAttribute('aria-checked', isMe ? 'true' : 'false');
                });
                if (hidden) hidden.value = slug;
                redraw();
            });
        });

        function redraw() {
            // Compositing order is critical: the casing variant PNG is a
            // FRAME (rounded caps + outline) with a TRANSPARENT centre.
            // The artwork must be drawn first so it fills the canvas, then
            // the casing on top so its transparent centre reveals the
            // artwork while its outline / caps appear *around* the design.
            //
            // Sizing rule: canvas buffer = the SELECTED casing's natural
            // dimensions. That way the casing always renders 1:1 at native
            // resolution and the print-area rectangle (configured in the
            // same coord space) maps directly onto the canvas without any
            // scale conversion.
            if (loading && pendingLoads <= 0) loading.style.display = 'none';
            var base = variantImages[current];
            var dims = resizeCanvasToVariant(canvas, base, canvas.width, canvas.height);
            var cW = dims[0], cH = dims[1];
            ctx.clearRect(0, 0, cW, cH);

            // 1. Light placeholder background so transparent corners look
            //    intentional rather than like a broken image.
            ctx.fillStyle = '#f1f5f9';
            ctx.fillRect(0, 0, cW, cH);

            // 2. Artwork: cover-crop into the configured print-area window
            //    (so the 0.9 cm wrap-around portion is invisible from the
            //    front), or contain-fit when no print area is configured
            //    (legacy campaigns).
            if (artwork) {
                drawArtwork(ctx, artwork, cW, cH, cfg.printArea);
            }

            // 3. Variant casing on top — transparent centre lets the
            //    artwork show through, while the bottle outline + caps
            //    render around the design.
            if (base) {
                ctx.drawImage(base, 0, 0, cW, cH);
            }
        }
    }

    function loadImage(url) {
        return new Promise(function (resolve, reject) {
            var img = new Image();
            img.onload = function () { resolve(img); };
            img.onerror = function () { reject(new Error('load:' + url)); };
            img.src = url;
        });
    }

    /**
     * Draw an image into a canvas context using `object-fit: contain`
     * semantics — preserve the image's aspect ratio, scale it to fit
     * inside the target box, and center it on both axes. Used to drop a
     * participant's artwork into the casing's design area without
     * stretching: if the artwork is shorter than the casing (e.g.
     * 425×2362 inside a 425×2598 canvas) it lands with equal padding
     * top + bottom, mirroring the reference brief.
     */
    function drawContain(ctx, img, boxW, boxH) {
        var iw = (img && (img.naturalWidth || img.width)) | 0;
        var ih = (img && (img.naturalHeight || img.height)) | 0;
        if (!iw || !ih) return;
        var scale = Math.min(boxW / iw, boxH / ih);
        var w = iw * scale;
        var h = ih * scale;
        var x = (boxW - w) / 2;
        var y = (boxH - h) / 2;
        ctx.drawImage(img, x, y, w, h);
    }

    /**
     * Draw `img` into the rectangle (px, py, pw, ph) using `object-fit: cover`
     * semantics: scale up to fully cover the rect (preserving aspect), then
     * center-crop the overflow. Drawing is clipped to the rect so anything
     * outside the print area is discarded — which is exactly how the physical
     * sleeve works: customer uploads 3.6 cm wide, only 2.7 cm shows on the
     * case front, the rest wraps around and is invisible.
     */
    function drawCover(ctx, img, px, py, pw, ph) {
        var iw = (img && (img.naturalWidth || img.width)) | 0;
        var ih = (img && (img.naturalHeight || img.height)) | 0;
        if (!iw || !ih || pw <= 0 || ph <= 0) return;

        var scale = Math.max(pw / iw, ph / ih);
        var w = iw * scale;
        var h = ih * scale;
        var x = px + (pw - w) / 2;
        var y = py + (ph - h) / 2;

        ctx.save();
        ctx.beginPath();
        ctx.rect(px, py, pw, ph);
        ctx.clip();
        ctx.drawImage(img, x, y, w, h);
        ctx.restore();
    }

    /**
     * Compositor "draw the artwork" helper. Picks between cover-crop (when
     * the campaign has a print-area window configured — the casing's
     * visible front face) and contain-fit (legacy behaviour, no crop).
     *
     * `printArea` may be either an object {x, y, w, h} or null/undefined.
     */
    function drawArtwork(ctx, img, canvasW, canvasH, printArea) {
        if (!img) return;
        if (printArea && printArea.w > 0 && printArea.h > 0) {
            drawCover(
                ctx, img,
                +printArea.x || 0,
                +printArea.y || 0,
                +printArea.w,
                +printArea.h
            );
        } else {
            drawContain(ctx, img, canvasW, canvasH);
        }
    }

    /**
     * Resize a canvas's pixel buffer to the casing variant's natural
     * dimensions so the displayed mockup matches the real product
     * proportions (and the downloaded PNG comes out at the casing's
     * native resolution). No-op when the variant isn't loaded yet.
     * Returns the [w, h] used so the caller can keep working with it.
     */
    function resizeCanvasToVariant(canvas, variantImg, fallbackW, fallbackH) {
        var cW = (variantImg && (variantImg.naturalWidth  || variantImg.width))  | 0;
        var cH = (variantImg && (variantImg.naturalHeight || variantImg.height)) | 0;
        if (!cW || !cH) {
            cW = fallbackW || canvas.width  || 1;
            cH = fallbackH || canvas.height || 1;
        }
        if (canvas.width  !== cW) canvas.width  = cW;
        if (canvas.height !== cH) canvas.height = cH;
        return [cW, cH];
    }

    // ─────────────────────────────────────────────────────────────────
    // ORDER-RECEIVED / VIEW-ORDER — locked mockup + PNG download
    // ─────────────────────────────────────────────────────────────────

    function initOrderMockups() {
        document.querySelectorAll('.cw-design-mockup').forEach(initOneOrderMockup);
    }

    function initOneOrderMockup(root) {
        if (root.getAttribute('data-cw-mockup-bound') === '1') return;
        root.setAttribute('data-cw-mockup-bound', '1');

        var raw = root.getAttribute('data-config');
        if (!raw) return;
        var cfg;
        try { cfg = JSON.parse(raw); } catch (e) { return; }

        var canvas  = root.querySelector('.cw-design-mockup__canvas');
        var loading = root.querySelector('.cw-design-mockup__loading');
        var btn     = root.querySelector('.cw-design-mockup__download-btn');
        if (!canvas) return;

        var ctx = canvas.getContext('2d');
        var ready = { art: null, variant: null, pending: 0, errors: 0 };

        // Disable the button until at least one of (artwork OR variant) renders;
        // the export only works after we've drawn something onto the canvas.
        if (btn) {
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
        }

        function tryRender() {
            // See checkout `redraw()` for the full rationale on stacking
            // order + sizing. Canvas buffer is resized to the casing's
            // natural dimensions on every render so the downloaded PNG
            // comes out at native resolution and the configured print
            // area (same coord space) maps 1:1 onto the buffer.
            if (loading && ready.pending <= 0) loading.style.display = 'none';
            var dims = resizeCanvasToVariant(canvas, ready.variant, canvas.width, canvas.height);
            var cW = dims[0], cH = dims[1];
            ctx.clearRect(0, 0, cW, cH);
            ctx.fillStyle = '#f1f5f9';
            ctx.fillRect(0, 0, cW, cH);
            if (ready.art) {
                drawArtwork(ctx, ready.art, cW, cH, cfg.printArea);
            }
            if (ready.variant) {
                ctx.drawImage(ready.variant, 0, 0, cW, cH);
            }
            if (ready.pending <= 0 && btn) {
                btn.disabled = false;
                btn.removeAttribute('aria-busy');
            }
        }

        if (cfg.artwork) {
            ready.pending++;
            loadImage(cfg.artwork).then(function (img) {
                ready.art = img; ready.pending--; tryRender();
            }).catch(function () { ready.errors++; ready.pending--; tryRender(); });
        }
        if (cfg.variantUrl) {
            ready.pending++;
            loadImage(cfg.variantUrl).then(function (img) {
                ready.variant = img; ready.pending--; tryRender();
            }).catch(function () { ready.errors++; ready.pending--; tryRender(); });
        }

        // Wire the download button. Uses canvas.toBlob → object URL → click a
        // hidden <a download> so the user gets a real file save, not a base64
        // tab. Falls back to toDataURL on browsers without toBlob (legacy).
        if (btn) {
            btn.addEventListener('click', function () {
                if (btn.disabled) return;
                var filename = cfg.filename || ('mockup-' + Date.now() + '.png');

                function triggerDownload(href, revoke) {
                    var a = document.createElement('a');
                    a.href = href;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    if (revoke) setTimeout(function () { URL.revokeObjectURL(href); }, 1000);
                }

                if (canvas.toBlob) {
                    try {
                        canvas.toBlob(function (blob) {
                            if (!blob) return;
                            triggerDownload(URL.createObjectURL(blob), true);
                        }, 'image/png');
                    } catch (e) {
                        try { triggerDownload(canvas.toDataURL('image/png'), false); } catch (e2) { /* tainted */ }
                    }
                } else {
                    try { triggerDownload(canvas.toDataURL('image/png'), false); } catch (e) { /* tainted */ }
                }
            });
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // BUSINESS DASHBOARD — entry mockup previews + zoom lightbox
    //
    // Judges browse the "Manage Entries" page; each entry card and the
    // evaluation modal render a composite (artwork on its chosen casing
    // variant) inside a `.cw-design-entry-mockup` wrapper. Clicking any
    // such wrapper — or a plain `<img.cw-entry-plain-img>` on non-design
    // campaigns — opens a single shared lightbox at near-full viewport
    // size so the judge can scrutinise detail before scoring.
    //
    // The compositing uses the same toBlob/toDataURL pipeline as the
    // order-received mockup; the lightbox just re-uses its own canvas
    // (larger pixel buffer) so we never copy a tainted bitmap.
    // ─────────────────────────────────────────────────────────────────

    function initEntryMockups(root) {
        var scope = root || document;
        scope.querySelectorAll('.cw-design-entry-mockup').forEach(initOneEntryMockup);
    }

    function initOneEntryMockup(el) {
        if (el.getAttribute('data-cw-entry-bound') === '1') return;
        el.setAttribute('data-cw-entry-bound', '1');

        var raw = el.getAttribute('data-config');
        if (!raw) return;
        var cfg;
        try { cfg = JSON.parse(raw); } catch (e) { return; }
        if (!cfg || (!cfg.artwork && !cfg.variantUrl)) return;

        var canvas  = el.querySelector('.cw-design-entry-mockup__canvas');
        var loading = el.querySelector('.cw-design-entry-mockup__loading');
        if (!canvas) return;

        var ctx = canvas.getContext('2d');
        var state = { art: null, variant: null, pending: 0 };

        function render() {
            // Stacking + sizing same as the checkout picker — casing
            // natural dimensions drive the canvas, artwork is cropped to
            // the configured print area (front face) when set, otherwise
            // contain-fit so legacy entries still render.
            var dims = resizeCanvasToVariant(canvas, state.variant, canvas.width, canvas.height);
            var cW = dims[0], cH = dims[1];
            ctx.clearRect(0, 0, cW, cH);
            ctx.fillStyle = '#f1f5f9';
            ctx.fillRect(0, 0, cW, cH);
            if (state.art) {
                drawArtwork(ctx, state.art, cW, cH, cfg.printArea);
            }
            if (state.variant) {
                ctx.drawImage(state.variant, 0, 0, cW, cH);
            }
            if (state.pending <= 0 && loading) loading.style.display = 'none';
        }

        if (cfg.variantUrl) {
            state.pending++;
            loadImage(cfg.variantUrl).then(function (img) {
                state.variant = img; state.pending--; render();
            }).catch(function () { state.pending--; render(); });
        }
        if (cfg.artwork) {
            state.pending++;
            loadImage(cfg.artwork).then(function (img) {
                state.art = img; state.pending--; render();
            }).catch(function () { state.pending--; render(); });
        }
        if (state.pending === 0) render();

        // Click → open lightbox with the same config. Use both click + keyboard
        // for accessibility (the element is role=button, tabindex=0).
        function openZoom(e) {
            e.preventDefault();
            e.stopPropagation();
            openEntryLightbox(cfg);
        }
        el.addEventListener('click', openZoom);
        el.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') openZoom(ev);
        });
    }

    function initPlainEntryImages(root) {
        var scope = root || document;
        scope.querySelectorAll('img.cw-entry-plain-img').forEach(function (img) {
            if (img.getAttribute('data-cw-entry-bound') === '1') return;
            img.setAttribute('data-cw-entry-bound', '1');
            img.style.cursor = 'zoom-in';
            img.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openEntryLightbox({
                    artwork: img.getAttribute('data-full') || img.src,
                    variantUrl: '',
                    variantName: '',
                    title: img.getAttribute('data-title') || ''
                });
            });
        });
    }

    // Shared lightbox — initialised lazily on first open so we don't pay for
    // its DOM listeners on pages that never need it.
    var entryLightboxBound = false;

    function openEntryLightbox(cfg) {
        var modal = document.getElementById('cw-entry-mockup-lightbox');
        if (!modal) return;

        // The dashboard renders the lightbox markup deep inside the My
        // Account content wrapper. Many themes (and Woo's own wrappers)
        // apply `transform`, `filter`, `contain`, or `will-change` to
        // those ancestors, which creates a containing block for *any*
        // `position: fixed` descendant — making our lightbox render
        // inline at the page-flow position instead of covering the
        // viewport (visible bug: the close button & loading text appear
        // tucked under the entry grid). Reparenting the modal directly
        // under <body> escapes those ancestors permanently.
        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }

        var canvas  = modal.querySelector('#cw-entry-mockup-lightbox-canvas');
        var imgEl   = modal.querySelector('#cw-entry-mockup-lightbox-img');
        var caption = modal.querySelector('#cw-entry-mockup-lightbox-caption');
        var dl      = modal.querySelector('#cw-entry-mockup-lightbox-download');
        var loading = modal.querySelector('.cw-entry-mockup-lightbox__loading');

        // Bind close handlers once.
        if (!entryLightboxBound) {
            entryLightboxBound = true;
            modal.querySelector('.cw-entry-mockup-lightbox__close').addEventListener('click', closeEntryLightbox);
            modal.addEventListener('click', function (ev) {
                if (ev.target === modal) closeEntryLightbox();
            });
            document.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeEntryLightbox();
                }
            });
        }

        // Build a fresh caption — variant name + filename when we have them.
        var bits = [];
        if (cfg.title)        bits.push('<strong>' + escapeHtml(cfg.title) + '</strong>');
        if (cfg.variantName)  bits.push('<i class="fas fa-palette"></i> ' + escapeHtml(cfg.variantName));
        if (cfg.artFilename)  bits.push('<span class="cw-entry-mockup-lightbox__file"><i class="fas fa-file-image"></i> ' + escapeHtml(cfg.artFilename) + '</span>');
        caption.innerHTML = bits.join(' &middot; ');

        // Download button → original PNG (canvas is tainted-safe here too,
        // but the judge usually wants the raw participant artwork).
        if (cfg.artwork) {
            dl.href = cfg.artwork;
            dl.style.display = '';
        } else {
            dl.style.display = 'none';
        }

        // Decide which renderer to use:
        //   - design submission (artwork + variant)         → canvas composite
        //   - plain image (no variant)                       → <img>
        if (cfg.variantUrl) {
            imgEl.style.display = 'none';
            canvas.style.display = '';
            // Use a buffer big enough to keep print-grade detail crisp.
            var W = Math.max(1, parseInt(cfg.width, 10)  || 2400);
            var H = Math.max(1, parseInt(cfg.height, 10) || 600);
            canvas.width  = W;
            canvas.height = H;
            var ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, W, H);
            loading.style.display = '';

            var ready = { art: null, variant: null, pending: 0 };
            function paint() {
                // Stacking + sizing matches the inline mockups so the
                // zoom view is pixel-identical to what was on the card,
                // just bigger. Buffer = casing native dims; artwork is
                // cropped to the print-area window (or contain-fit when
                // the campaign doesn't define one).
                var dims = resizeCanvasToVariant(canvas, ready.variant, W, H);
                var cW = dims[0], cH = dims[1];
                ctx.clearRect(0, 0, cW, cH);
                ctx.fillStyle = '#f1f5f9';
                ctx.fillRect(0, 0, cW, cH);
                if (ready.art) {
                    drawArtwork(ctx, ready.art, cW, cH, cfg.printArea);
                }
                if (ready.variant) {
                    ctx.drawImage(ready.variant, 0, 0, cW, cH);
                }
                if (ready.pending <= 0) loading.style.display = 'none';
            }
            ready.pending++;
            loadImage(cfg.variantUrl).then(function (img) {
                ready.variant = img; ready.pending--; paint();
            }).catch(function () { ready.pending--; paint(); });
            if (cfg.artwork) {
                ready.pending++;
                loadImage(cfg.artwork).then(function (img) {
                    ready.art = img; ready.pending--; paint();
                }).catch(function () { ready.pending--; paint(); });
            }
        } else {
            canvas.style.display = 'none';
            imgEl.style.display = '';
            loading.style.display = '';
            imgEl.onload = function () { loading.style.display = 'none'; };
            imgEl.onerror = function () { loading.style.display = 'none'; };
            imgEl.src = cfg.artwork || '';
        }

        modal.classList.add('is-open');
        // Force inline display in case the CSS file is slow to load — the
        // markup ships with `style="display:none"` so the class alone isn't
        // enough without our !important rule applying.
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeEntryLightbox() {
        var modal = document.getElementById('cw-entry-mockup-lightbox');
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // PUBLIC API — used by the registration modal to (re-)initialise
    // dynamically injected upload widgets after rows are added/removed,
    // and by the eval modal to (re-)initialise mockups it injects on
    // demand.
    // ─────────────────────────────────────────────────────────────────

    window.CwDesign = window.CwDesign || {};
    window.CwDesign.initContainer        = initUploadContainer;
    window.CwDesign.initAll              = initAllUploadContainers;
    window.CwDesign.initEntryMockups     = initEntryMockups;
    window.CwDesign.initPlainEntryImages = initPlainEntryImages;
    window.CwDesign.openLightbox         = openEntryLightbox;

    // ─────────────────────────────────────────────────────────────────
    // BOOT
    // ─────────────────────────────────────────────────────────────────

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    function boot() {
        initAllUploadContainers();
        initCheckoutPickers();
        initOrderMockups();
        initEntryMockups();
        initPlainEntryImages();
    }
})();
