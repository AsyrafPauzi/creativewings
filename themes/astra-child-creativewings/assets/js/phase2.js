/**
 * Phase 2 page motion — GSAP + ScrollTrigger.
 * Honors prefers-reduced-motion. Never leaves forms/cards stuck invisible.
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

  ready(function () {
    var root = document.querySelector('[data-cw-phase2]');
    if (!root || typeof gsap === 'undefined') return;

    var reduced =
      window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var panels = root.querySelectorAll(
      '.cw-phase2__panel, .cw-dir, .cw-sponsors-page, .custom-login-form, .cw-auth, form'
    );
    gsap.set(panels, { clearProps: 'opacity,visibility,autoAlpha', autoAlpha: 1, y: 0 });

    if (reduced) return;

    if (typeof ScrollTrigger !== 'undefined') {
      gsap.registerPlugin(ScrollTrigger);
    }

    root.querySelectorAll('[data-cw-phase2-cloud]').forEach(function (cloud, i) {
      var dir = i % 2 === 0 ? 1 : -1;
      gsap.to(cloud, {
        x: dir * (22 + i * 10),
        y: Math.sin(i + 1) * 8,
        duration: 14 + i * 3,
        ease: 'sine.inOut',
        yoyo: true,
        repeat: -1,
      });
    });

    var lines = root.querySelectorAll('[data-cw-phase2-line]');
    if (lines.length) {
      gsap.fromTo(
        lines,
        { y: 24, autoAlpha: 0 },
        {
          y: 0,
          autoAlpha: 1,
          duration: 0.75,
          stagger: 0.08,
          ease: 'power3.out',
          overwrite: 'auto',
          onComplete: function () {
            gsap.set(lines, {
              clearProps: 'opacity,visibility,transform,translate,rotate,scale',
              autoAlpha: 1,
              y: 0,
            });
          },
        }
      );
    }

    var body = root.querySelector('[data-cw-phase2-body]');
    if (body && typeof ScrollTrigger !== 'undefined') {
      gsap.fromTo(
        body,
        { y: 28 },
        {
          y: 0,
          duration: 0.7,
          ease: 'power2.out',
          immediateRender: false,
          clearProps: 'transform',
          scrollTrigger: {
            trigger: body,
            start: 'top 92%',
            once: true,
          },
        }
      );
    }

    if (typeof ScrollTrigger !== 'undefined') {
      ScrollTrigger.refresh();
    }
  });
})();
