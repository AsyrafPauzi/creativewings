/**
 * Activities / Competitions browse motion — GSAP + ScrollTrigger.
 * Honors prefers-reduced-motion. Never leaves cards stuck invisible.
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
    var root = document.querySelector('[data-cw-browse]');
    if (!root || typeof gsap === 'undefined') return;

    var reduced =
      window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var cards = root.querySelectorAll('.cwg-card');
    gsap.set(cards, { clearProps: 'opacity,visibility,autoAlpha', autoAlpha: 1, y: 0 });

    if (reduced) return;

    if (typeof ScrollTrigger !== 'undefined') {
      gsap.registerPlugin(ScrollTrigger);
    }

    /* Soft drifting clouds */
    root.querySelectorAll('[data-cw-browse-cloud]').forEach(function (cloud, i) {
      var dir = i % 2 === 0 ? 1 : -1;
      gsap.to(cloud, {
        x: dir * (28 + i * 12),
        y: Math.sin(i + 1) * 10,
        duration: 16 + i * 3,
        ease: 'sine.inOut',
        yoyo: true,
        repeat: -1,
      });
    });

    /* Hero entrance */
    var heroLines = root.querySelectorAll('[data-cw-browse-line]');
    if (heroLines.length) {
      gsap.fromTo(
        heroLines,
        { y: 32, autoAlpha: 0 },
        {
          y: 0,
          autoAlpha: 1,
          duration: 0.85,
          stagger: 0.1,
          ease: 'power3.out',
          delay: 0.05,
          clearProps: 'transform',
        }
      );
    }

    /* Hero parallax wash */
    var hero = root.querySelector('[data-cw-browse-hero]');
    if (hero && typeof ScrollTrigger !== 'undefined') {
      gsap.to(hero, {
        backgroundPosition: '50% 72%',
        ease: 'none',
        scrollTrigger: {
          trigger: hero,
          start: 'top top',
          end: 'bottom top',
          scrub: true,
        },
      });
    }

    /* Filter tabs slide in */
    var tabs = root.querySelectorAll('.cwg-tab, .cwg-view-toggle button, .cwg-view-toggle a');
    if (tabs.length && typeof ScrollTrigger !== 'undefined') {
      gsap.fromTo(
        tabs,
        { y: 14, autoAlpha: 0 },
        {
          y: 0,
          autoAlpha: 1,
          duration: 0.5,
          stagger: 0.04,
          ease: 'power2.out',
          immediateRender: false,
          clearProps: 'transform',
          scrollTrigger: {
            trigger: root.querySelector('.cwg-tabs-wrap, .cwg-toolbar, .cwg-wrap') || root,
            start: 'top 90%',
            once: true,
          },
        }
      );
    }

    /* Cards: scale + rise — stay fully opaque */
    if (cards.length && typeof ScrollTrigger !== 'undefined') {
      gsap.fromTo(
        cards,
        { y: 36, scale: 0.94 },
        {
          y: 0,
          scale: 1,
          duration: 0.7,
          stagger: 0.08,
          ease: 'power3.out',
          immediateRender: false,
          clearProps: 'transform',
          scrollTrigger: {
            trigger: root.querySelector('[data-cw-browse-grid]') || root,
            start: 'top 88%',
            once: true,
            invalidateOnRefresh: true,
          },
        }
      );

      cards.forEach(function (card) {
        var img = card.querySelector('.cwg-card-cover img');
        if (!img) return;
        gsap.fromTo(
          img,
          { scale: 1.12 },
          {
            scale: 1,
            ease: 'none',
            scrollTrigger: {
              trigger: card,
              start: 'top 90%',
              end: 'bottom 45%',
              scrub: true,
            },
          }
        );
      });
    }

    /* Promo band */
    var promo = root.querySelector('[data-cw-browse-promo]');
    if (promo) {
      var promoLines = promo.querySelectorAll('[data-cw-browse-promo-line]');
      if (promoLines.length && typeof ScrollTrigger !== 'undefined') {
        gsap.fromTo(
          promoLines,
          { y: 24, autoAlpha: 0 },
          {
            y: 0,
            autoAlpha: 1,
            duration: 0.75,
            stagger: 0.08,
            ease: 'power2.out',
            immediateRender: false,
            clearProps: 'transform',
            scrollTrigger: {
              trigger: promo,
              start: 'top 85%',
              once: true,
            },
          }
        );
      }
    }

    if (typeof ScrollTrigger !== 'undefined') {
      ScrollTrigger.refresh();
    }
  });
})();
