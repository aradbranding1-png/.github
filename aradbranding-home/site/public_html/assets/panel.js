/* Panel shell: notifications dropdown and the mobile drawer. Both are <details>, so they open without JS too. */
(function () {
  'use strict';
  var pops = Array.prototype.slice.call(document.querySelectorAll('.notif, .nav-drawer, .fab, .profile, .theme-pick'));

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
  // Copy buttons (new API key)
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var done = function () { btn.textContent = 'کپی شد'; setTimeout(function () { btn.textContent = 'کپی'; }, 1800); };
      if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () {});
    });
  });

  // «کیف پول مشتریان»: quick amounts and a live preview of the balance after the change.
  var amount = document.querySelector('.wa-amount');
  if (amount) {
    var form = amount.closest('form');
    var preview = form.querySelector('.wa-preview');
    var balance = parseInt(amount.getAttribute('data-balance'), 10) || 0;
    var waFa = function (n) { return n.toLocaleString('fa-IR'); };
    var waDigits = function (v) {
      return parseInt(String(v).replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; })
        .replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; }).replace(/\D+/g, ''), 10) || 0;
    };
    var update = function () {
      var n = waDigits(amount.value);
      var debit = (form.querySelector('input[name="direction"]:checked') || {}).value === 'debit';
      if (!n) { preview.textContent = ''; preview.classList.remove('is-bad'); return; }
      var after = debit ? balance - n : balance + n;
      preview.textContent = (debit ? 'کسر ' : 'افزایش ') + waFa(n) + ' Star · موجودی پس از ثبت: ' + waFa(Math.max(after, 0)) + ' Star'
        + (after < 0 ? ' (موجودی کافی نیست)' : '');
      preview.classList.toggle('is-bad', after < 0);
    };
    form.querySelectorAll('[data-amount]').forEach(function (b) {
      b.addEventListener('click', function () { amount.value = b.getAttribute('data-amount'); update(); amount.focus(); });
    });
    amount.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
  }

  // «کاربران منتخب»: one trader per line; show how many are listed.
  document.querySelectorAll('.handles-box').forEach(function (box) {
    var out = box.parentNode.querySelector('.handles-count');
    if (!out) return;
    var count = function () {
      var seen = {};
      box.value.split(/[\s,،]+/).forEach(function (h) { h = h.replace(/^.*\/p\/([^\/?#]+).*$/i, '$1').replace(/^@/, '').toLowerCase(); if (h) seen[h] = 1; });
      var n = Object.keys(seen).length;
      out.textContent = n ? n.toLocaleString('fa-IR') + ' نفر' + (n > 200 ? ' (بیش از ۲۰۰ نفر؛ فقط ۲۰۰ نفر اول در نظر گرفته می‌شوند)' : '') : '';
    };
    box.addEventListener('input', count);
    count();
  });

  // Report bars: widths come from data-w (CSP: no inline styles in the markup).
  document.querySelectorAll('.rp-track i[data-w], .fin-share i[data-w]').forEach(function (i) {
    i.style.width = Math.max(0, Math.min(100, parseInt(i.getAttribute('data-w'), 10) || 0)) + '%';
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

  // Theme menu: automatic (light by day, dark at night) / light / dark.
  var labels = { system: 'خودکار (روز روشن، شب تیره)', light: 'روشن', dark: 'تیره' };
  var root = document.documentElement;
  var pick = document.querySelector('.theme-pick');
  var paint = function () {
    if (!pick) return;
    var m = root.getAttribute('data-theme-mode') || 'system';
    var sum = pick.querySelector('summary');
    sum.setAttribute('aria-label', 'حالت نمایش: ' + labels[m]);
    sum.setAttribute('title', 'حالت نمایش: ' + labels[m]);
    pick.querySelectorAll('[data-theme-set]').forEach(function (b) {
      b.setAttribute('aria-checked', b.getAttribute('data-theme-set') === m ? 'true' : 'false');
    });
  };
  if (pick) {
    paint();
    pick.querySelectorAll('[data-theme-set]').forEach(function (b) {
      b.addEventListener('click', function () {
        var next = b.getAttribute('data-theme-set');
        root.setAttribute('data-theme-mode', next);
        var sys = window.__sadtSystemTheme ? window.__sadtSystemTheme() : 'light';
        root.setAttribute('data-theme', next === 'system' ? sys : next);
        try { if (next === 'system') localStorage.removeItem('sadt-theme-mode'); else localStorage.setItem('sadt-theme-mode', next); } catch (e) {}
        paint();
        pick.open = false;
      });
    });
  }
})();
