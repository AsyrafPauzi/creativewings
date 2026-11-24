/**
 * Contact / About story pages — scrubbing intro, pin, media scale.
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
        return '<span class="cw-story-word">' + chunk + '</span>';
      })
      .join('');
  }

  ready(function () {
    var root = document.querySelector('[data-cw-story]');
    if (!root || typeof gsap === 'undefined') return;

    var reduced =
      window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (typeof ScrollTrigger !== 'undefined') {
      gsap.registerPlugin(ScrollTrigger);
    }

    var scrubEl = root.querySelector('[data-cw-story-scrub]');
    wrapScrubWords(scrubEl);

    var rail = root.querySelector('[data-cw-story-rail]');
    var blocks = root.querySelectorAll('[data-cw-story-block]');
    var media = root.querySelector('[data-cw-story-media] iframe');

    if (reduced) {
      if (scrubEl) {
        gsap.set(scrubEl.querySelectorAll('.cw-story-word'), { opacity: 1 });
      }
      return;
    }

    if (rail) {
      gsap.fromTo(
        rail,
        { y: 28, autoAlpha: 0 },
        { y: 0, autoAlpha: 1, duration: 0.8, ease: 'power2.out', delay: 0.15 }
      );
    }

    if (scrubEl && typeof ScrollTrigger !== 'undefined') {
      var words = scrubEl.querySelectorAll('.cw-story-word');
      gsap.set(words, { opacity: 0.18 });
      gsap.to(words, {
        opacity: 1,
        stagger: 0.06,
        ease: 'none',
        scrollTrigger: {
          trigger: scrubEl,
          start: 'top 80%',
          end: 'bottom 45%',
          scrub: true,
        },
      });
    }

    if (typeof ScrollTrigger !== 'undefined') {
      blocks.forEach(function (block) {
        gsap.fromTo(
          block,
          { y: 28 },
          {
            y: 0,
            ease: 'none',
            scrollTrigger: {
              trigger: block,
              start: 'top 90%',
              end: 'top 58%',
              scrub: true,
            },
          }
        );
      });

      if (media) {
        gsap.fromTo(
          media,
          { scale: 0.92 },
          {
            scale: 1,
            ease: 'none',
            scrollTrigger: {
              trigger: media,
              start: 'top 92%',
              end: 'top 50%',
              scrub: true,
            },
          }
        );
      }

      ScrollTrigger.refresh();
    }
  });
})();
