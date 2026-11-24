/**
 * Sponsors page — marquee, scrubbing intro, logo scale on scroll.
 * All partner tiers stay open (logos always visible).
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
        return '<span class="cw-sponsors-word">' + chunk + '</span>';
      })
      .join('');
  }

  function buildMarquee(root) {
    var host = root.querySelector('[data-cw-sponsors-marquee]');
    var track = root.querySelector('[data-cw-sponsors-marquee-track]');
    if (!host || !track) return null;

    var logos = root.querySelectorAll('.cwd-partner-logo img');
    if (!logos.length) return null;

    var seen = {};
    var items = [];
    logos.forEach(function (img) {
      var src = img.currentSrc || img.src;
      if (!src || seen[src]) return;
      seen[src] = true;
      var alt = img.alt || '';
      var cell = document.createElement('div');
      cell.className = 'cw-sponsors-marquee__item';
      var node = document.createElement('img');
      node.src = src;
      node.alt = alt;
      node.loading = 'lazy';
      node.decoding = 'async';
      cell.appendChild(node);
      items.push(cell);
    });

    if (!items.length) return null;

    track.innerHTML = '';
    items.forEach(function (n) {
      track.appendChild(n);
    });
    items.forEach(function (n) {
      track.appendChild(n.cloneNode(true));
    });

    host.hidden = false;
    return track;
  }

  function labelPackages(root) {
    root.querySelectorAll('.cwd-partners-package').forEach(function (pkg) {
      var label = pkg.querySelector('.cwd-partners-package__label');
      if (!label) return;

      /* Keep label | body as 2 columns so Media + Non-media stack in the right pane
         (not under the label as a skinny third grid cell). */
      if (!pkg.querySelector('.cwd-partners-package__body')) {
        var body = document.createElement('div');
        body.className = 'cwd-partners-package__body';
        Array.prototype.slice.call(pkg.children).forEach(function (child) {
          if (child !== label) {
            body.appendChild(child);
          }
        });
        pkg.appendChild(body);
      }

      var count = pkg.querySelectorAll('.cwd-partner-item').length;
      if (!count) {
        count = pkg.querySelectorAll('.cwd-partner-logo').length;
      }
      label.setAttribute(
        'data-cw-count',
        count + (count === 1 ? ' partner' : ' partners')
      );
      pkg.classList.add('is-open');
    });
  }

  ready(function () {
    var root = document.querySelector('[data-cw-sponsors]');
    if (!root || typeof gsap === 'undefined') return;

    var reduced =
      window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (typeof ScrollTrigger !== 'undefined') {
      gsap.registerPlugin(ScrollTrigger);
    }

    var scrubEl = root.querySelector('[data-cw-sponsors-scrub]');
    wrapScrubWords(scrubEl);
    labelPackages(root);
    var marqueeTrack = buildMarquee(root);
    var logos = root.querySelectorAll('.cwd-partner-logo');
    var aside = root.querySelector('[data-cw-sponsors-aside]');
    var quote = root.querySelector('[data-cw-sponsors-quote]');
    var mark = root.querySelector('.cw-sponsors-hero__mark');

    gsap.set(logos, {
      clearProps: 'opacity,visibility,autoAlpha,transform,scale',
      autoAlpha: 1,
      scale: 1,
    });

    if (reduced) {
      if (scrubEl) {
        gsap.set(scrubEl.querySelectorAll('.cw-sponsors-word'), { opacity: 1 });
      }
      return;
    }

    if (mark) {
      gsap.fromTo(
        mark,
        { autoAlpha: 0, y: 24 },
        { autoAlpha: 1, y: 0, duration: 0.9, ease: 'power3.out', delay: 0.1 }
      );
    }

    if (aside) {
      gsap.fromTo(
        aside,
        { y: 28, autoAlpha: 0 },
        { y: 0, autoAlpha: 1, duration: 0.85, ease: 'power2.out', delay: 0.15 }
      );
    }

    if (scrubEl && typeof ScrollTrigger !== 'undefined') {
      var words = scrubEl.querySelectorAll('.cw-sponsors-word');
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

    if (marqueeTrack) {
      var loopWidth = marqueeTrack.scrollWidth / 2;
      gsap.fromTo(
        marqueeTrack,
        { x: 0 },
        {
          x: -loopWidth,
          duration: Math.max(30, loopWidth / 40),
          ease: 'none',
          repeat: -1,
        }
      );
    }

    if (typeof ScrollTrigger !== 'undefined') {
      /* Logos stay fully opaque — only lift on enter (no fade that hides marks). */
      logos.forEach(function (logo) {
        gsap.fromTo(
          logo,
          { y: 16, scale: 0.96 },
          {
            y: 0,
            scale: 1,
            ease: 'none',
            scrollTrigger: {
              trigger: logo,
              start: 'top 94%',
              end: 'top 62%',
              scrub: true,
            },
          }
        );
      });

      root.querySelectorAll('.cwd-partners-package').forEach(function (pkg) {
        gsap.fromTo(
          pkg,
          { y: 24 },
          {
            y: 0,
            ease: 'none',
            scrollTrigger: {
              trigger: pkg,
              start: 'top 92%',
              end: 'top 64%',
              scrub: true,
            },
          }
        );
      });

      if (quote) {
        gsap.fromTo(
          quote,
          { y: 28 },
          {
            y: 0,
            ease: 'none',
            scrollTrigger: {
              trigger: quote,
              start: 'top 90%',
              end: 'top 60%',
              scrub: true,
            },
          }
        );
      }

      ScrollTrigger.refresh();
    }
  });
})();
