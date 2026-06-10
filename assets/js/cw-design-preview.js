/* global cwDesignVars */
/**
 * Design Submission front-end logic.
 *
 *   1. Campaign product page (and the [cw_event_detail] modal) — validates a
 *      participant's PNG against the organizer-defined dimensions BEFORE
 *      uploading, then AJAX-uploads to cw_design_artwork_upload. Mirrors
 *      validation server-side. Supports one upload widget per participant row
 *      via a `data-slot` attribute on the container.
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
                        showError(feedback, cwDesignVars.messages.wrongExtension);
                        input.value = '';
                        return;
                    }
                    measurePng(file).then(function (dims) {
                        if (dims.w !== requiredW || dims.h !== requiredH) {
                            var msg = cwDesignVars.messages.wrongDimensions
                                .replace('%d', requiredW)
                                .replace('%d', requiredH);
                            showError(feedback, msg + ' (' + dims.w + ' × ' + dims.h + ' uploaded).');
                            input.value = '';
                            return;
                        }
                        uploadFile(input, role, productId, slot, file, hidden, feedback);
                    }).catch(function () {
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
                }
            })
            .catch(function () {
                input.disabled = false;
                showError(feedback, cwDesignVars.messages.genericError);
                input.value = '';
                if (hidden) hidden.value = '';
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
            if (loading && pendingLoads <= 0) loading.style.display = 'none';
            var base = variantImages[current];
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            if (base) {
                ctx.drawImage(base, 0, 0, canvas.width, canvas.height);
            } else {
                ctx.fillStyle = '#f1f5f9';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
            }
            if (artwork) {
                ctx.drawImage(artwork, 0, 0, canvas.width, canvas.height);
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
            if (loading && ready.pending <= 0) loading.style.display = 'none';
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            if (ready.variant) {
                ctx.drawImage(ready.variant, 0, 0, canvas.width, canvas.height);
            } else {
                ctx.fillStyle = '#f1f5f9';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
            }
            if (ready.art) {
                ctx.drawImage(ready.art, 0, 0, canvas.width, canvas.height);
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
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            if (state.variant) {
                ctx.drawImage(state.variant, 0, 0, canvas.width, canvas.height);
            } else {
                ctx.fillStyle = '#f1f5f9';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
            }
            if (state.art) {
                ctx.drawImage(state.art, 0, 0, canvas.width, canvas.height);
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
                ctx.clearRect(0, 0, W, H);
                if (ready.variant) ctx.drawImage(ready.variant, 0, 0, W, H);
                if (ready.art)     ctx.drawImage(ready.art,     0, 0, W, H);
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
