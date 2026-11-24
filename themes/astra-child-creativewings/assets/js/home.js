/**
 * Homepage motion — GSAP + ScrollTrigger.
 * Honors prefers-reduced-motion.
 * Never leaves interactive content stuck at opacity:0.
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

  function formatCount(value, decimals) {
    var n = Number(value);
    if (!isFinite(n)) return '0';
    if (decimals > 0) {
      return n.toLocaleString('en-MY', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
      });
    }
    return Math.round(n).toLocaleString('en-MY');
  }

  function revealIn(els, opts) {
    if (!els || !els.length || typeof ScrollTrigger === 'undefined') return;
    gsap.fromTo(
      els,
      { y: opts.y || 28, autoAlpha: 0 },
      {
        y: 0,
        autoAlpha: 1,
        duration: opts.duration || 0.75,
        stagger: opts.stagger || 0,
        ease: opts.ease || 'power2.out',
        immediateRender: false,
        clearProps: 'transform',
        scrollTrigger: {
          trigger: opts.trigger,
          start: opts.start || 'top 85%',
          once: true,
          invalidateOnRefresh: true,
        },
      }
    );
  }

  ready(function () {
    var root = document.querySelector('[data-cw-home]');
    if (!root || typeof gsap === 'undefined') return;

    var reduced =
      window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* Safety: campaign cards must always be readable */
    var eventCards = root.querySelectorAll('.cwg-events-wrap .cwg-card, .cwg-carousel__slide .cwg-card');
    gsap.set(eventCards, { clearProps: 'all', autoAlpha: 1, y: 0 });

    if (reduced) {
      root.querySelectorAll('[data-cw-count]').forEach(function (el) {
        var target = parseFloat(el.getAttribute('data-cw-count') || '0');
        var decimals = parseInt(el.getAttribute('data-cw-count-decimals') || '0', 10);
        el.textContent = formatCount(target, decimals);
      });
      return;
    }

    if (typeof ScrollTrigger !== 'undefined') {
      gsap.registerPlugin(ScrollTrigger);
    }

    /* Soft drifting clouds */
    root.querySelectorAll('[data-cw-cloud]').forEach(function (cloud, i) {
      var dir = i % 2 === 0 ? 1 : -1;
      gsap.to(cloud, {
        x: dir * (36 + i * 10),
        y: Math.sin(i + 1) * 14,
        duration: 18 + i * 3.5,
        ease: 'sine.inOut',
        yoyo: true,
        repeat: -1,
      });
    });

    /* Hero entrance */
    var heroLines = root.querySelectorAll('[data-cw-hero-line]');
    if (heroLines.length) {
      gsap.fromTo(
        heroLines,
        { y: 28, autoAlpha: 0 },
        {
          y: 0,
          autoAlpha: 1,
          duration: 0.85,
          stagger: 0.1,
          ease: 'power3.out',
          delay: 0.06,
          clearProps: 'transform',
        }
      );
    }

    /* Soft hero parallax (background only — no fade) */
    var hero = root.querySelector('[data-cw-hero]');
    if (hero && typeof ScrollTrigger !== 'undefined') {
      gsap.to(hero, {
        backgroundPosition: '50% 70%',
        ease: 'none',
        scrollTrigger: {
          trigger: hero,
          start: 'top top',
          end: 'bottom top',
          scrub: true,
        },
      });
    }

    /* Impact count-up */
    var impact = root.querySelector('[data-cw-impact]');
    if (impact) {
      revealIn(impact.querySelectorAll('[data-cw-impact-item]'), {
        trigger: impact,
        start: 'top 85%',
        stagger: 0.08,
        y: 20,
      });

      impact.querySelectorAll('[data-cw-count]').forEach(function (el) {
        var target = parseFloat(el.getAttribute('data-cw-count') || '0');
        var decimals = parseInt(el.getAttribute('data-cw-count-decimals') || '0', 10);
        var obj = { v: 0 };
        if (typeof ScrollTrigger === 'undefined') {
          el.textContent = formatCount(target, decimals);
          return;
        }
        ScrollTrigger.create({
          trigger: el,
          start: 'top 88%',
          once: true,
          onEnter: function () {
            gsap.to(obj, {
              v: target,
              duration: 1.35,
              ease: 'power2.out',
              onUpdate: function () {
                el.textContent = formatCount(obj.v, decimals);
              },
            });
          },
        });
      });
    }

    /* Featured image scale (keep visible) */
    root.querySelectorAll('.cw-home-featured__media img').forEach(function (img) {
      if (typeof ScrollTrigger === 'undefined') return;
      gsap.fromTo(
        img,
        { scale: 1.08 },
        {
          scale: 1,
          ease: 'none',
          scrollTrigger: {
            trigger: img.closest('.cw-home-featured') || img,
            start: 'top 85%',
            end: 'bottom 40%',
            scrub: true,
          },
        }
      );
    });

    /* Section reveals — never fade Open Campaigns cards (carousel must stay readable) */
    root.querySelectorAll('.cw-home-featured-wrap, .cw-home-smiles__head, .cw-home-partners').forEach(function (block) {
      revealIn([block], {
        trigger: block,
        start: 'top 88%',
        y: 28,
        duration: 0.8,
      });
    });

    /* How it works */
    var steps = root.querySelector('.cw-home-steps');
    if (steps) {
      revealIn(steps.querySelectorAll('.cw-home-steps__item'), {
        trigger: steps,
        start: 'top 80%',
        stagger: 0.1,
        y: 24,
      });
    }

    /* Smile marquee */
    var track = root.querySelector('[data-cw-smiles-track]');
    if (track) {
      var total = track.scrollWidth / 2;
      if (total > 0) {
        gsap.to(track, {
          x: -total,
          duration: Math.max(28, total / 40),
          ease: 'none',
          repeat: -1,
        });
      }
      track.querySelectorAll('[data-cw-smile-item]').forEach(function (item) {
        item.addEventListener('mouseenter', function () {
          gsap.to(item, { y: -6, duration: 0.3, ease: 'power2.out' });
        });
        item.addEventListener('mouseleave', function () {
          gsap.to(item, { y: 0, duration: 0.4, ease: 'power3.out' });
        });
      });
    }

    /* Open Campaigns: light slide-in only, cards stay opaque */
    var eventsWrap = root.querySelector('.cwg-events-wrap');
    if (eventsWrap && eventCards.length && typeof ScrollTrigger !== 'undefined') {
      gsap.fromTo(
        eventCards,
        { y: 18 },
        {
          y: 0,
          duration: 0.55,
          stagger: 0.06,
          ease: 'power2.out',
          immediateRender: false,
          clearProps: 'transform',
          scrollTrigger: {
            trigger: eventsWrap,
            start: 'top 90%',
            once: true,
            invalidateOnRefresh: true,
          },
        }
      );
    }

    /* CTA lines */
    var cta = root.querySelector('[data-cw-cta]');
    if (cta) {
      revealIn(cta.querySelectorAll('[data-cw-cta-line]'), {
        trigger: cta,
        start: 'top 85%',
        stagger: 0.08,
        y: 20,
      });
    }

    if (typeof ScrollTrigger !== 'undefined') {
      ScrollTrigger.refresh();
    }
  });
})();
