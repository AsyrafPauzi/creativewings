/**
 * Phase 4 Pass 1 — campaign single product motion.
 * Hero entrance, media scale, section reveals.
 * About prose stays plain HTML (no word scrub — preserves formatting).
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

  ready(function () {
    var root = document.querySelector('[data-cw-phase4-product]');
    if (!root || typeof gsap === 'undefined') return;

    var reduced =
      window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (typeof ScrollTrigger !== 'undefined') {
      gsap.registerPlugin(ScrollTrigger);
    }

    var clouds = root.querySelectorAll('[data-cw-phase4-cloud]');
    var hero = root.querySelector('.cwd-hero-card');
    var heroMedia = root.querySelector(
      '.cwd-hero-img-col, .cwd-hero-carousel, .cwd-hero-img'
    );
    var heroInfo = root.querySelector('.cwd-hero-info-col');
    var sections = gsap.utils.toArray(
      root.querySelectorAll('.cwd-section, .cwd-sidebar .cwd-card')
    );

    if (reduced) {
      gsap.set([hero, heroMedia, heroInfo, sections], { clearProps: 'all' });
      return;
    }

    clouds.forEach(function (cloud, i) {
      var dir = i % 2 === 0 ? 1 : -1;
      gsap.to(cloud, {
        x: dir * (18 + i * 8),
        y: Math.sin(i + 1) * 6,
        duration: 12 + i * 2,
        ease: 'sine.inOut',
        yoyo: true,
        repeat: -1,
      });
    });

    if (hero) {
      gsap.set(hero, { opacity: 0, y: 28 });
      if (heroMedia) gsap.set(heroMedia, { scale: 0.94, opacity: 0.5 });
      if (heroInfo) gsap.set(heroInfo, { y: 20, opacity: 0 });

      gsap
        .timeline({ defaults: { ease: 'power3.out' } })
        .to(hero, { opacity: 1, y: 0, duration: 0.7 })
        .to(
          heroMedia,
          { scale: 1, opacity: 1, duration: 0.75, force3D: true },
          '-=0.45'
        )
        .to(heroInfo, { y: 0, opacity: 1, duration: 0.55 }, '-=0.5');
    }

    if (heroMedia && hero && typeof ScrollTrigger !== 'undefined') {
      gsap.to(heroMedia, {
        scale: 1.04,
        ease: 'none',
        scrollTrigger: {
          trigger: hero,
          start: 'top top',
          end: 'bottom top',
          scrub: 0.7,
        },
      });
    }

    if (sections.length && typeof ScrollTrigger !== 'undefined') {
      sections.forEach(function (card) {
        gsap.fromTo(
          card,
          { y: 28, opacity: 0.35, scale: 0.985 },
          {
            y: 0,
            opacity: 1,
            scale: 1,
            ease: 'none',
            scrollTrigger: {
              trigger: card,
              start: 'top 92%',
              end: 'top 68%',
              scrub: true,
            },
          }
        );
      });
    }

    if (typeof ScrollTrigger !== 'undefined') {
      requestAnimationFrame(function () {
        ScrollTrigger.refresh();
      });
    }
  });
})();
