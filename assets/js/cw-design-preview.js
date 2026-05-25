/* global cwDesignVars */
/**
 * Design Submission front-end logic.
 *
 *   1. Campaign product page — validates a participant's PNG against the
 *      organizer-defined dimensions BEFORE uploading, then AJAX-uploads to
 *      cw_design_artwork_upload. Mirrors validation server-side.
 *   2. Checkout page — composites the participant's uploaded PNG onto each
 *      organizer-uploaded variant image on a <canvas>, with live preview as
 *      the participant clicks between swatches.
 *
 * Vanilla — no jQuery dependency (the wizard already pulls jQuery globally
 * for unrelated repeaters; we deliberately don't rely on it here so the
 * checkout preview keeps working even if jQuery loading is deferred).
 */
(function () {
    'use strict';

    if (typeof cwDesignVars === 'undefined') {
        return;
    }

    // ─────────────────────────────────────────────────────────────────
    // CAMPAIGN PAGE — upload + dimension validation
    // ─────────────────────────────────────────────────────────────────

    function initUploads() {
        var container = document.querySelector('.cw-design-upload');
        if (!container) return;

        var productId = parseInt(container.dataset.productId, 10);
        var requiredW = parseInt(container.dataset.width, 10);
        var requiredH = parseInt(container.dataset.height, 10);

        container.querySelectorAll('input.cw-design-file').forEach(function (input) {
            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (!file) return;
                var role = input.dataset.role || 'artwork';
                var feedback = container.querySelector('.cw-design-feedback[data-role="' + role + '"]');
                var hidden   = container.querySelector('input.cw-design-aid[data-role="' + role + '"]');

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
                        uploadFile(input, role, productId, file, hidden, feedback);
                    }).catch(function () {
                        showError(feedback, cwDesignVars.messages.wrongExtension);
                        input.value = '';
                    });
                    return;
                }

                // Source file: extension whitelist; no dimension check.
                if (!/\.(ai|pdf|svg|eps)$/i.test(file.name)) {
                    showError(feedback, 'Source file must be .ai, .pdf, .svg, or .eps');
                    input.value = '';
                    return;
                }
                uploadFile(input, role, productId, file, hidden, feedback);
            });
        });
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

    function uploadFile(input, role, productId, file, hidden, feedback) {
        var fd = new FormData();
        fd.append('action', cwDesignVars.action);
        fd.append('security', cwDesignVars.nonce);
        fd.append('product_id', productId);
        fd.append('role', role);
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
        var raw = root.getAttribute('data-config');
        if (!raw) return;
        var cfg;
        try { cfg = JSON.parse(raw); } catch (e) { return; }

        var canvas = root.querySelector('.cw-design-picker__canvas');
        var loading = root.querySelector('.cw-design-picker__loading');
        var hidden  = root.querySelector('.cw-design-picker__field');
        if (!canvas || !cfg) return;

        var ctx = canvas.getContext('2d');
        var current = cfg.default || (cfg.variants[0] && cfg.variants[0].slug) || '';
        var artwork = null;
        var variantImages = {};
        var pendingLoads  = 0;

        // Load artwork.
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

        // Load every variant image so swatch clicks repaint instantly.
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

        // Swatch click handling.
        root.querySelectorAll('.cw-design-swatch').forEach(function (sw) {
            sw.addEventListener('click', function () {
                var slug = sw.getAttribute('data-variant');
                if (!slug || slug === current) return;
                current = slug;
                root.querySelectorAll('.cw-design-swatch').forEach(function (other) {
                    other.classList.toggle('is-selected', other === sw);
                    other.setAttribute('aria-checked', other === sw ? 'true' : 'false');
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
                // While the variant image is still loading, paint a soft
                // placeholder so the canvas isn't a stark white block.
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
            // No `img.crossOrigin = 'anonymous'` here on purpose. WordPress doesn't
            // emit CORS headers on /wp-content/uploads, so requesting in CORS mode
            // makes browsers (Safari especially) refuse the load even for same-
            // origin variant images. We only `drawImage` onto the canvas — we
            // never `getImageData()` or `toDataURL()` it — so a tainted canvas
            // is fine and not requesting CORS at all is the right call.
            var img = new Image();
            img.onload = function () { resolve(img); };
            img.onerror = function () { reject(new Error('load:' + url)); };
            img.src = url;
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // BOOT
    // ─────────────────────────────────────────────────────────────────

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    function boot() {
        initUploads();
        initCheckoutPickers();
    }
})();
