/**
 * Promo spotlight: prev/next, dots, swipe, keyboard + auto-scroll.
 */
(function () {
  'use strict';

  var SWIPE_MIN = 48;
  var AUTO_MS = 5000;

  function pauseVideos(root) {
    root.querySelectorAll('video[data-qb-promo-video]').forEach(function (v) {
      try {
        v.pause();
      } catch (e) { /* ignore */ }
    });
  }

  function initCarousel(root) {
    if (root.getAttribute('data-qb-promo-inited') === '1') return;
    root.setAttribute('data-qb-promo-inited', '1');

    var track = root.querySelector('.qb-promo-carousel__track');
    var viewport = root.querySelector('.qb-promo-carousel__viewport');
    var slides = root.querySelectorAll('.qb-promo-carousel__slide');
    var dots = root.querySelectorAll('.qb-promo-carousel__dot');
    var prevBtn = root.querySelector('.qb-promo-carousel__arrow--prev');
    var nextBtn = root.querySelector('.qb-promo-carousel__arrow--next');

    var n = slides.length;
    if (!track || !viewport || n === 0) return;

    var index = 0;
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var timer = null;

    function setTransform() {
      track.style.transform = 'translate3d(' + (-index * 100) + '%,0,0)';
    }

    function updateDots() {
      dots.forEach(function (d, i) {
        var on = i === index;
        d.classList.toggle('is-active', on);
        d.setAttribute('aria-selected', on ? 'true' : 'false');
      });
    }

    function updateA11y() {
      slides.forEach(function (s, i) {
        s.setAttribute('aria-hidden', i === index ? 'false' : 'true');
      });
    }

    function go(i) {
      index = (i % n + n) % n;
      setTransform();
      updateDots();
      updateA11y();
      pauseVideos(root);
    }

    function next() {
      go(index + 1);
    }

    function prev() {
      go(index - 1);
    }

    function clearTimer() {
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
    }

    function armTimer() {
      clearTimer();
      if (reduceMotion || n <= 1) return;
      timer = setInterval(next, AUTO_MS);
    }

    if (prevBtn) prevBtn.addEventListener('click', function () {
      prev();
      armTimer();
    });
    if (nextBtn) nextBtn.addEventListener('click', function () {
      next();
      armTimer();
    });

    dots.forEach(function (d, di) {
      d.addEventListener('click', function () {
        go(di);
        armTimer();
      });
    });

    /* Touch swipe */
    var touchStartX = 0;
    viewport.addEventListener('touchstart', function (e) {
      if (!e.changedTouches || !e.changedTouches[0]) return;
      touchStartX = e.changedTouches[0].clientX;
    }, { passive: true });

    viewport.addEventListener('touchend', function (e) {
      if (!e.changedTouches || !e.changedTouches[0]) return;
      var dx = e.changedTouches[0].clientX - touchStartX;
      if (Math.abs(dx) < SWIPE_MIN) return;
      if (dx > 0) prev(); else next();
      armTimer();
    }, { passive: true });

    /* Keyboard: any focus inside carousel root (root + viewport are focusable) */
    root.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
      var t = e.target;
      if (!root.contains(t)) return;
      if (t && t.closest && t.closest('iframe')) return;
      if (t && t.closest && t.closest('input, textarea, select, [contenteditable="true"]')) return;
      e.preventDefault();
      if (e.key === 'ArrowLeft') prev();
      else next();
      armTimer();
    });

    root.addEventListener('mouseenter', clearTimer);
    root.addEventListener('mouseleave', armTimer);
    root.addEventListener('focusin', clearTimer);
    root.addEventListener('focusout', function (e) {
      if (!root.contains(e.relatedTarget)) {
        setTimeout(armTimer, 0);
      }
    });

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) clearTimer();
      else armTimer();
    });

    if (window.matchMedia) {
      var mqRm = window.matchMedia('(prefers-reduced-motion: reduce)');
      function onReduceMotion(ev) {
        reduceMotion = ev.matches;
        if (reduceMotion) {
          clearTimer();
          track.style.transition = 'none';
        } else {
          track.style.transition = '';
          armTimer();
        }
      }
      if (mqRm.addEventListener) {
        mqRm.addEventListener('change', onReduceMotion);
      } else if (mqRm.addListener) {
        mqRm.addListener(onReduceMotion);
      }
    }

    go(0);
    armTimer();
  }

  function boot() {
    document.querySelectorAll('[data-qb-promo-carousel]').forEach(initCarousel);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
