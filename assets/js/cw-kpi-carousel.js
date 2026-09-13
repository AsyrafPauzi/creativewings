/**
 * KPI campaign carousel — homepage shortcode [cw_kpi_carousel].
 * Uses shared cw-carousel.js (loaded separately).
 */
(function () {
  'use strict';
  // Compatibility shim: old markup used data-cw-kpi-* attributes.
  document.querySelectorAll('[data-cw-kpi-carousel]').forEach(function (root) {
    if (!root.hasAttribute('data-cw-carousel')) {
      root.setAttribute('data-cw-carousel', '');
    }
    var track = root.querySelector('[data-cw-kpi-track]');
    if (track && !track.hasAttribute('data-cw-carousel-track')) {
      track.setAttribute('data-cw-carousel-track', '');
    }
    var prev = root.querySelector('[data-cw-kpi-prev]');
    if (prev && !prev.hasAttribute('data-cw-carousel-prev')) {
      prev.setAttribute('data-cw-carousel-prev', '');
    }
    var next = root.querySelector('[data-cw-kpi-next]');
    if (next && !next.hasAttribute('data-cw-carousel-next')) {
      next.setAttribute('data-cw-carousel-next', '');
    }
    if (!root.hasAttribute('data-gap')) {
      root.setAttribute('data-gap', '18');
    }
  });
})();
