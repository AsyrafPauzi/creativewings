/**
 * Campaign public submissions world-map gallery.
 * Pins are server-rendered; this enhances KPI fill + pin entrance.
 * Expects window.cwdMapGallery = { pins, kpi, i18n }.
 */
(function () {
    'use strict';

    function normalizePoint(point) {
        if (!Array.isArray(point) || point.length < 2) {
            return null;
        }
        var x = Number(point[0]);
        var y = Number(point[1]);
        if (!Number.isFinite(x) || !Number.isFinite(y)) {
            return null;
        }
        x = Math.max(0, Math.min(100, x));
        y = Math.max(0, Math.min(100, y));
        return [Math.round(x * 100) / 100, Math.round(y * 100) / 100];
    }

    // Pure helper is exposed for the tiny no-DOM regression test.
    window.CWMapGalleryTest = {
        normalizePoint: normalizePoint
    };

    var cfg = window.cwdMapGallery || null;
    var root = document.getElementById('cwd-map-gallery');
    if (!root) {
        return;
    }

    var fillEl = root.querySelector('[data-cwd-map-fill]');
    var moreEl = root.querySelector('[data-cwd-map-more]');
    var canvas = root.querySelector('[data-cwd-map-canvas]');

    function drawPoints() {
        if (!canvas || !cfg || !Array.isArray(cfg.points)) {
            return;
        }

        var rect = canvas.getBoundingClientRect();
        if (!rect.width || !rect.height) {
            return;
        }

        var dpr = Math.max(1, Math.min(2, Number(window.devicePixelRatio) || 1));
        var pixelWidth = Math.round(rect.width * dpr);
        var pixelHeight = Math.round(rect.height * dpr);
        if (canvas.width !== pixelWidth || canvas.height !== pixelHeight) {
            canvas.width = pixelWidth;
            canvas.height = pixelHeight;
        }

        var ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, rect.width, rect.height);
        ctx.fillStyle = 'rgba(240, 90, 126, 0.58)';

        var radius = cfg.points.length > 5000 ? 1.05 : (cfg.points.length > 1500 ? 1.25 : 1.55);
        ctx.beginPath();
        cfg.points.forEach(function (rawPoint) {
            var point = normalizePoint(rawPoint);
            if (!point) {
                return;
            }
            var x = (point[0] / 100) * rect.width;
            var y = (point[1] / 100) * rect.height;
            ctx.moveTo(x + radius, y);
            ctx.arc(x, y, radius, 0, Math.PI * 2);
        });
        ctx.fill();
    }

    var resizeFrame = null;
    function scheduleDraw() {
        if (resizeFrame !== null) {
            return;
        }
        resizeFrame = window.requestAnimationFrame(function () {
            resizeFrame = null;
            drawPoints();
        });
    }

    drawPoints();
    window.addEventListener('resize', scheduleDraw, { passive: true });

    if (fillEl && cfg && cfg.kpi && cfg.kpi.enabled) {
        var pct = Math.max(0, Math.min(100, Number(cfg.kpi.fillPercent) || 0));
        fillEl.style.width = pct + '%';
        fillEl.setAttribute('aria-valuenow', String(pct));
        root.classList.add('cwd-map-has-kpi');
        if (pct >= 100) {
            root.classList.add('cwd-map-kpi-done');
        }
    }

    if (moreEl && cfg && cfg.moreCount > 0) {
        moreEl.hidden = false;
    }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    var pins = root.querySelectorAll('.cwd-map-pin');
    pins.forEach(function (el, i) {
        el.style.animationDelay = Math.min(i * 18, 600) + 'ms';
        el.classList.add('cwd-map-pin-enter');
    });
})();
