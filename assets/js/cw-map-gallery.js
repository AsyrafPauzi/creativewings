/**
 * Campaign public submissions — Malaysia smiley mosaic map with pan/zoom.
 * Expects window.cwdMapGallery = { slots, filled, filledCount, totalSlots, kpi, i18n }.
 */
(function () {
    'use strict';

    var MIN_SCALE = 1;
    var MAX_SCALE = 3;
    var ZOOM_STEP = 1.25;

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

    function clampScale(scale) {
        return Math.max(MIN_SCALE, Math.min(MAX_SCALE, scale));
    }

    function clampPan(tx, ty, scale, stageWidth, stageHeight) {
        if (scale <= 1) {
            return { tx: 0, ty: 0 };
        }
        var maxX = stageWidth * (scale - 1) / 2;
        var maxY = stageHeight * (scale - 1) / 2;
        return {
            tx: Math.max(-maxX, Math.min(maxX, tx)),
            ty: Math.max(-maxY, Math.min(maxY, ty))
        };
    }

    function screenToMapCoords(px, py, stageWidth, stageHeight, tx, ty, scale) {
        var cx = stageWidth / 2;
        var cy = stageHeight / 2;
        return {
            x: (px - cx - tx) / scale + cx,
            y: (py - cy - ty) / scale + cy
        };
    }

    function zoomAt(scale, tx, ty, stageWidth, stageHeight, pointerX, pointerY, newScale) {
        var cx = stageWidth / 2;
        var cy = stageHeight / 2;
        var mapX = (pointerX - cx - tx) / scale + cx;
        var mapY = (pointerY - cy - ty) / scale + cy;
        var nextTx = pointerX - cx - (mapX - cx) * newScale;
        var nextTy = pointerY - cy - (mapY - cy) * newScale;
        return clampPan(nextTx, nextTy, newScale, stageWidth, stageHeight);
    }

    function smileyRadius(stageWidth, viewBoxWidth, gridStep, radiusRatio) {
        var vb = Math.max(1, Number(viewBoxWidth) || 1000);
        var step = Math.max(1, Number(gridStep) || 6.2);
        var ratio = Math.max(0.2, Math.min(0.5, Number(radiusRatio) || 0.4));
        return (stageWidth / vb) * step * ratio;
    }

    function drawSmileyFace(ctx, x, y, r, filled) {
        ctx.save();
        ctx.beginPath();
        ctx.arc(x + r * 0.08, y + r * 0.12, r, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(15, 23, 42, 0.08)';
        ctx.fill();
        ctx.restore();

        if (filled) {
            var grad = ctx.createLinearGradient(x - r, y - r, x + r, y + r);
            grad.addColorStop(0, '#f05a7e');
            grad.addColorStop(0.35, '#fbbf24');
            grad.addColorStop(0.65, '#34d399');
            grad.addColorStop(1, '#60a5fa');
            ctx.beginPath();
            ctx.arc(x, y, r, 0, Math.PI * 2);
            ctx.fillStyle = grad;
            ctx.fill();
            ctx.strokeStyle = 'rgba(255,255,255,0.9)';
            ctx.lineWidth = Math.max(0.45, r * 0.12);
            ctx.stroke();
            ctx.fillStyle = '#1e293b';
        } else {
            var faceGrad = ctx.createRadialGradient(
                x - r * 0.25,
                y - r * 0.35,
                r * 0.1,
                x,
                y,
                r
            );
            faceGrad.addColorStop(0, '#ffffff');
            faceGrad.addColorStop(0.55, '#f8fafc');
            faceGrad.addColorStop(1, '#e2e8f0');
            ctx.beginPath();
            ctx.arc(x, y, r, 0, Math.PI * 2);
            ctx.fillStyle = faceGrad;
            ctx.fill();
            ctx.strokeStyle = '#cbd5e1';
            ctx.lineWidth = Math.max(0.45, r * 0.1);
            ctx.stroke();
            ctx.fillStyle = '#94a3b8';
        }

        var eyeR = Math.max(0.45, r * 0.1);
        ctx.beginPath();
        ctx.arc(x - r * 0.32, y - r * 0.14, eyeR, 0, Math.PI * 2);
        ctx.arc(x + r * 0.32, y - r * 0.14, eyeR, 0, Math.PI * 2);
        ctx.fill();

        ctx.beginPath();
        ctx.strokeStyle = filled ? '#1e293b' : '#94a3b8';
        ctx.lineWidth = Math.max(0.4, r * 0.1);
        ctx.lineCap = 'round';
        ctx.arc(x, y + r * 0.1, r * 0.4, 0.2 * Math.PI, 0.8 * Math.PI);
        ctx.stroke();
    }

    function findFilledSlotAt(slots, filledLookup, stageWidth, stageHeight, mapX, mapY, radius) {
        var hitR = radius * 1.35;
        var hitR2 = hitR * hitR;
        var best = -1;
        var bestDist = hitR2;

        for (var i = 0; i < slots.length; i++) {
            if (!filledLookup[i]) {
                continue;
            }
            var point = normalizePoint(slots[i]);
            if (!point) {
                continue;
            }
            var x = (point[0] / 100) * stageWidth;
            var y = (point[1] / 100) * stageHeight;
            var dx = mapX - x;
            var dy = mapY - y;
            var dist = dx * dx + dy * dy;
            if (dist <= hitR2 && dist < bestDist) {
                bestDist = dist;
                best = i;
            }
        }

        return best;
    }

    function buildFilledLookup(filledSlots) {
        var lookup = Object.create(null);
        if (!Array.isArray(filledSlots)) {
            return lookup;
        }
        filledSlots.forEach(function (item) {
            if (!item || !Number.isFinite(Number(item.slot))) {
                return;
            }
            lookup[Number(item.slot)] = item;
        });
        return lookup;
    }

    window.CWMapGalleryTest = {
        normalizePoint: normalizePoint,
        smileyRadius: smileyRadius,
        findFilledSlotAt: findFilledSlotAt,
        buildFilledLookup: buildFilledLookup,
        screenToMapCoords: screenToMapCoords,
        clampPan: clampPan,
        clampScale: clampScale,
        zoomAt: zoomAt
    };

    var cfg = window.cwdMapGallery || null;
    var root = document.getElementById('cwd-map-gallery');
    if (!root) {
        return;
    }

    var fillEl = root.querySelector('[data-cwd-map-fill]');
    var stage = root.querySelector('[data-cwd-map-stage]');
    var viewport = root.querySelector('[data-cwd-map-viewport]');
    var canvas = root.querySelector('[data-cwd-map-canvas]');
    var zoomInBtn = root.querySelector('[data-cwd-map-zoom-in]');
    var zoomOutBtn = root.querySelector('[data-cwd-map-zoom-out]');
    var zoomResetBtn = root.querySelector('[data-cwd-map-zoom-reset]');
    var slots = cfg && Array.isArray(cfg.slots) ? cfg.slots : [];
    var filledCount = cfg ? Math.max(0, Number(cfg.filledCount) || 0) : 0;
    var filledLookup = buildFilledLookup(cfg && cfg.filledSlots ? cfg.filledSlots : []);
    var viewBoxW = cfg && cfg.viewBox ? Number(cfg.viewBox[0]) || 1000 : 1000;
    var gridStep = cfg ? Number(cfg.gridStep) || 6.2 : 6.2;
    var radiusRatio = cfg ? Number(cfg.radiusRatio) || 0.4 : 0.4;

    var scale = 1;
    var tx = 0;
    var ty = 0;
    var lastRadius = 2;
    var isDragging = false;
    var dragMoved = false;
    var dragStartX = 0;
    var dragStartY = 0;
    var dragOriginTx = 0;
    var dragOriginTy = 0;
    var pinchStartDistance = 0;
    var pinchStartScale = 1;

    function stageSize() {
        if (!stage) {
            return { width: 0, height: 0 };
        }
        var rect = stage.getBoundingClientRect();
        return { width: rect.width, height: rect.height };
    }

    function applyTransform() {
        if (!viewport) {
            return;
        }
        viewport.style.transform = 'translate(' + tx + 'px, ' + ty + 'px) scale(' + scale + ')';
        if (stage) {
            stage.classList.toggle('is-zoomed', scale > 1.01);
            stage.classList.toggle('is-dragging', isDragging);
        }
    }

    function setTransform(nextScale, nextTx, nextTy) {
        var size = stageSize();
        scale = clampScale(nextScale);
        var pan = clampPan(nextTx, nextTy, scale, size.width, size.height);
        tx = pan.tx;
        ty = pan.ty;
        applyTransform();
        scheduleDraw();
    }

    function zoomBy(factor, pointerX, pointerY) {
        var size = stageSize();
        var px = typeof pointerX === 'number' ? pointerX : size.width / 2;
        var py = typeof pointerY === 'number' ? pointerY : size.height / 2;
        var nextScale = clampScale(scale * factor);
        var pan = zoomAt(scale, tx, ty, size.width, size.height, px, py, nextScale);
        scale = nextScale;
        tx = pan.tx;
        ty = pan.ty;
        applyTransform();
        scheduleDraw();
    }

    function resetZoom() {
        setTransform(1, 0, 0);
    }

    function drawMosaic() {
        if (!canvas || !slots.length || !stage) {
            return;
        }

        var rect = stage.getBoundingClientRect();
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

        var radius = smileyRadius(rect.width, viewBoxW, gridStep, radiusRatio);
        lastRadius = radius;

        for (var i = 0; i < slots.length; i++) {
            var point = normalizePoint(slots[i]);
            if (!point) {
                continue;
            }
            var x = (point[0] / 100) * rect.width;
            var y = (point[1] / 100) * rect.height;
            drawSmileyFace(ctx, x, y, lastRadius, !!filledLookup[i]);
        }
    }

    var resizeFrame = null;
    function scheduleDraw() {
        if (resizeFrame !== null) {
            return;
        }
        resizeFrame = window.requestAnimationFrame(function () {
            resizeFrame = null;
            drawMosaic();
        });
    }

    function pointerToStage(ev) {
        var rect = stage.getBoundingClientRect();
        return {
            x: ev.clientX - rect.left,
            y: ev.clientY - rect.top
        };
    }

    function onPointerDown(ev) {
        if (!stage || ev.button > 0) {
            return;
        }
        if (ev.target.closest('.cwd-map-zoom-controls, .cwd-map-empty, .cwd-map-empty-cta')) {
            return;
        }
        isDragging = true;
        dragMoved = false;
        dragStartX = ev.clientX;
        dragStartY = ev.clientY;
        dragOriginTx = tx;
        dragOriginTy = ty;
        if (stage.setPointerCapture && ev.pointerId !== undefined) {
            stage.setPointerCapture(ev.pointerId);
        }
    }

    function onPointerMove(ev) {
        if (!isDragging) {
            return;
        }
        var dx = ev.clientX - dragStartX;
        var dy = ev.clientY - dragStartY;
        if (Math.abs(dx) > 4 || Math.abs(dy) > 4) {
            dragMoved = true;
        }
        var size = stageSize();
        var pan = clampPan(dragOriginTx + dx, dragOriginTy + dy, scale, size.width, size.height);
        tx = pan.tx;
        ty = pan.ty;
        applyTransform();
    }

    function onPointerUp(ev) {
        if (!isDragging) {
            return;
        }
        isDragging = false;
        applyTransform();
        if (stage.releasePointerCapture && ev.pointerId !== undefined) {
            try {
                stage.releasePointerCapture(ev.pointerId);
            } catch (err) {
                // Ignore release errors after DOM changes.
            }
        }
    }

    function onStageClick(ev) {
        if (!canvas || filledCount <= 0 || dragMoved) {
            return;
        }
        if (ev.target.closest('.cwd-map-zoom-controls, .cwd-map-empty, .cwd-map-empty-cta')) {
            return;
        }
        var size = stageSize();
        var pointer = pointerToStage(ev);
        var mapPoint = screenToMapCoords(pointer.x, pointer.y, size.width, size.height, tx, ty, scale);
        var slotIndex = findFilledSlotAt(slots, filledLookup, size.width, size.height, mapPoint.x, mapPoint.y, lastRadius);
        if (slotIndex < 0 || !filledLookup[slotIndex]) {
            return;
        }
        var placement = filledLookup[slotIndex];
        var list = Array.isArray(window.cwdPublicSubmissions) ? window.cwdPublicSubmissions : [];
        var openIndex = Number.isFinite(Number(placement.listIndex))
            ? Number(placement.listIndex)
            : 0;
        document.dispatchEvent(new CustomEvent('cwdMapOpenPin', {
            detail: { index: openIndex, list: list }
        }));
    }

    function onWheel(ev) {
        if (!stage || !stage.contains(ev.target)) {
            return;
        }
        ev.preventDefault();
        var pointer = pointerToStage(ev);
        var factor = ev.deltaY < 0 ? ZOOM_STEP : 1 / ZOOM_STEP;
        zoomBy(factor, pointer.x, pointer.y);
    }

    function touchDistance(touches) {
        var dx = touches[0].clientX - touches[1].clientX;
        var dy = touches[0].clientY - touches[1].clientY;
        return Math.sqrt(dx * dx + dy * dy);
    }

    function onTouchStart(ev) {
        if (ev.touches.length === 2) {
            pinchStartDistance = touchDistance(ev.touches);
            pinchStartScale = scale;
        } else if (ev.touches.length === 1) {
            isDragging = true;
            dragMoved = false;
            dragStartX = ev.touches[0].clientX;
            dragStartY = ev.touches[0].clientY;
            dragOriginTx = tx;
            dragOriginTy = ty;
        }
    }

    function onTouchMove(ev) {
        if (ev.touches.length === 2 && pinchStartDistance > 0) {
            ev.preventDefault();
            var distance = touchDistance(ev.touches);
            var midpointX = (ev.touches[0].clientX + ev.touches[1].clientX) / 2;
            var midpointY = (ev.touches[0].clientY + ev.touches[1].clientY) / 2;
            var rect = stage.getBoundingClientRect();
            var px = midpointX - rect.left;
            var py = midpointY - rect.top;
            var nextScale = clampScale(pinchStartScale * (distance / pinchStartDistance));
            var size = stageSize();
            var pan = zoomAt(scale, tx, ty, size.width, size.height, px, py, nextScale);
            scale = nextScale;
            tx = pan.tx;
            ty = pan.ty;
            applyTransform();
            scheduleDraw();
            return;
        }
        if (isDragging && ev.touches.length === 1) {
            ev.preventDefault();
            var tdx = ev.touches[0].clientX - dragStartX;
            var tdy = ev.touches[0].clientY - dragStartY;
            if (Math.abs(tdx) > 4 || Math.abs(tdy) > 4) {
                dragMoved = true;
            }
            var tSize = stageSize();
            var tPan = clampPan(dragOriginTx + tdx, dragOriginTy + tdy, scale, tSize.width, tSize.height);
            tx = tPan.tx;
            ty = tPan.ty;
            applyTransform();
        }
    }

    function onTouchEnd() {
        isDragging = false;
        pinchStartDistance = 0;
        applyTransform();
    }

    drawMosaic();
    applyTransform();
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

    if (zoomInBtn) {
        zoomInBtn.addEventListener('click', function () {
            zoomBy(ZOOM_STEP);
        });
    }
    if (zoomOutBtn) {
        zoomOutBtn.addEventListener('click', function () {
            zoomBy(1 / ZOOM_STEP);
        });
    }
    if (zoomResetBtn) {
        zoomResetBtn.addEventListener('click', resetZoom);
    }

    if (stage) {
        stage.addEventListener('pointerdown', onPointerDown);
        stage.addEventListener('pointermove', onPointerMove);
        stage.addEventListener('pointerup', onPointerUp);
        stage.addEventListener('pointercancel', onPointerUp);
        stage.addEventListener('click', onStageClick);
        stage.addEventListener('wheel', onWheel, { passive: false });
        stage.addEventListener('touchstart', onTouchStart, { passive: true });
        stage.addEventListener('touchmove', onTouchMove, { passive: false });
        stage.addEventListener('touchend', onTouchEnd, { passive: true });
        stage.addEventListener('touchcancel', onTouchEnd, { passive: true });
    }
})();
