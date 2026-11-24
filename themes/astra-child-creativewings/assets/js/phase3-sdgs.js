/**
 * SDGs page — GSAP + ScrollTrigger.
 * Hero entrance, stack parallax, manifesto scrub,
 * goals grid + feature panel swap.
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

  function wrapWords(el, className) {
    if (!el || el.dataset.cwScrubReady) return;
    var text = el.textContent.trim();
    if (!text) return;
    el.dataset.cwScrubReady = '1';
    el.innerHTML = text
      .split(/(\s+)/)
      .map(function (chunk) {
        if (/^\s+$/.test(chunk)) return chunk;
        return '<span class="' + className + '">' + chunk + '</span>';
      })
      .join('');
  }

  ready(function () {
    var root = document.querySelector('[data-cw-sdgs]');
    if (!root || typeof gsap === 'undefined') return;

    var reduced =
      window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (typeof ScrollTrigger !== 'undefined') {
      gsap.registerPlugin(ScrollTrigger);
    }

    var hero = root.querySelector('[data-cw-sdgs-hero]');
    var scrubEl = root.querySelector('[data-cw-sdgs-scrub]');
    var manifesto = root.querySelector('[data-cw-sdgs-manifesto]');
    var lines = root.querySelectorAll('[data-cw-sdgs-line]');
    var stackCards = gsap.utils.toArray(root.querySelectorAll('[data-cw-sdgs-stack-card]'));
    var tiles = gsap.utils.toArray(root.querySelectorAll('[data-cw-sdgs-tile]'));
    var feature = root.querySelector('[data-cw-sdgs-feature]');
    var featureImg = root.querySelector('[data-cw-sdgs-feature-img]');
    var featureN = root.querySelector('[data-cw-sdgs-feature-n]');
    var featureName = root.querySelector('[data-cw-sdgs-feature-name]');
    var featureBlurb = root.querySelector('[data-cw-sdgs-feature-blurb]');
    var featureLink = root.querySelector('[data-cw-sdgs-feature-link]');
    var cta = root.querySelector('[data-cw-sdgs-cta]');

    wrapWords(scrubEl, 'cw-p3-word');
    wrapWords(manifesto, 'cw-p3-word');

    var heroWords = scrubEl ? scrubEl.querySelectorAll('.cw-p3-word') : [];
    var manifestoWords = manifesto ? manifesto.querySelectorAll('.cw-p3-word') : [];

    function setFeature(tile, animate) {
      if (!tile || !featureImg || !featureN || !featureName) return;
      var n = tile.getAttribute('data-n') || '';
      var label = tile.getAttribute('data-label') || '';
      var blurb = tile.getAttribute('data-blurb') || '';
      var src = tile.getAttribute('data-src') || '';
      var goalLabel = 'Goal ' + n;
      var fadeTargets = [featureImg, featureN, featureName];
      if (featureBlurb) fadeTargets.push(featureBlurb);

      tiles.forEach(function (t) {
        var on = t === tile;
        t.classList.toggle('is-active', on);
        t.setAttribute('aria-pressed', on ? 'true' : 'false');
      });

      var apply = function () {
        featureImg.src = src;
        featureImg.alt = 'SDG ' + n + ' — ' + label;
        featureN.textContent = goalLabel;
        featureName.textContent = label;
        if (featureBlurb) featureBlurb.textContent = blurb;
        if (featureLink) {
          featureLink.href = 'https://sdgs.un.org/goals/goal' + n;
        }
      };

      if (!animate || reduced) {
        apply();
        gsap.set(fadeTargets, { clearProps: 'opacity,transform' });
        return;
      }

      gsap
        .timeline({ defaults: { ease: 'power2.out' } })
        .to(fadeTargets, {
          opacity: 0,
          y: 8,
          duration: 0.18,
          stagger: 0.02,
        })
        .add(apply)
        .fromTo(
          fadeTargets,
          { opacity: 0, y: -8 },
          { opacity: 1, y: 0, duration: 0.28, stagger: 0.03 }
        );
    }

    if (reduced) {
      gsap.set([heroWords, manifestoWords], { opacity: 1 });
      gsap.set(lines, { clearProps: 'all' });
      gsap.set(stackCards, { clearProps: 'all' });
      gsap.set(tiles, { clearProps: 'all' });
      tiles.forEach(function (tile) {
        tile.addEventListener('click', function () {
          setFeature(tile, false);
        });
      });
      return;
    }

    /* Hero entrance */
    gsap.set(lines, { y: 28, opacity: 0 });
    gsap.set(stackCards, { y: 56, scale: 0.88, opacity: 0 });
    gsap.set(heroWords, { opacity: 0.14 });

    gsap
      .timeline({ defaults: { ease: 'power3.out' } })
      .to(lines, { y: 0, opacity: 1, duration: 0.7, stagger: 0.09 })
      .to(
        stackCards,
        { y: 0, scale: 1, opacity: 1, duration: 0.8, stagger: 0.11, force3D: true },
        '-=0.5'
      )
      .to(
        heroWords,
        { opacity: 1, duration: 0.45, stagger: 0.028, ease: 'power1.out' },
        '-=0.55'
      );

    /* Stack parallax */
    if (hero && stackCards.length && typeof ScrollTrigger !== 'undefined') {
      stackCards.forEach(function (card) {
        var depth = parseFloat(card.getAttribute('data-depth') || '1');
        gsap.to(card, {
          y: -36 * depth,
          scale: 1.03,
          ease: 'none',
          scrollTrigger: {
            trigger: hero,
            start: 'top top',
            end: 'bottom top',
            scrub: 0.8,
          },
        });
      });
    }

    /* Manifesto scrub */
    if (manifesto && manifestoWords.length && typeof ScrollTrigger !== 'undefined') {
      var manifestoWrap = root.querySelector('[data-cw-sdgs-manifesto-wrap]') || manifesto;
      gsap.set(manifestoWords, { opacity: 0.14 });
      gsap.to(manifestoWords, {
        opacity: 1,
        stagger: 0.06,
        ease: 'none',
        scrollTrigger: {
          trigger: manifestoWrap,
          start: 'top 75%',
          end: 'bottom 35%',
          scrub: 0.5,
          invalidateOnRefresh: true,
        },
      });
    }

    /* Goals grid + feature */
    if (tiles.length) {
      if (typeof ScrollTrigger !== 'undefined') {
        if (feature) {
          gsap.from(feature, {
            y: 24,
            opacity: 0.35,
            duration: 0.65,
            ease: 'power3.out',
            scrollTrigger: {
              trigger: feature,
              start: 'top 90%',
              once: true,
            },
          });
        }
        gsap.from(tiles, {
          y: 18,
          opacity: 0.2,
          scale: 0.94,
          duration: 0.45,
          stagger: 0.03,
          ease: 'power3.out',
          force3D: true,
          scrollTrigger: {
            trigger: root.querySelector('[data-cw-sdgs-goals]') || tiles[0],
            start: 'top 85%',
            once: true,
          },
        });
      }

      tiles.forEach(function (tile) {
        tile.addEventListener('mouseenter', function () {
          if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            setFeature(tile, true);
          }
        });
        tile.addEventListener('focus', function () {
          setFeature(tile, true);
        });
        tile.addEventListener('click', function () {
          setFeature(tile, true);
        });
      });
    }

    if (cta && typeof ScrollTrigger !== 'undefined') {
      gsap.fromTo(
        cta,
        { y: 20, opacity: 0.5 },
        {
          y: 0,
          opacity: 1,
          ease: 'none',
          scrollTrigger: {
            trigger: cta,
            start: 'top 92%',
            end: 'top 70%',
            scrub: true,
          },
        }
      );
    }

    if (typeof ScrollTrigger !== 'undefined') {
      requestAnimationFrame(function () {
        ScrollTrigger.refresh();
      });
    }
  });
})();
