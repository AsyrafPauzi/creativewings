/**
 * Phase 3 pages — scrubbing intro, card lift, media scale.
 * Honors prefers-reduced-motion.
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function wrapScrubWords(el) {
    if (!el || el.dataset.cwScrubReady) return;
    var text = el.textContent.trim();
    if (!text) return;
    el.dataset.cwScrubReady = '1';
    el.innerHTML = text
      .split(/(\s+)/)
      .map(function (chunk) {
        if (/^\s+$/.test(chunk)) return chunk;
        return '<span class="cw-p3-word">' + chunk + '</span>';
      })
      .join('');
  }

  ready(function () {
    var root = document.querySelector('[data-cw-p3]');
    if (!root || typeof gsap === 'undefined') return;
    /* SDGs page has its own intensive motion system */
    if (root.hasAttribute('data-cw-sdgs')) return;

    var reduced =
      window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (typeof ScrollTrigger !== 'undefined') {
      gsap.registerPlugin(ScrollTrigger);
    }

    var scrubEl = root.querySelector('[data-cw-p3-scrub]');
    wrapScrubWords(scrubEl);

    var blocks = root.querySelectorAll('[data-cw-p3-block]');
    var cards = root.querySelectorAll('[data-cw-p3-card]');
    var media = root.querySelector('[data-cw-p3-media] img');

    if (reduced) {
      if (scrubEl) {
        gsap.set(scrubEl.querySelectorAll('.cw-p3-word'), { opacity: 1 });
      }
      return;
    }

    if (scrubEl && typeof ScrollTrigger !== 'undefined') {
      var words = scrubEl.querySelectorAll('.cw-p3-word');
      gsap.set(words, { opacity: 0.18 });
      gsap.to(words, {
        opacity: 1,
        stagger: 0.05,
        ease: 'none',
        scrollTrigger: {
          trigger: scrubEl,
          start: 'top 82%',
          end: 'bottom 48%',
          scrub: true,
        },
      });
    }

    if (typeof ScrollTrigger !== 'undefined') {
      blocks.forEach(function (block) {
        gsap.fromTo(
          block,
          { y: 26 },
          {
            y: 0,
            ease: 'none',
            scrollTrigger: {
              trigger: block,
              start: 'top 92%',
              end: 'top 62%',
              scrub: true,
            },
          }
        );
      });

      cards.forEach(function (card) {
        gsap.fromTo(
          card,
          { y: 18, scale: 0.97 },
          {
            y: 0,
            scale: 1,
            ease: 'none',
            scrollTrigger: {
              trigger: card,
              start: 'top 94%',
              end: 'top 64%',
              scrub: true,
            },
          }
        );
      });

      if (media) {
        gsap.fromTo(
          media,
          { scale: 0.94 },
          {
            scale: 1,
            ease: 'none',
            scrollTrigger: {
              trigger: media,
              start: 'top 90%',
              end: 'top 45%',
              scrub: true,
            },
          }
        );
      }

      ScrollTrigger.refresh();
    }
  });
})();
