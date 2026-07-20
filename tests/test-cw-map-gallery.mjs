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

console.log('PASS: canvas map helpers');
