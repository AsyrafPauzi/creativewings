import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(
  new URL('../assets/js/cw-map-gallery.js', import.meta.url),
  'utf8'
);

const window = {
  devicePixelRatio: 2,
  matchMedia: () => ({ matches: true }),
  addEventListener: () => {},
};
const document = {
  getElementById: () => null,
};

vm.runInNewContext(source, {
  window,
  document,
  Math,
  Number,
  String,
  Array,
  setTimeout,
  clearTimeout,
});

assert.ok(window.CWMapGalleryTest, 'test helpers are exposed');
assert.deepEqual(
  Array.from(window.CWMapGalleryTest.normalizePoint([12.345, 67.891])),
  [12.35, 67.89]
);
assert.deepEqual(
  Array.from(window.CWMapGalleryTest.normalizePoint([-5, 120])),
  [0, 100]
);
assert.equal(window.CWMapGalleryTest.normalizePoint(['x', 1]), null);

assert.equal(
  window.CWMapGalleryTest.findFilledSlotAt(
    [[50, 50], [10, 10]],
    window.CWMapGalleryTest.buildFilledLookup([{ slot: 0 }]),
    100,
    100,
    50,
    50,
    4
  ),
  0
);

assert.equal(
  Math.round(window.CWMapGalleryTest.smileyRadius(800, 1000, 7, 0.48) * 100) / 100,
  2.69
);

const mapped = window.CWMapGalleryTest.screenToMapCoords(60, 40, 100, 100, 0, 0, 2);
assert.equal(mapped.x, 55);
assert.equal(mapped.y, 45);

const clamped = window.CWMapGalleryTest.clampPan(120, 0, 2, 100, 100);
assert.equal(clamped.tx, 50);

console.log('PASS: canvas map helpers');
