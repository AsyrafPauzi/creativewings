/**
 * Shared Creative Wings carousel (KPI + events grid).
 * Roots: [data-cw-carousel]
 * Track: [data-cw-carousel-track]
 * Nav:   [data-cw-carousel-prev] / [data-cw-carousel-next]
 */
(function () {
  'use strict';

  function initCarousel(root) {
    if (!root || root.dataset.cwCarouselReady === '1') return;
    root.dataset.cwCarouselReady = '1';

    var track = root.querySelector('[data-cw-carousel-track]');
    var slides = track ? Array.prototype.slice.call(track.children) : [];
    if (!track || slides.length === 0) return;

    var perView = parseInt(root.getAttribute('data-per-view') || '3', 10) || 3;
    var intervalMs = parseInt(root.getAttribute('data-interval') || '4500', 10) || 4500;
    var autoplay = root.getAttribute('data-autoplay') !== 'no';
    var gap = parseInt(root.getAttribute('data-gap') || '24', 10) || 24;
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var minSlides = Math.max(perView * 2, 6);
    while (track.children.length < minSlides && slides.length > 0) {
      slides.forEach(function (slide) {
        if (track.children.length >= minSlides) return;
        track.appendChild(slide.cloneNode(true));
      });
    }

    var index = 0;
    var timer = null;

    function visibleCount() {
      var w = root.clientWidth;
      if (w < 640) return 1;
      if (w < 960) return Math.min(2, perView);
      return perView;
    }

    function slideWidth() {
      var n = visibleCount();
      var totalGap = gap * (n - 1);
      return (root.clientWidth - totalGap) / n;
    }

    function layout() {
      var w = slideWidth();
      Array.prototype.forEach.call(track.children, function (el) {
        el.style.flex = '0 0 ' + w + 'px';
        el.style.width = w + 'px';
        el.style.maxWidth = w + 'px';
      });
      goTo(index, false);
    }

    function goTo(i, animate) {
      var total = track.children.length;
      if (total === 0) return;
      index = ((i % total) + total) % total;
      track.style.transition = animate === false ? 'none' : 'transform .55s cubic-bezier(.22,.61,.36,1)';
      var offset = index * (slideWidth() + gap);
      track.style.transform = 'translate3d(' + (-offset) + 'px,0,0)';
    }

    function next() {
      var total = track.children.length;
      var n = visibleCount();
      if (index >= total - n) {
        goTo(index + 1, true);
        window.setTimeout(function () {
          goTo(0, false);
        }, 560);
      } else {
        goTo(index + 1, true);
      }
    }

    function start() {
      stop();
      if (!autoplay || reduced || track.children.length <= visibleCount()) return;
      timer = window.setInterval(next, intervalMs);
    }

    function stop() {
      if (timer) {
        window.clearInterval(timer);
        timer = null;
      }
    }

    var prevBtn = root.querySelector('[data-cw-carousel-prev]');
    var nextBtn = root.querySelector('[data-cw-carousel-next]');
    if (prevBtn) {
      prevBtn.addEventListener('click', function () {
        goTo(index - 1, true);
        start();
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        next();
        start();
      });
    }

    root.addEventListener('mouseenter', stop);
    root.addEventListener('mouseleave', start);
    root.addEventListener('focusin', stop);
    root.addEventListener('focusout', start);

    var resizeTimer = null;
    window.addEventListener('resize', function () {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(function () {
        layout();
        start();
      }, 150);
    });

    layout();
    start();
  }

  function boot() {
    document.querySelectorAll('[data-cw-carousel]').forEach(initCarousel);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
