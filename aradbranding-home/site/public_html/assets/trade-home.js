/* Home page UI (progressive enhancement; the page is complete without it). The 3D globe lives in trade-globe.js. */
(function () {
  'use strict';
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var digits = '۰۱۲۳۴۵۶۷۸۹';
  function fa(n) {
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '٬').replace(/\d/g, function (d) { return digits[d]; });
  }

  // Sticky glass header gets denser once the page scrolls.
  var head = document.querySelector('[data-th-head]');
  if (head) {
    var onScroll = function () { head.classList.toggle('is-scrolled', window.scrollY > 12); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // Mobile menu (a <details>): close on navigation, outside tap or Escape.
  var menu = document.querySelector('.th-menu');
  if (menu) {
    menu.addEventListener('click', function (e) { if (e.target.closest('a')) menu.open = false; });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') menu.open = false; });
    document.addEventListener('click', function (e) { if (menu.open && !menu.contains(e.target)) menu.open = false; });
  }

  if (!('IntersectionObserver' in window)) return;

  // Count-up for the statistics strip.
  function count(el) {
    var target = parseInt(el.getAttribute('data-count'), 10) || 0;
    var plus = el.hasAttribute('data-plus') ? '+' : '';
    if (reduced || target === 0) { el.textContent = plus + fa(target); return; }
    var t0 = null;
    var dur = 1600;
    function step(t) {
      if (t0 === null) t0 = t;
      var k = Math.min(1, (t - t0) / dur);
      var e = 1 - Math.pow(1 - k, 4);
      el.textContent = plus + fa(Math.round(target * e));
      if (k < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  var seen = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      var el = entry.target;
      seen.unobserve(el);
      el.classList.add('is-in');
      el.querySelectorAll('[data-count]').forEach(count);
      el.querySelectorAll('.th-bar i[data-w]').forEach(function (bar) {
        bar.style.width = Math.max(6, Math.min(100, parseInt(bar.getAttribute('data-w'), 10) || 0)) + '%';
      });
    });
  }, { threshold: 0.18 });

  document.querySelectorAll('.th-stats, .th-discover, .th-modules, .th-sec').forEach(function (el) {
    el.classList.add('th-reveal');
    seen.observe(el);
  });
})();
