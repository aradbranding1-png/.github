/* Home page UI (progressive enhancement; the page is complete without it). The 3D globe lives in trade-globe.js. */
(function () {
  'use strict';
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var digits = '۰۱۲۳۴۵۶۷۸۹';
  var NUM = window.sadtNum || 'fa-IR';
  function fa(n) {
    if (NUM !== 'fa-IR') return Number(n).toLocaleString(NUM);
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '٬').replace(/\d/g, function (d) { return digits[d]; });
  }

  // Globe safety net: if the 3D globe has not started after 9 s (browser without ES-module support, a script that
  // failed to load or parse, a stuck GPU), show the poster globe and the static country cards.
  var globeRoot = document.querySelector('[data-trade-globe]');
  if (globeRoot) {
    setTimeout(function () {
      var c = globeRoot.classList;
      if (!c.contains('tg-ready') && !c.contains('tg-fallback')) c.add('tg-fallback');
    }, 9000);
  }

  // Fallback layout (no 3D globe: projectors, old TVs): place the country cards on a ring around the poster globe,
  // skipping any spot that would cover the hero text, the buttons, the header, the side rail or another card.
  function layoutFallbackCards() {
    if (!globeRoot) return;
    if (!globeRoot.classList.contains('tg-fallback')) {
      // Back to the 3D globe (e.g. a restored GPU context): drop the static placement.
      var all = globeRoot.querySelectorAll('.tg-card[data-fb]');
      for (var z = 0; z < all.length; z++) { all[z].style.left = all[z].style.top = all[z].style.display = ''; all[z].removeAttribute('data-fb'); }
      return;
    }
    var box = globeRoot.querySelector('.tg-cards');
    var poster = globeRoot.querySelector('.tg-poster');
    if (!box || !poster) return;
    var B = box.getBoundingClientRect();
    var P = poster.getBoundingClientRect();
    if (!P.width || !B.width) return;
    var cx = P.left + P.width / 2 - B.left, cy = P.top + P.height / 2 - B.top, r = P.width / 2;
    var avoid = [];
    var nodes = document.querySelectorAll('[data-tg-avoid], [data-th-head], .th-head');
    for (var i = 0; i < nodes.length; i++) {
      var q = nodes[i].getBoundingClientRect();
      if (q.width && q.height) avoid.push({ l: q.left - B.left - 10, t: q.top - B.top - 10, r: q.right - B.left + 10, b: q.bottom - B.top + 10 });
    }
    var hit = function (a, list) {
      for (var k = 0; k < list.length; k++) {
        var o = list[k];
        if (a.l < o.r && a.r > o.l && a.t < o.b && a.b > o.t) return true;
      }
      return false;
    };
    // Angles (degrees, 0 = right, clockwise on screen), tried in this order: right side first, then top and bottom.
    var angles = [-35, 30, -75, 75, 0, -110, 110, -145, 145, 180];
    // Main markets first, then secondary ones, then route-only countries.
    var list = globeRoot.querySelectorAll('.tg-card'), cards = [];
    for (var pass = 0; pass < 3; pass++) {
      for (var n = 0; n < list.length; n++) {
        var cl = list[n].classList, rank = cl.contains('is-route') ? 2 : (cl.contains('is-secondary') ? 1 : 0);
        if (rank === pass) cards.push(list[n]);
      }
    }
    var placed = [], used = {}, max = window.innerWidth < 700 ? 3 : 6;
    for (var c = 0; c < cards.length; c++) {
      var el = cards[c];
      el.setAttribute('data-fb', '1');
      el.style.display = 'flex';
      var w = el.offsetWidth, h = el.offsetHeight, spot = null;
      for (var g = 0; g < angles.length && placed.length < max; g++) {
        if (used[g]) continue;
        var rad = angles[g] * Math.PI / 180;
        var x = cx + Math.cos(rad) * r * 0.98, y = cy + Math.sin(rad) * r * 0.98;
        var rect = { l: x - w / 2, t: y - h / 2, r: x + w / 2, b: y + h / 2 };
        if (rect.l < 6 || rect.t < 6 || rect.r > B.width - 6 || rect.b > B.height - 6) continue;
        if (hit(rect, avoid) || hit({ l: rect.l - 8, t: rect.t - 8, r: rect.r + 8, b: rect.b + 8 }, placed)) continue;
        spot = { x: x, y: y, rect: rect, g: g };
        break;
      }
      if (spot) {
        used[spot.g] = true;
        placed.push(spot.rect);
        el.style.left = Math.round(spot.x) + 'px';
        el.style.top = Math.round(spot.y) + 'px';
      } else {
        el.style.display = 'none';
      }
    }
  }
  if (globeRoot) {
    var relayout = function () { setTimeout(layoutFallbackCards, 60); };
    if (window.MutationObserver) new MutationObserver(relayout).observe(globeRoot, { attributes: true, attributeFilter: ['class'] });
    window.addEventListener('resize', relayout);
    window.addEventListener('load', relayout);
    relayout();
  }

  // Diagnostics for TVs and other odd browsers: open the home page with ?globe=debug.
  if (globeRoot && /[?&]globe=debug\b/.test(location.search)) {
    setTimeout(function () {
      var info = ['نسخه کره: 1.19.0'];
      var gl2 = null, gl1 = null, vendor = '';
      try { gl2 = document.createElement('canvas').getContext('webgl2'); } catch (e) { /* ignore */ }
      try { gl1 = document.createElement('canvas').getContext('webgl') || document.createElement('canvas').getContext('experimental-webgl'); } catch (e) { /* ignore */ }
      var g = gl2 || gl1;
      if (g) {
        var ext = g.getExtension('WEBGL_debug_renderer_info');
        vendor = ext ? g.getParameter(ext.UNMASKED_RENDERER_WEBGL) : g.getParameter(g.RENDERER);
      }
      var poster = document.querySelector('.tg-poster');
      var pr = poster ? poster.getBoundingClientRect() : { width: 0 };
      info.push('WebGL 2: ' + (gl2 ? 'دارد' : 'ندارد') + ' · WebGL 1: ' + (gl1 ? 'دارد' : 'ندارد'));
      info.push('کارت گرافیک: ' + (vendor || '—'));
      info.push('وضعیت: ' + (globeRoot.className || '—') + (window.__tgFail ? ' · علت: ' + window.__tgFail : ''));
      if (window.__tgProbe) info.push('نمونه پیکسل: ' + window.__tgProbe.drawn + ' رسم‌شده، ' + window.__tgProbe.bright + ' روشن');
      info.push('ماژول ES: ' + ('noModule' in document.createElement('script') ? 'پشتیبانی' : 'بدون پشتیبانی') + ' · cqh: ' + (window.CSS && CSS.supports && CSS.supports('width', '1cqh') ? 'دارد' : 'ندارد'));
      info.push('تصویر جایگزین: ' + Math.round(pr.width) + 'px · صفحه: ' + window.innerWidth + '×' + window.innerHeight + ' @' + (window.devicePixelRatio || 1));
      info.push(navigator.userAgent);
      var box = document.createElement('pre');
      box.className = 'tg-debug';
      box.textContent = info.join('\n');
      document.body.appendChild(box);
    }, 10000);
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

  // Market carousel: arrows step one card, mouse drag scrolls. RTL scrollLeft runs 0 → negative.
  document.querySelectorAll('.th-mk.is-carousel').forEach(function (wrap) {
    var row = wrap.querySelector('.th-markets');
    var prev = wrap.querySelector('[data-mk="prev"]');
    var next = wrap.querySelector('[data-mk="next"]');
    if (!row) return;
    var rtl = getComputedStyle(row).direction === 'rtl';
    function step() {
      var card = row.querySelector('.th-market');
      return card ? card.getBoundingClientRect().width + 12 : row.clientWidth;
    }
    function sync() {
      var max = row.scrollWidth - row.clientWidth;
      var pos = Math.abs(row.scrollLeft);
      if (prev) prev.disabled = pos <= 16;
      if (next) next.disabled = pos >= max - 16;
    }
    function go(dir) { row.scrollBy({ left: (rtl ? -1 : 1) * dir * step(), behavior: 'smooth' }); }
    if (prev) prev.addEventListener('click', function () { go(-1); });
    if (next) next.addEventListener('click', function () { go(1); });
    row.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { e.preventDefault(); go(rtl ? 1 : -1); }
      if (e.key === 'ArrowRight') { e.preventDefault(); go(rtl ? -1 : 1); }
    });
    row.addEventListener('scroll', sync, { passive: true });
    window.addEventListener('resize', sync);
    sync();

    var down = false, moved = false, x0 = 0, s0 = 0;
    row.addEventListener('pointerdown', function (e) {
      if (e.pointerType !== 'mouse' || e.button !== 0) return;
      down = true; moved = false; x0 = e.clientX; s0 = row.scrollLeft;
    });
    window.addEventListener('pointermove', function (e) {
      if (!down) return;
      var dx = e.clientX - x0;
      if (!moved && Math.abs(dx) > 5) { moved = true; row.classList.add('is-drag'); }
      if (moved) row.scrollLeft = s0 - dx;
    });
    window.addEventListener('pointerup', function () {
      if (!down) return;
      down = false;
      if (moved) {
        row.classList.remove('is-drag');
        var w = step(), snapped = Math.round(row.scrollLeft / w) * w;
        row.scrollTo({ left: snapped, behavior: 'smooth' });
      }
    });
    row.addEventListener('click', function (e) { if (moved) { e.preventDefault(); moved = false; } }, true);
    row.addEventListener('dragstart', function (e) { e.preventDefault(); });
  });
})();
