/**
 * Build Malaysia smiley-mosaic map from Natural Earth boundary data.
 *
 * Land outline SVG + hex smiley slot grid.
 * Input:  assets/data/malaysia-boundary.geojson
 * Output: assets/data/malaysia-smiley-slots.json
 *         assets/img/malaysia-map.svg (neutral backdrop — land shape from smileys only)
 */
import { readFileSync, writeFileSync } from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const VIEW_W = 1360;
const VIEW_H = 680;
const TARGET_SLOTS = 700;
const RADIUS_RATIO = 0.5;
const FIT_MARGIN = 24;
const ZOOM_OUT = 0.94;
const WIDTH_STRETCH = 1.34; // Wider silhouette (political-map style, less pencil-thin)

const geo = JSON.parse(
    readFileSync(join(ROOT, 'assets/data/malaysia-boundary.geojson'), 'utf8')
);
const feature = geo.features.find((f) => f.properties?.name === 'Malaysia');
if (!feature) {
    throw new Error('Malaysia feature not found in boundary GeoJSON');
}

/** @type {number[][][][]} */
const multiPolygon =
    feature.geometry.type === 'MultiPolygon'
        ? feature.geometry.coordinates
        : [feature.geometry.coordinates];

function projectLonLat(lon, lat, bounds) {
    const x =
        ((lon - bounds.minLon) / (bounds.maxLon - bounds.minLon)) * VIEW_W;
    const y =
        ((bounds.maxLat - lat) / (bounds.maxLat - bounds.minLat)) * VIEW_H;
    return [x, y];
}

let minLon = Infinity;
let maxLon = -Infinity;
let minLat = Infinity;
let maxLat = -Infinity;

multiPolygon.forEach((polygon) => {
    polygon.forEach((ring) => {
        ring.forEach(([lon, lat]) => {
            minLon = Math.min(minLon, lon);
            maxLon = Math.max(maxLon, lon);
            minLat = Math.min(minLat, lat);
            maxLat = Math.max(maxLat, lat);
        });
    });
});

const lonPad = (maxLon - minLon) * 0.01;
const latPad = (maxLat - minLat) * 0.01;
const bounds = {
    minLon: minLon - lonPad,
    maxLon: maxLon + lonPad,
    minLat: minLat - latPad,
    maxLat: maxLat + latPad,
};

/** @type {number[][][]} */
let projected = multiPolygon.map((polygon) =>
    polygon.map((ring) => ring.map(([lon, lat]) => projectLonLat(lon, lat, bounds)))
);

let bboxMinX = Infinity;
let bboxMaxX = -Infinity;
let bboxMinY = Infinity;
let bboxMaxY = -Infinity;

projected.forEach((polygon) => {
    polygon.forEach((ring) => {
        ring.forEach(([x, y]) => {
            bboxMinX = Math.min(bboxMinX, x);
            bboxMaxX = Math.max(bboxMaxX, x);
            bboxMinY = Math.min(bboxMinY, y);
            bboxMaxY = Math.max(bboxMaxY, y);
        });
    });
});

const rawCenterX = (bboxMinX + bboxMaxX) / 2;
projected = projected.map((polygon) =>
    polygon.map((ring) =>
        ring.map(([x, y]) => [rawCenterX + (x - rawCenterX) * WIDTH_STRETCH, y])
    )
);

bboxMinX = Infinity;
bboxMaxX = -Infinity;
bboxMinY = Infinity;
bboxMaxY = -Infinity;

projected.forEach((polygon) => {
    polygon.forEach((ring) => {
        ring.forEach(([x, y]) => {
            bboxMinX = Math.min(bboxMinX, x);
            bboxMaxX = Math.max(bboxMaxX, x);
            bboxMinY = Math.min(bboxMinY, y);
            bboxMaxY = Math.max(bboxMaxY, y);
        });
    });
});

const landW = bboxMaxX - bboxMinX;
const landH = bboxMaxY - bboxMinY;
const scaleW = (VIEW_W - FIT_MARGIN * 2) / landW;
const scaleH = (VIEW_H - FIT_MARGIN * 2) / landH;
const fitScale = Math.min(scaleW, scaleH) * ZOOM_OUT;

const fittedW = landW * fitScale;
const fittedH = landH * fitScale;
const offsetX = (VIEW_W - fittedW) / 2;
const offsetY = (VIEW_H - fittedH) / 2;

function fitPoint(x, y) {
    return [
        offsetX + (x - bboxMinX) * fitScale,
        offsetY + (y - bboxMinY) * fitScale,
    ];
}

projected = projected.map((polygon) =>
    polygon.map((ring) => ring.map(([x, y]) => fitPoint(x, y)))
);

function pointInRing(x, y, ring) {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const xi = ring[i][0];
        const yi = ring[i][1];
        const xj = ring[j][0];
        const yj = ring[j][1];
        const intersect =
            yi > y !== yj > y &&
            x < ((xj - xi) * (y - yi)) / (yj - yi + 0.000001) + xi;
        if (intersect) inside = !inside;
    }
    return inside;
}

function onLand(x, y) {
    for (const polygon of projected) {
        if (!polygon.length) continue;
        if (!pointInRing(x, y, polygon[0])) continue;
        let inHole = false;
        for (let h = 1; h < polygon.length; h++) {
            if (pointInRing(x, y, polygon[h])) {
                inHole = true;
                break;
            }
        }
        if (!inHole) return true;
    }
    return false;
}

const svg = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${VIEW_W} ${VIEW_H}" role="img" aria-labelledby="title desc">
  <title id="title">Malaysia map</title>
  <desc id="desc">Malaysia smiley mosaic neutral backdrop.</desc>
  <rect width="${VIEW_W}" height="${VIEW_H}" fill="#ffffff"/>
</svg>
`;

function generateSlots(gridStep) {
    const hexRowStep = gridStep * (Math.sqrt(3) / 2);
    const out = [];

    for (let row = 0, y = gridStep / 2; y < VIEW_H; y += hexRowStep, row++) {
        const offset = (row % 2) * (gridStep / 2);
        for (let x = gridStep / 2 + offset; x < VIEW_W; x += gridStep) {
            if (!onLand(x, y)) continue;
            out.push([
                Math.round((x / VIEW_W) * 10000) / 100,
                Math.round((y / VIEW_H) * 10000) / 100,
            ]);
        }
    }

    out.sort((a, b) => (a[1] === b[1] ? a[0] - b[0] : a[1] - b[1]));
    return out;
}

let lo = 7;
let hi = 24;
let gridStep = hi;
let slots = generateSlots(gridStep);

while (hi - lo > 0.05) {
    const mid = (lo + hi) / 2;
    const count = generateSlots(mid).length;
    if (count > TARGET_SLOTS) {
        lo = mid;
    } else {
        hi = mid;
        gridStep = mid;
        slots = generateSlots(mid);
    }
}

if (slots.length > TARGET_SLOTS) {
    gridStep = hi;
    slots = generateSlots(gridStep);
}

writeFileSync(join(ROOT, 'assets/img/malaysia-map.svg'), svg);
writeFileSync(
    join(ROOT, 'assets/data/malaysia-smiley-slots.json'),
    JSON.stringify({
        viewBox: [VIEW_W, VIEW_H],
        gridStep: Math.round(gridStep * 100) / 100,
        radiusRatio: RADIUS_RATIO,
        targetSlots: TARGET_SLOTS,
        widthStretch: WIDTH_STRETCH,
        count: slots.length,
        slots,
        source: 'malaysia-boundary.geojson',
    })
);

console.log(`Malaysia polygons: ${projected.length}`);
console.log(`Land smiley slots: ${slots.length} (target ${TARGET_SLOTS})`);
console.log(`Grid step: ${gridStep.toFixed(2)}px, radius ratio: ${RADIUS_RATIO}`);
console.log('Wrote assets/img/malaysia-map.svg');
console.log('Wrote assets/data/malaysia-smiley-slots.json');
