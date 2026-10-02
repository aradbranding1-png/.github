/* Panel shell: notifications dropdown and the mobile drawer. Both are <details>, so they open without JS too. */
(function () {
  'use strict';
  var pops = Array.prototype.slice.call(document.querySelectorAll('.notif, .nav-drawer, .fab, .profile'));

  function closeOthers(keep) {
    pops.forEach(function (d) { if (d !== keep) d.open = false; });
  }
  pops.forEach(function (d) {
    d.addEventListener('toggle', function () {
      if (d.open) closeOthers(d);
      document.documentElement.classList.toggle('drawer-open', !!document.querySelector('.nav-drawer[open]'));
    });
  });
  document.addEventListener('click', function (e) {
    pops.forEach(function (d) {
      if (d.open && !d.contains(e.target)) d.open = false;
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') pops.forEach(function (d) {
      if (d.open) { d.open = false; var s = d.querySelector('summary'); if (s) s.focus(); }
    });
  });
  document.querySelectorAll('.nav-drawer a').forEach(function (a) {
    a.addEventListener('click', function () { var d = a.closest('details'); if (d) d.open = false; });
  });

  // Notifications: load the latest items the first time the dropdown opens (and again after 60 s).
  var notif = document.querySelector('.notif[data-peek]');
  if (notif) {
    var body = notif.querySelector('[data-peek-body]');
    var loadedAt = 0;
    notif.addEventListener('toggle', function () {
      if (!notif.open || Date.now() - loadedAt < 60000) return;
      loadedAt = Date.now();
      fetch(notif.getAttribute('data-peek'), { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
        .then(function (r) {
          if (r.redirected) { window.location.href = r.url; throw new Error('redirect'); }
          if (!r.ok) throw new Error(r.status);
          return r.text();
        })
        .then(function (html) {
          body.innerHTML = html;
          var badge = notif.querySelector('summary .badge');
          if (badge) badge.remove();
        })
        .catch(function () {
          loadedAt = 0;
          body.innerHTML = '<div class="np-empty"><p>دریافت اعلان‌ها ممکن نشد.</p></div>';
        });
    });
  }

  // Dashboard: progress bars and a short count-up on KPI numbers.
  requestAnimationFrame(function () {
    document.querySelectorAll('.ad-bar i[data-w]').forEach(function (bar) {
      bar.style.width = Math.max(4, Math.min(100, parseInt(bar.getAttribute('data-w'), 10) || 0)) + '%';
    });
  });
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var digits = '۰۱۲۳۴۵۶۷۸۹';
  var fa = function (n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '٬').replace(/\d/g, function (d) { return digits[d]; }); };
  if (!reduced) {
    document.querySelectorAll('.ad-kpi [data-count]').forEach(function (el) {
      var target = parseInt(el.getAttribute('data-count'), 10) || 0;
      if (target < 2) return;
      var t0 = null;
      var step = function (t) {
        if (t0 === null) t0 = t;
        var k = Math.min(1, (t - t0) / 900);
        el.textContent = fa(Math.round(target * (1 - Math.pow(1 - k, 3))));
        if (k < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    });
  }

  // Theme button: automatic (device) → light → dark → automatic…
  var labels = { system: 'خودکار (مطابق دستگاه)', light: 'روشن', dark: 'تیره' };
  var root = document.documentElement;
  var themeBtn = document.querySelector('[data-theme-cycle]');
  var paintBtn = function () {
    if (!themeBtn) return;
    var m = root.getAttribute('data-theme-mode') || 'system';
    themeBtn.setAttribute('aria-label', 'حالت نمایش: ' + labels[m]);
    themeBtn.setAttribute('title', 'حالت نمایش: ' + labels[m]);
  };
  if (themeBtn) {
    paintBtn();
    themeBtn.addEventListener('click', function () {
      var order = ['system', 'light', 'dark'];
      var next = order[(order.indexOf(root.getAttribute('data-theme-mode') || 'system') + 1) % 3];
      root.setAttribute('data-theme-mode', next);
      var sys = window.__sadtSystemTheme ? window.__sadtSystemTheme() : 'light';
      root.setAttribute('data-theme', next === 'system' ? sys : next);
      try { if (next === 'system') localStorage.removeItem('sadt-theme-mode'); else localStorage.setItem('sadt-theme-mode', next); } catch (e) {}
      paintBtn();
    });
  }
})();
