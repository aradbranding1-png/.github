/* Panel shell: notifications dropdown and the mobile drawer. Both are <details>, so they open without JS too. */
(function () {
  'use strict';
  var pops = Array.prototype.slice.call(document.querySelectorAll('.notif, .nav-drawer, .fab'));

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
})();
