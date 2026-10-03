/* Arad Edu — front-end behaviours (no external dependencies, CSP-friendly: no inline handlers) */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var faDigits = function (s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
  var fmt = function (n) { n = Number(n) || 0; var s = Math.abs(n) >= 1000 ? n.toLocaleString('en-US', { maximumFractionDigits: 0 }) : (Math.round(n * 10) / 10).toString(); return faDigits(s); };

  function post(url, data) {
    var fd = new FormData();
    fd.append('_token', csrf);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
  }
  function toast(msg) {
    var w = $('.toast-wrap'); if (!w) { w = document.createElement('div'); w.className = 'toast-wrap'; document.body.appendChild(w); }
    var t = document.createElement('div'); t.className = 'toast'; t.textContent = msg; w.appendChild(t);
    setTimeout(function () { t.remove(); }, 3500);
  }
  window.appToast = toast;

  // ---------------------------------------------------------------- navigation & theme
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-nav-toggle]');
    if (t) { document.body.classList.toggle('nav-open'); return; }
    if (e.target.classList && e.target.classList.contains('overlay')) document.body.classList.remove('nav-open');
    var th = e.target.closest('[data-theme-toggle]');
    if (th) {
      var root = document.documentElement;
      var dark = root.getAttribute('data-theme') === 'dark' || (!root.getAttribute('data-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches);
      root.setAttribute('data-theme', dark ? 'light' : 'dark');
      try { localStorage.setItem('theme', dark ? 'light' : 'dark'); } catch (x) {}
    }
    var cp = e.target.closest('[data-copy]');
    if (cp) { e.preventDefault(); navigator.clipboard && navigator.clipboard.writeText(cp.getAttribute('data-copy')).then(function () { toast('کپی شد'); }); }
    var pr = e.target.closest('[data-print]');
    if (pr) { e.preventDefault(); window.print(); }
    var op = e.target.closest('[data-open]');
    if (op) { e.preventDefault(); var d = document.getElementById(op.getAttribute('data-open')); if (d && d.showModal) d.showModal(); }
    var cl = e.target.closest('[data-close]');
    if (cl) { e.preventDefault(); var dl = cl.closest('dialog'); if (dl) dl.close(); }
    // close other open dropdowns
    $$('details.dropdown[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
    // backdrop / close button of a header dropdown (bottom sheet on phones)
    var ddc = e.target.closest('[data-dd-close]');
    if (ddc) { e.preventDefault(); var dd = ddc.closest('details.dropdown'); if (dd) dd.removeAttribute('open'); }
  });
  try { var saved = localStorage.getItem('theme'); if (saved) document.documentElement.setAttribute('data-theme', saved); } catch (x) {}

  // confirm dialogs
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var msg = f.getAttribute('data-confirm') || (e.submitter && e.submitter.getAttribute('data-confirm'));
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    if (f.hasAttribute('data-busy') || true) {
      var b = e.submitter || $('button[type=submit]', f);
      if (b && !f.hasAttribute('data-no-busy')) { setTimeout(function () { b.disabled = true; b.classList.add('disabled'); }, 10); }
    }
  });
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-confirm]');
    if (a && !window.confirm(a.getAttribute('data-confirm'))) e.preventDefault();
  });

  // auto-submit selects
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-autosubmit]')) e.target.form.submit();
  });

  // flashes fade
  setTimeout(function () { $$('.alert[data-autohide]').forEach(function (a) { a.style.transition = 'opacity .6s'; a.style.opacity = '0'; setTimeout(function () { a.remove(); }, 700); }); }, 6000);

  // ---------------------------------------------------------------- charts (SVG)
  var PALETTE = ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#0ea5e9', '#8b5cf6', '#ec4899', '#14b8a6', '#84cc16', '#f97316'];
  function svg(tag, attrs) { var el = document.createElementNS('http://www.w3.org/2000/svg', tag); for (var k in attrs) el.setAttribute(k, attrs[k]); return el; }
  function niceMax(v) { if (v <= 0) return 4; var p = Math.pow(10, Math.floor(Math.log10(v))); var n = v / p; var m = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10; return m * p; }

  function renderChart(el) {
    var cfg; try { cfg = JSON.parse(el.getAttribute('data-chart')); } catch (x) { return; }
    el.innerHTML = '';
    var tip = document.createElement('div'); tip.className = 'chart-tip'; el.appendChild(tip);
    var showTip = function (evt, text) { var r = el.getBoundingClientRect(); tip.textContent = text; tip.style.left = (evt.clientX - r.left) + 'px'; tip.style.top = (evt.clientY - r.top) + 'px'; tip.style.opacity = 1; };
    var hideTip = function () { tip.style.opacity = 0; };
    var type = cfg.type || 'bar';
    var labels = cfg.labels || [];
    var series = cfg.series || [];
    if (type === 'donut') return donut(el, cfg, showTip, hideTip);
    var W = Math.max(el.clientWidth, 280), H = parseInt(el.getAttribute('data-height') || 240, 10);
    var pad = { t: 16, r: 8, b: 34, l: 36 };
    var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, preserveAspectRatio: 'none' });
    var max = 0;
    series.forEach(function (se) { se.data.forEach(function (v) { if (cfg.stacked) return; max = Math.max(max, Number(v) || 0); }); });
    if (cfg.stacked) labels.forEach(function (_, i) { var t = 0; series.forEach(function (se) { t += Number(se.data[i]) || 0; }); max = Math.max(max, t); });
    max = cfg.max || niceMax(max);
    var cw = W - pad.l - pad.r, ch = H - pad.t - pad.b;
    // grid
    for (var g = 0; g <= 4; g++) {
      var y = pad.t + ch - (ch * g / 4);
      s.appendChild(svg('line', { x1: pad.l, x2: W - pad.r, y1: y, y2: y, class: 'ax', 'stroke-dasharray': g ? '3 4' : '' }));
      var tl = svg('text', { x: pad.l - 6, y: y + 3, class: 'lbl', 'text-anchor': 'end' }); tl.textContent = fmt(max * g / 4); s.appendChild(tl);
    }
    var n = labels.length || 1, step = cw / n;
    var every = Math.ceil(n / Math.max(1, Math.floor(cw / 46)));
    labels.forEach(function (lb, i) {
      if (i % every) return;
      var x = pad.l + step * i + step / 2;
      var t = svg('text', { x: x, y: H - pad.b + 16, class: 'lbl', 'text-anchor': 'middle' }); t.textContent = lb; s.appendChild(t);
    });
    if (type === 'bar') {
      var gw = step * 0.66, bw = cfg.stacked ? gw : gw / series.length;
      labels.forEach(function (lb, i) {
        var acc = 0;
        series.forEach(function (se, k) {
          var v = Number(se.data[i]) || 0, h = ch * v / max;
          var x = pad.l + step * i + (step - gw) / 2 + (cfg.stacked ? 0 : bw * k);
          var y = pad.t + ch - h - (cfg.stacked ? ch * acc / max : 0);
          var r = svg('rect', { x: x, y: y, width: Math.max(bw - 2, 2), height: Math.max(h, 0), rx: 4, fill: se.color || PALETTE[k % 10] });
          r.addEventListener('mousemove', function (e) { showTip(e, (se.name ? se.name + ' — ' : '') + lb + ': ' + fmt(v)); });
          r.addEventListener('mouseleave', hideTip);
          s.appendChild(r);
          acc += v;
        });
      });
    } else {
      series.forEach(function (se, k) {
        var color = se.color || PALETTE[k % 10];
        var pts = se.data.map(function (v, i) { return [pad.l + step * i + step / 2, pad.t + ch - ch * (Number(v) || 0) / max]; });
        if (!pts.length) return;
        var d = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' ');
        var gid = 'g' + Math.random().toString(36).slice(2);
        var defs = svg('defs', {}); var lg = svg('linearGradient', { id: gid, x1: 0, y1: 0, x2: 0, y2: 1 });
        lg.appendChild(svg('stop', { offset: '0%', 'stop-color': color, 'stop-opacity': .28 })); lg.appendChild(svg('stop', { offset: '100%', 'stop-color': color, 'stop-opacity': 0 }));
        defs.appendChild(lg); s.appendChild(defs);
        s.appendChild(svg('path', { d: d + ' L' + pts[pts.length - 1][0] + ' ' + (pad.t + ch) + ' L' + pts[0][0] + ' ' + (pad.t + ch) + ' Z', fill: 'url(#' + gid + ')' }));
        s.appendChild(svg('path', { d: d, fill: 'none', stroke: color, 'stroke-width': 2.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
        pts.forEach(function (p, i) {
          var c = svg('circle', { cx: p[0], cy: p[1], r: 3.5, fill: '#fff', stroke: color, 'stroke-width': 2 });
          c.addEventListener('mousemove', function (e) { showTip(e, (se.name ? se.name + ' — ' : '') + labels[i] + ': ' + fmt(se.data[i])); });
          c.addEventListener('mouseleave', hideTip);
          s.appendChild(c);
        });
      });
    }
    el.appendChild(s);
    if (series.length > 1 || cfg.legend) legend(el, series.map(function (se, k) { return { name: se.name, color: se.color || PALETTE[k % 10] }; }));
  }
  function donut(el, cfg, showTip, hideTip) {
    var vals = cfg.values || [], labels = cfg.labels || [], total = vals.reduce(function (a, b) { return a + (Number(b) || 0); }, 0);
    var S = 170, R = 70, r = 46, cx = S / 2, cy = S / 2;
    var s = svg('svg', { viewBox: '0 0 ' + S + ' ' + S });
    var a0 = -Math.PI / 2;
    if (!total) { s.appendChild(svg('circle', { cx: cx, cy: cy, r: (R + r) / 2, fill: 'none', stroke: 'var(--surface-3)', 'stroke-width': R - r })); }
    vals.forEach(function (v, i) {
      v = Number(v) || 0; if (!v) return;
      var a1 = a0 + (v / total) * Math.PI * 2 - 0.0001;
      var large = (a1 - a0) > Math.PI ? 1 : 0;
      var p = ['M', cx + R * Math.cos(a0), cy + R * Math.sin(a0), 'A', R, R, 0, large, 1, cx + R * Math.cos(a1), cy + R * Math.sin(a1), 'L', cx + r * Math.cos(a1), cy + r * Math.sin(a1), 'A', r, r, 0, large, 0, cx + r * Math.cos(a0), cy + r * Math.sin(a0), 'Z'].join(' ');
      var color = (cfg.colors && cfg.colors[i]) || PALETTE[i % 10];
      var path = svg('path', { d: p, fill: color });
      path.addEventListener('mousemove', function (e) { showTip(e, labels[i] + ': ' + fmt(v) + ' (' + faDigits(Math.round(v * 100 / total)) + '٪)'); });
      path.addEventListener('mouseleave', hideTip);
      s.appendChild(path);
      a0 = a1 + 0.0001;
    });
    var t = svg('text', { x: cx, y: cy + 2, 'text-anchor': 'middle', class: 'val', style: 'font-size:22px;fill:var(--text)' }); t.textContent = fmt(total); s.appendChild(t);
    var t2 = svg('text', { x: cx, y: cy + 20, 'text-anchor': 'middle', class: 'lbl' }); t2.textContent = cfg.center || 'مجموع'; s.appendChild(t2);
    el.appendChild(s);
    var lgEl = el.parentNode.querySelector('.legend[data-for]');
    var items = labels.map(function (l, i) { return { name: l + ' (' + fmt(vals[i]) + ')', color: (cfg.colors && cfg.colors[i]) || PALETTE[i % 10] }; });
    if (lgEl) { lgEl.innerHTML = ''; items.forEach(function (it) { var sp = document.createElement('span'); var ic = document.createElement('i'); ic.style.background = it.color; sp.appendChild(ic); sp.appendChild(document.createTextNode(it.name)); lgEl.appendChild(sp); }); }
  }
  function legend(el, items) {
    var d = document.createElement('div'); d.className = 'legend';
    items.forEach(function (it) { var sp = document.createElement('span'); var ic = document.createElement('i'); ic.style.background = it.color; sp.appendChild(ic); sp.appendChild(document.createTextNode(it.name || '')); d.appendChild(sp); });
    el.appendChild(d);
  }
  function renderAll() { $$('[data-chart]').forEach(renderChart); }
  renderAll();
  var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(renderAll, 200); });

  // ---------------------------------------------------------------- installer: pretty URL detection
  var rw = $('[data-rewrite-test]');
  if (rw) {
    fetch(rw.getAttribute('data-rewrite-test'), { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (t) {
      var ok = t.trim() === 'rewrite-ok'; var inp = $('input[name=pretty]'); if (inp) inp.value = ok ? '1' : '0';
      rw.textContent = ok ? 'فعال (آدرس‌های تمیز)' : 'غیرفعال — از آدرس index.php?r= استفاده می‌شود';
      rw.className = 'badge ' + (ok ? 'badge-success' : 'badge-warning');
    }).catch(function () {});
  }

  // ---------------------------------------------------------------- permission matrix helpers
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.matches('[data-check-row]')) {
      $$('input[type=checkbox][data-row="' + t.getAttribute('data-check-row') + '"]:not(:disabled)').forEach(function (c) { c.checked = t.checked; });
    }
    if (t.matches('[data-check-col]')) {
      $$('input[type=checkbox][data-col="' + t.getAttribute('data-check-col') + '"]:not(:disabled)').forEach(function (c) { c.checked = t.checked; });
    }
    if (t.matches('[data-check-all]')) {
      $$('.matrix input[type=checkbox]:not(:disabled)').forEach(function (c) { c.checked = t.checked; });
    }
    if (t.matches('.perm-3state select')) { t.className = t.value; }
    if (t.matches('[data-toggle-target]')) {
      var sel = t.value;
      $$('[data-target-box]').forEach(function (b) { b.classList.toggle('hide', b.getAttribute('data-target-box') !== sel); $$('select,input', b).forEach(function (i) { i.disabled = b.getAttribute('data-target-box') !== sel; }); });
    }
    if (t.matches('[data-qtype]')) qtypeUI(t.value);
    if (t.matches('[data-content-type]')) ctypeUI(t.value);
  });
  $$('.perm-3state select').forEach(function (s) { s.className = s.value; });
  var tt = $('[data-toggle-target]'); if (tt) tt.dispatchEvent(new Event('change', { bubbles: true }));

  // matrix search filter
  var ms = $('[data-matrix-filter]');
  if (ms) ms.addEventListener('input', function () {
    var q = ms.value.trim();
    $$('.matrix tbody tr[data-label]').forEach(function (tr) { tr.classList.toggle('hide', q && tr.getAttribute('data-label').indexOf(q) === -1); });
  });

  // ---------------------------------------------------------------- question editor
  function qtypeUI(v) {
    var box = $('[data-options-box]'); if (!box) return;
    box.classList.toggle('hide', v === 'essay');
    var multi = v === 'multiple';
    $$('[data-opt-correct]').forEach(function (i) { i.type = multi ? 'checkbox' : (v === 'short' ? 'hidden' : 'radio'); });
    $$('.short-hint').forEach(function (h) { h.classList.toggle('hide', v !== 'short'); });
    if (v === 'truefalse') {
      var rows = $$('[data-opt-row]');
      if (rows.length < 2) addOpt(); if ($$('[data-opt-row]').length < 2) addOpt();
      var ins = $$('[data-opt-row] input[type=text]');
      if (ins[0] && !ins[0].value) ins[0].value = 'درست'; if (ins[1] && !ins[1].value) ins[1].value = 'غلط';
    }
  }
  function addOpt() {
    var tpl = $('#opt-template'), list = $('[data-options-list]'); if (!tpl || !list) return;
    var idx = Date.now() + Math.floor(Math.random() * 1000);
    var html = tpl.innerHTML.replace(/__i__/g, idx);
    var div = document.createElement('div'); div.innerHTML = html.trim();
    list.appendChild(div.firstChild);
    var sel = $('[data-qtype]'); if (sel) qtypeUI(sel.value);
  }
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-add-option]')) { e.preventDefault(); addOpt(); }
    var rm = e.target.closest('[data-remove-row]');
    if (rm) { e.preventDefault(); var row = rm.closest('[data-row-item]'); if (row) row.remove(); }
    var ad = e.target.closest('[data-add-row]');
    if (ad) {
      e.preventDefault();
      var tpl = document.getElementById(ad.getAttribute('data-add-row')), list = $(ad.getAttribute('data-into'));
      if (tpl && list) { var d = document.createElement('div'); d.innerHTML = tpl.innerHTML.replace(/__i__/g, Date.now()).trim(); list.appendChild(d.firstChild); }
    }
  });
  var qt = $('[data-qtype]'); if (qt) qtypeUI(qt.value);

  function ctypeUI(v) {
    $$('[data-ctype-box]').forEach(function (b) { var list = b.getAttribute('data-ctype-box').split(','); b.classList.toggle('hide', list.indexOf(v) === -1); });
  }
  var ct = $('[data-content-type]'); if (ct) ctypeUI(ct.value);

  // ---------------------------------------------------------------- lesson player: progress, resume, heartbeat
  var player = $('[data-lesson]');
  if (player) {
    var progressUrl = player.getAttribute('data-progress-url');
    var need = parseInt(player.getAttribute('data-need') || '90', 10);
    var btn = $('[data-complete-btn]');
    var media = $('video[data-track], audio[data-track]');
    var lastSent = 0, maxPct = parseInt(player.getAttribute('data-pct') || '0', 10);
    if (media) {
      var resume = parseInt(media.getAttribute('data-resume') || '0', 10);
      media.addEventListener('loadedmetadata', function () { if (resume > 5 && resume < media.duration - 5) media.currentTime = resume; });
      media.addEventListener('timeupdate', function () {
        if (!media.duration) return;
        var pct = Math.floor(media.currentTime * 100 / media.duration);
        if (pct > maxPct) maxPct = pct;
        if (maxPct >= need && btn) btn.disabled = false, btn.classList.remove('disabled');
        var now = Date.now();
        if (now - lastSent > 15000) { lastSent = now; post(progressUrl, { position: Math.floor(media.currentTime), percent: maxPct }); }
      });
      media.addEventListener('pause', function () { post(progressUrl, { position: Math.floor(media.currentTime), percent: maxPct }); });
      if (media.hasAttribute('data-remote')) {
        // external file could not be played (blocked by host, deleted, unsupported codec):
        // show a direct link and don't keep the learner stuck behind the watch requirement
        media.addEventListener('error', function () {
          var box = $('[data-remote-err]'); if (box) box.classList.remove('hide');
          if (btn) { btn.disabled = false; btn.classList.remove('disabled'); }
        });
      }
      media.addEventListener('ended', function () { maxPct = 100; post(progressUrl, { position: 0, percent: 100 }); if (btn) { btn.disabled = false; btn.classList.remove('disabled'); } });
    }
    // time-on-page heartbeat (only while tab is visible)
    var hb = player.getAttribute('data-heartbeat-url');
    if (hb) setInterval(function () { if (!document.hidden) post(hb, { seconds: 60 }); }, 60000);
  }

  // ---------------------------------------------------------------- exam timer & navigator
  var timer = $('[data-deadline]');
  if (timer) {
    var deadline = parseInt(timer.getAttribute('data-deadline'), 10) * 1000;
    var skew = Date.now() - parseInt(timer.getAttribute('data-now'), 10) * 1000;
    var out = $('[data-timer-text]', timer);
    var form = $('#exam-form');
    var tick = function () {
      var left = Math.max(0, Math.floor((deadline - (Date.now() - skew)) / 1000));
      var m = Math.floor(left / 60), s = left % 60;
      out.textContent = faDigits((m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s);
      timer.classList.toggle('low', left < 60);
      if (left <= 0) { clearInterval(iv); if (form && !form.dataset.sent) { form.dataset.sent = 1; form.removeAttribute('data-confirm'); form.submit(); } }
    };
    var iv = setInterval(tick, 1000); tick();
  }
  var examForm = $('#exam-form');
  if (examForm) {
    var mark = function () {
      $$('[data-q]', examForm).forEach(function (q) {
        var id = q.getAttribute('data-q');
        var answered = $$('input:checked', q).length > 0 || $$('textarea,input[type=text]', q).some(function (t) { return t.value.trim() !== ''; });
        var nav = $('.q-nav a[href="#q' + id + '"]'); if (nav) nav.classList.toggle('answered', answered);
      });
    };
    examForm.addEventListener('change', mark); examForm.addEventListener('input', mark); mark();
    window.addEventListener('beforeunload', function (e) { if (!examForm.dataset.sent) { e.preventDefault(); e.returnValue = ''; } });
    examForm.addEventListener('submit', function () { examForm.dataset.sent = 1; });
  }

  // ---------------------------------------------------------------- notifications: mark read when dropdown opened
  var nd = $('details[data-notif]');
  if (nd) nd.addEventListener('toggle', function () {
    if (nd.open && nd.getAttribute('data-unread') !== '0') { post(nd.getAttribute('data-read-url'), {}); var dot = $('.dot', nd); if (dot) dot.remove(); nd.setAttribute('data-unread', '0'); }
  });

  // ---------------------------------------------------------------- live user search (select filtering)
  $$('[data-filter-select]').forEach(function (inp) {
    var sel = document.getElementById(inp.getAttribute('data-filter-select'));
    if (!sel) return;
    inp.addEventListener('input', function () {
      var q = inp.value.trim();
      Array.prototype.forEach.call(sel.options, function (o) { o.hidden = q && o.text.indexOf(q) === -1 && !o.selected; });
    });
  });
})();

// log viewer quick filter
(function () {
  var inp = document.querySelector('[data-filter-log]');
  if (!inp) return;
  inp.addEventListener('input', function () {
    var q = inp.value.trim().toUpperCase();
    Array.prototype.forEach.call(document.querySelectorAll('.code-block > div'), function (d) { d.style.display = !q || d.textContent.toUpperCase().indexOf(q) !== -1 ? '' : 'none'; });
  });
})();

/* ---------------------------------------------------------------- PWA: service worker + install */
(function () {
  'use strict';
  var swMeta = document.querySelector('meta[name="sw-url"]');
  if ('serviceWorker' in navigator && swMeta && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(swMeta.content).catch(function () { /* PWA is optional */ });
    });
  }

  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (standalone) return;

  var ua = navigator.userAgent || '';
  var isIOS = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var isMobile = isIOS || /android|mobile/i.test(ua);
  var deferred = null;
  var btns = Array.prototype.slice.call(document.querySelectorAll('[data-pwa-install]'));
  var iconEl = document.querySelector('link[rel="apple-touch-icon"]');
  var iconUrl = iconEl ? iconEl.href : '';
  var KEY = 'pwa-dismissed-at';

  function dismissedRecently() {
    try { var t = +localStorage.getItem(KEY); return t && (Date.now() - t) < 14 * 864e5; } catch (x) { return false; }
  }
  function remember() { try { localStorage.setItem(KEY, String(Date.now())); } catch (x) {} }
  function closeBanner() { var b = document.querySelector('.pwa-banner'); if (b) b.remove(); }

  function banner(title, sub, withInstall) {
    closeBanner();
    var b = document.createElement('div'); b.className = 'pwa-banner'; b.setAttribute('role', 'dialog');
    if (iconUrl) { var im = document.createElement('img'); im.src = iconUrl; im.alt = ''; b.appendChild(im); }
    var tx = document.createElement('div');
    var t = document.createElement('div'); t.className = 'pb-t'; t.textContent = title;
    var s = document.createElement('div'); s.className = 'pb-s'; s.innerHTML = sub; // static strings only
    tx.appendChild(t); tx.appendChild(s); b.appendChild(tx);
    var a = document.createElement('div'); a.className = 'pb-a';
    if (withInstall) { var go = document.createElement('button'); go.type = 'button'; go.className = 'pb-go'; go.textContent = 'نصب'; go.addEventListener('click', install); a.appendChild(go); }
    var no = document.createElement('button'); no.type = 'button'; no.className = 'pb-no'; no.textContent = withInstall ? 'بعداً' : 'باشه';
    no.addEventListener('click', function () { remember(); closeBanner(); });
    a.appendChild(no); b.appendChild(a);
    document.body.appendChild(b);
  }

  var SHARE_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" x2="12" y1="2" y2="15"/></svg>';
  function iosHelp() {
    banner('نصب روی آیفون', 'در Safari دکمه اشتراک‌گذاری ' + SHARE_SVG + ' را بزنید و گزینه <bdi dir="ltr">Add to Home Screen</bdi> را انتخاب کنید.', false);
  }

  function install() {
    if (deferred) {
      deferred.prompt();
      deferred.userChoice.then(function () { deferred = null; closeBanner(); btns.forEach(function (b) { b.hidden = true; }); });
    } else if (isIOS) {
      iosHelp();
    }
  }
  btns.forEach(function (b) { b.addEventListener('click', install); });

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    btns.forEach(function (b) { b.hidden = false; });
    if (isMobile && !dismissedRecently()) banner('اپلیکیشن آموزش آراد', 'سامانه را روی گوشی نصب کنید تا مثل یک برنامه، سریع باز شود.', true);
  });
  window.addEventListener('appinstalled', function () { closeBanner(); btns.forEach(function (b) { b.hidden = true; }); });

  if (isIOS) {
    btns.forEach(function (b) { b.hidden = false; });
    if (!dismissedRecently() && /safari/i.test(ua) && !/crios|fxios|edgios/i.test(ua)) setTimeout(iosHelp, 2500);
  }
})();

/* ---------------------------------------------------------------- Persian digits in numeric fields
 * Browsers reject Persian/Arabic digits (۰-۹) in <input type="number"> and silently submit an empty
 * value. Numeric fields are switched to text + numeric keyboard, and typed digits are converted. */
(function () {
  'use strict';
  var map = { '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
              '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9', '٫': '.', '،': '' };
  function toLatin(s) { return String(s).replace(/[۰-۹٠-٩٫،]/g, function (c) { return map[c]; }); }
  function convert(inp) {
    if (inp.dataset.numFixed) return;
    inp.dataset.numFixed = '1';
    var step = inp.getAttribute('step');
    inp.type = 'text';
    inp.setAttribute('inputmode', step && step !== '1' ? 'decimal' : 'numeric');
    inp.setAttribute('autocomplete', 'off');
    inp.classList.add('ltr');
  }
  document.querySelectorAll('input[type="number"]').forEach(convert);
  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.dataset || !t.dataset.numFixed) return;
    var v = toLatin(t.value).replace(/[^0-9.\-]/g, '');
    if (v !== t.value) { var pos = t.selectionStart; t.value = v; try { t.setSelectionRange(pos, pos); } catch (x) {} }
  });
})();

/* ---------------------------------------------------------------- credit type tabs (user page) */
(function () {
  'use strict';
  var tabs = document.querySelector('[data-credit-tabs]');
  if (!tabs) return;
  tabs.addEventListener('click', function (e) {
    var a = e.target.closest('[data-ct]'); if (!a) return;
    e.preventDefault();
    tabs.querySelectorAll('[data-ct]').forEach(function (x) { x.classList.toggle('active', x === a); });
    document.querySelectorAll('[data-ct-form]').forEach(function (f) { f.classList.toggle('hide', f.getAttribute('data-ct-form') !== a.getAttribute('data-ct')); });
  });
})();

/* ---------------------------------------------------------------- upload progress
 * Forms with files are sent with XMLHttpRequest so we can show a progress bar,
 * speed and remaining time, warn before leaving the page and allow a retry. */
(function () {
  'use strict';
  var maxMeta = document.querySelector('meta[name="upload-max"]');
  var maxBytes = maxMeta ? parseInt(maxMeta.content, 10) || 0 : 0;
  var fa = function (s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
  var mb = function (b) { return fa((b / 1048576).toFixed(b >= 104857600 ? 0 : 1)); };
  var dur = function (s) { s = Math.max(0, Math.round(s)); if (s < 60) return fa(s) + ' ثانیه'; var m = Math.floor(s / 60); return fa(m) + ' دقیقه' + (s % 60 ? ' و ' + fa(s % 60) + ' ثانیه' : ''); };
  var busy = false;
  window.addEventListener('beforeunload', function (e) { if (busy) { e.preventDefault(); e.returnValue = ''; } });

  function overlay() {
    var o = document.createElement('div');
    o.className = 'up-overlay';
    o.innerHTML = '<div class="up-box" role="dialog" aria-live="polite">' +
      '<div class="up-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg></div>' +
      '<h3 class="up-title">در حال آپلود فایل…</h3><div class="up-files"></div>' +
      '<div class="up-bar"><span></span></div>' +
      '<div class="up-row"><b class="up-pct">۰٪</b><span class="up-info"></span></div>' +
      '<div class="up-note">تا پایان آپلود این صفحه را نبندید و از آن خارج نشوید.</div>' +
      '<div class="up-actions"><button type="button" class="btn btn-outline btn-sm up-cancel">انصراف</button></div></div>';
    document.body.appendChild(o);
    return o;
  }

  function send(form, fd, files, total) {
    var o = overlay();
    var bar = o.querySelector('.up-bar span'), pct = o.querySelector('.up-pct'), info = o.querySelector('.up-info');
    o.querySelector('.up-files').textContent = files.map(function (f) { return f.name; }).join('، ') + ' — ' + mb(total) + ' مگابایت';
    var xhr = new XMLHttpRequest();
    var t0 = Date.now(), lastT = t0, lastL = 0, speed = 0;
    busy = true;
    xhr.open((form.getAttribute('method') || 'POST').toUpperCase(), form.action);
    xhr.setRequestHeader('X-Upload-XHR', '1');
    xhr.upload.onprogress = function (e) {
      if (!e.lengthComputable) return;
      var p = Math.min(100, Math.floor(e.loaded * 100 / e.total));
      bar.style.width = p + '%'; pct.textContent = fa(p) + '٪';
      var now = Date.now();
      if (now - lastT > 700) { var s = (e.loaded - lastL) / ((now - lastT) / 1000); speed = speed ? speed * 0.6 + s * 0.4 : s; lastT = now; lastL = e.loaded; }
      var left = speed > 0 ? (e.total - e.loaded) / speed : 0;
      info.textContent = mb(e.loaded) + ' از ' + mb(e.total) + ' مگابایت' + (speed ? ' · ' + mb(speed) + ' مگابایت/ثانیه · حدود ' + dur(left) + ' مانده' : '');
    };
    xhr.upload.onload = function () {
      bar.style.width = '100%'; pct.textContent = '۱۰۰٪';
      o.querySelector('.up-title').textContent = 'آپلود کامل شد؛ در حال ذخیره روی سرور…';
      info.textContent = ''; o.querySelector('.up-cancel').disabled = true; o.classList.add('is-processing');
    };
    xhr.onload = function () {
      busy = false;
      var ct = xhr.getResponseHeader('Content-Type') || '';
      if (ct.indexOf('application/json') !== -1) {
        try { var j = JSON.parse(xhr.responseText); if (j.redirect) {
          // same page with only a different #hash would not reload (flash message would be lost)
          var to = new URL(j.redirect, location.href);
          if (to.pathname === location.pathname && to.search === location.search) { history.replaceState(null, '', to.href); location.reload(); }
          else window.location.href = to.href;
          return;
        } } catch (x) {}
      }
      // an error page (validation, permission, server error): show it as a normal page
      document.open(); document.write(xhr.responseText); document.close();
    };
    xhr.onerror = function () { fail('ارتباط اینترنت قطع شد یا سرور پاسخ نداد.'); };
    xhr.ontimeout = function () { fail('زمان ارسال به پایان رسید.'); };
    o.querySelector('.up-cancel').addEventListener('click', function () { xhr.abort(); busy = false; o.remove(); });
    function fail(msg) {
      busy = false; o.classList.add('is-error');
      o.querySelector('.up-title').textContent = 'آپلود کامل نشد';
      info.textContent = msg + ' فایل‌ها و اطلاعات فرم حفظ شده‌اند؛ دوباره تلاش کنید.';
      var a = o.querySelector('.up-actions');
      a.innerHTML = '';
      var retry = document.createElement('button'); retry.type = 'button'; retry.className = 'btn btn-grad btn-sm'; retry.textContent = 'تلاش دوباره';
      retry.addEventListener('click', function () { o.remove(); send(form, fd, files, total); });
      var close = document.createElement('button'); close.type = 'button'; close.className = 'btn btn-outline btn-sm'; close.textContent = 'بستن';
      close.addEventListener('click', function () { o.remove(); });
      a.appendChild(retry); a.appendChild(close);
    }
    xhr.send(fd);
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (e.defaultPrevented || !form || form.enctype !== 'multipart/form-data' || !window.FormData || !window.XMLHttpRequest) return;
    var files = [];
    Array.prototype.forEach.call(form.querySelectorAll('input[type=file]'), function (i) { Array.prototype.forEach.call(i.files || [], function (f) { files.push(f); }); });
    if (!files.length) return; // nothing to upload: normal submit
    var total = files.reduce(function (s, f) { return s + f.size; }, 0);
    e.preventDefault();
    if (maxBytes && total > maxBytes) {
      alert('حجم فایل(ها) ' + mb(total) + ' مگابایت است و بیشتر از حداکثر مجاز سرور (' + mb(maxBytes) + ' مگابایت) است. فایل کوچک‌تری انتخاب کنید یا حداکثر حجم را در تنظیمات افزایش دهید.');
      return;
    }
    var fd;
    try { fd = new FormData(form, e.submitter || undefined); } catch (x) { fd = new FormData(form); if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value); }
    send(form, fd, files, total);
  });
})();

/* ---------------------------------------------------------------- audio player
 * Replaces the browser's audio controls with a themed player: play/pause, ±15s,
 * seek bar, speed (0.75×–2×, remembered), volume and mute. The <audio> element stays
 * in the page, so progress tracking and resume keep working. */
(function () {
  'use strict';
  var fa = function (s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
  var fmt = function (t) { if (!isFinite(t) || t < 0) t = 0; t = Math.floor(t); var h = Math.floor(t / 3600), m = Math.floor(t % 3600 / 60), s = t % 60; return fa((h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (s < 10 ? '0' : '') + s); };
  var I = {
    play: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.5.86l11-6.86a1 1 0 0 0 0-1.72l-11-6.86A1 1 0 0 0 8 5.14z"/></svg>',
    pause: '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16" rx="1.2"/><rect x="14" y="4" width="4" height="16" rx="1.2"/></svg>',
    back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><text x="12" y="15.5" font-size="7" text-anchor="middle" fill="currentColor" stroke="none" font-family="sans-serif">15</text></svg>',
    fwd: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/><text x="12" y="15.5" font-size="7" text-anchor="middle" fill="currentColor" stroke="none" font-family="sans-serif">15</text></svg>',
    vol: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a10 10 0 0 1 0 14"/></svg>',
    mute: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="22" x2="16" y1="9" y2="15"/><line x1="16" x2="22" y1="9" y2="15"/></svg>',
    music: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>'
  };
  var SPEEDS = [0.75, 1, 1.25, 1.5, 2];
  var store = { get: function (k) { try { return localStorage.getItem(k); } catch (x) { return null; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (x) {} } };

  Array.prototype.forEach.call(document.querySelectorAll('audio[data-track], audio[data-player]'), function (a) {
    if (a.dataset.playerReady) return;
    a.dataset.playerReady = '1';
    var title = a.getAttribute('data-title') || document.title.split('|')[0].trim();
    var host = a.closest('.card') || a.parentNode;
    var p = document.createElement('div');
    p.className = 'ap';
    p.innerHTML =
      '<div class="ap-head"><span class="ap-art">' + I.music + '<i></i><i></i><i></i></span><div class="ap-meta"><b></b><span class="ap-status">آماده پخش</span></div></div>' +
      '<div class="ap-seek" dir="ltr"><span class="ap-t ap-cur">۰:۰۰</span><div class="ap-track"><div class="ap-buf"></div><div class="ap-fill"></div><input type="range" class="ap-range" min="0" max="1000" value="0" aria-label="پیشرفت"></div><span class="ap-t ap-dur">—</span></div>' +
      '<div class="ap-ctrl">' +
        '<button type="button" class="ap-speed" title="سرعت پخش">۱×</button>' +
        '<div class="ap-main" dir="ltr"><button type="button" class="ap-b ap-back" title="۱۵ ثانیه عقب">' + I.back + '</button><button type="button" class="ap-play" title="پخش">' + I.play + '</button><button type="button" class="ap-b ap-fwd" title="۱۵ ثانیه جلو">' + I.fwd + '</button></div>' +
        '<div class="ap-vol" dir="ltr"><button type="button" class="ap-b ap-mute" title="قطع/وصل صدا">' + I.vol + '</button><input type="range" class="ap-vrange" min="0" max="100" value="100" aria-label="بلندی صدا"></div>' +
      '</div>' +
      '<div class="ap-speeds" hidden>' + SPEEDS.map(function (s) { return '<button type="button" data-s="' + s + '">' + fa(s) + '×</button>'; }).join('') + '</div>';
    p.querySelector('.ap-meta b').textContent = title;
    a.controls = false; a.removeAttribute('controls'); a.style.display = 'none';
    // take over the card (icon + native player) with the new player
    if (host.classList && host.classList.contains('card')) { host.innerHTML = ''; host.appendChild(a); host.appendChild(p); host.classList.add('ap-card'); }
    else a.parentNode.insertBefore(p, a.nextSibling);

    var $ = function (s) { return p.querySelector(s); };
    var play = $('.ap-play'), range = $('.ap-range'), fill = $('.ap-fill'), buf = $('.ap-buf'), cur = $('.ap-cur'), du = $('.ap-dur');
    var speedBtn = $('.ap-speed'), menu = $('.ap-speeds'), mute = $('.ap-mute'), vr = $('.ap-vrange'), status = $('.ap-status');
    var seeking = false;

    function setSpeed(s) { a.playbackRate = s; speedBtn.textContent = fa(s) + '×'; store.set('ap-speed', String(s)); Array.prototype.forEach.call(menu.children, function (b) { b.classList.toggle('on', parseFloat(b.dataset.s) === s); }); }
    var saved = parseFloat(store.get('ap-speed') || '1'); setSpeed(SPEEDS.indexOf(saved) !== -1 ? saved : 1);
    var vSaved = parseFloat(store.get('ap-vol') || '1'); a.volume = isFinite(vSaved) ? Math.min(1, Math.max(0, vSaved)) : 1; vr.value = Math.round(a.volume * 100);

    function paintVol() { var v = a.muted ? 0 : a.volume; vr.style.setProperty('--v', (v * 100) + '%'); mute.innerHTML = v === 0 ? I.mute : I.vol; }
    paintVol();
    function paint() {
      var d = a.duration, t = a.currentTime;
      if (isFinite(d) && d > 0) { if (!seeking) range.value = Math.round(t / d * 1000); fill.style.width = (t / d * 100) + '%'; du.textContent = fmt(d); }
      cur.textContent = fmt(t);
      try { if (a.buffered.length && isFinite(d)) buf.style.width = (a.buffered.end(a.buffered.length - 1) / d * 100) + '%'; } catch (x) {}
    }
    play.addEventListener('click', function () { if (a.paused) a.play(); else a.pause(); });
    a.addEventListener('play', function () { play.innerHTML = I.pause; play.title = 'توقف'; p.classList.add('is-playing'); status.textContent = 'در حال پخش'; });
    a.addEventListener('pause', function () { play.innerHTML = I.play; play.title = 'پخش'; p.classList.remove('is-playing'); status.textContent = 'متوقف'; });
    a.addEventListener('ended', function () { status.textContent = 'پایان'; });
    a.addEventListener('waiting', function () { status.textContent = 'در حال بارگذاری…'; });
    a.addEventListener('playing', function () { status.textContent = 'در حال پخش'; });
    a.addEventListener('timeupdate', paint); a.addEventListener('loadedmetadata', paint); a.addEventListener('progress', paint); a.addEventListener('durationchange', paint);
    a.addEventListener('ratechange', function () { speedBtn.textContent = fa(a.playbackRate) + '×'; });
    range.addEventListener('input', function () { seeking = true; if (isFinite(a.duration)) { var t = range.value / 1000 * a.duration; cur.textContent = fmt(t); fill.style.width = (range.value / 10) + '%'; } });
    range.addEventListener('change', function () { if (isFinite(a.duration)) a.currentTime = range.value / 1000 * a.duration; seeking = false; });
    $('.ap-back').addEventListener('click', function () { a.currentTime = Math.max(0, a.currentTime - 15); });
    $('.ap-fwd').addEventListener('click', function () { a.currentTime = Math.min(a.duration || 0, a.currentTime + 15); });
    speedBtn.addEventListener('click', function (e) { e.stopPropagation(); menu.hidden = !menu.hidden; });
    menu.addEventListener('click', function (e) { var b = e.target.closest('[data-s]'); if (!b) return; setSpeed(parseFloat(b.dataset.s)); menu.hidden = true; });
    document.addEventListener('click', function (e) { if (!p.contains(e.target)) menu.hidden = true; });
    mute.addEventListener('click', function () { a.muted = !a.muted; if (!a.muted && a.volume === 0) a.volume = 0.5; vr.value = a.muted ? 0 : Math.round(a.volume * 100); paintVol(); });
    vr.addEventListener('input', function () { a.volume = vr.value / 100; a.muted = a.volume === 0; store.set('ap-vol', String(a.volume)); paintVol(); });
    // keyboard: space = play/pause, arrows = ±5s (when the player has focus)
    p.tabIndex = 0;
    p.addEventListener('keydown', function (e) {
      if (e.target.tagName === 'INPUT') return;
      if (e.key === ' ') { e.preventDefault(); play.click(); }
      else if (e.key === 'ArrowLeft') a.currentTime = Math.min(a.duration || 0, a.currentTime + 5);
      else if (e.key === 'ArrowRight') a.currentTime = Math.max(0, a.currentTime - 5);
    });
    paint();
  });
})();

/* ---------------------------------------------------------------- نظام رشد تاجر */
(function () {
  // stage requirement form: show fields for the chosen kind
  document.querySelectorAll('[data-kind-switch]').forEach(function (sel) {
    var form = sel.form;
    function sync() {
      form.querySelectorAll('[data-kind-show]').forEach(function (el) {
        var on = el.getAttribute('data-kind-show').split(' ').indexOf(sel.value) !== -1;
        el.classList.toggle('hide', !on);
        el.querySelectorAll('input,select').forEach(function (i) { i.disabled = !on; });
      });
    }
    sel.addEventListener('change', sync); sync();
  });
  // roadmap → open the stage details
  function openStage(no, scroll) {
    var d = document.getElementById('stage-' + no);
    if (!d) return;
    var wrap = d.parentElement && d.parentElement.closest('details');
    if (wrap) wrap.open = true;
    d.open = true;
    if (scroll) d.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-open-stage]');
    if (!a) return;
    e.preventDefault();
    openStage(a.getAttribute('data-open-stage'), true);
    history.replaceState(null, '', '#stage-' + a.getAttribute('data-open-stage'));
  });
  var m = location.hash.match(/^#stage-(\d+)$/);
  if (m) openStage(m[1], true);

  // full services refresh: run batches from this page
  var box = document.querySelector('[data-sync-run]');
  if (!box) return;
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var running = false;
  var fa = function (n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
  function set(k, v) { var el = box.querySelector('[data-sync-' + k + ']'); if (el) el.textContent = v; }
  function step() {
    var fd = new FormData(); fd.append('_token', csrf);
    fetch(box.getAttribute('data-step-url'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var pct = d.total ? Math.min(100, Math.round(d.done * 100 / d.total)) : 100;
        set('done', fa(d.done)); set('total', fa(d.total)); set('pct', fa(pct) + '٪'); set('ok', fa(d.okn)); set('failed', fa(d.failed)); set('svc', fa(d.services));
        var bar = box.querySelector('[data-sync-bar]'); if (bar) bar.style.width = pct + '%';
        if (d.status === 'running') setTimeout(step, 300);
        else { running = false; setTimeout(function () { location.href = location.pathname + '#sync'; location.reload(); }, 800); }
      })
      .catch(function () { running = false; var b = box.querySelector('[data-sync-go]'); if (b) b.disabled = false; });
  }
  function go() { if (running) return; running = true; var b = box.querySelector('[data-sync-go]'); if (b) b.disabled = true; step(); }
  var btn = box.querySelector('[data-sync-go]');
  if (btn) btn.addEventListener('click', go);
  if (box.getAttribute('data-autostart') === '1') go();
})();

/* ---------------------------------------------------------------- locked lessons: explain instead of doing nothing */
(function () {
  var box = null, timer = null;
  function show(msg) {
    if (!box) {
      box = document.createElement('div'); box.className = 'lock-pop'; box.setAttribute('role', 'alert');
      box.innerHTML = '<span class="lp-ic">🔒</span><div class="lp-t"></div><button type="button" class="lp-x" aria-label="بستن">×</button>';
      document.body.appendChild(box);
      box.querySelector('.lp-x').addEventListener('click', function () { box.classList.remove('on'); });
    }
    box.querySelector('.lp-t').textContent = msg;
    box.classList.remove('on'); void box.offsetWidth; box.classList.add('on');
    clearTimeout(timer); timer = setTimeout(function () { box.classList.remove('on'); }, 6000);
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-locked]');
    if (!a) return;
    e.preventDefault();
    show(a.getAttribute('data-locked'));
    a.classList.remove('shake'); void a.offsetWidth; a.classList.add('shake');
  });
})();

/* ---------------------------------------------------------------- drag & drop ordering: [data-sortable] with rows [data-id] and a .drag-h handle */
(function () {
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  document.querySelectorAll('[data-sortable]').forEach(function (list) {
    var drag = null, ph = null, startOrder = '';
    function rows() { return Array.prototype.filter.call(list.children, function (r) { return r.hasAttribute('data-id'); }); }
    function order() { return rows().map(function (r) { return r.getAttribute('data-id'); }).join(','); }
    var pending = null;
    function begin(row, e, el) {
      drag = row; startOrder = order();
      drag.classList.add('dragging'); document.body.classList.add('is-sorting');
      el && el.setPointerCapture && el.setPointerCapture(e.pointerId);
    }
    list.addEventListener('pointerdown', function (e) {
      var h = e.target.closest('.drag-h');
      if (h && list.contains(h)) { e.preventDefault(); begin(h.closest('[data-id]'), e, h); return; }
      // with a mouse the whole row can be dragged (not from buttons, links or fields)
      if (e.pointerType === 'mouse' && e.button === 0 && list.hasAttribute('data-row-drag') && !e.target.closest('a,button,input,select,textarea,label,form')) {
        var row = e.target.closest('[data-id]');
        if (row && list.contains(row)) pending = { row: row, y: e.clientY, id: e.pointerId };
      }
    });
    list.addEventListener('pointermove', function (e) {
      if (!drag && pending) {
        if (Math.abs(e.clientY - pending.y) < 6) return;
        begin(pending.row, e, list); pending = null;
        var sel = window.getSelection && window.getSelection(); if (sel) sel.removeAllRanges();
      }
      if (!drag) return;
      e.preventDefault();
      var y = e.clientY;
      var over = rows().filter(function (r) { return r !== drag; }).find(function (r) { var b = r.getBoundingClientRect(); return y < b.top + b.height / 2; });
      if (over) { if (drag.nextElementSibling !== over) list.insertBefore(drag, over); }
      else if (list.lastElementChild !== drag) list.appendChild(drag);
      // keep the row visible when dragging near the screen edges
      if (y < 80) window.scrollBy(0, -12); else if (y > window.innerHeight - 60) window.scrollBy(0, 12);
    });
    function end() {
      pending = null;
      if (!drag) return;
      drag.classList.remove('dragging'); document.body.classList.remove('is-sorting');
      var moved = drag; drag = null;
      if (order() === startOrder) return;
      var fd = new FormData(); fd.append('_token', csrf);
      rows().forEach(function (r) { fd.append('ids[]', r.getAttribute('data-id')); });
      moved.classList.add('saving');
      fetch(list.getAttribute('data-sortable'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) { rows().forEach(function (r, i) { var n = r.querySelector('[data-row-no]'); if (n) n.textContent = String(i + 1).replace(/\d/g, function (x) { return '۰۱۲۳۴۵۶۷۸۹'[x]; }); }); moved.classList.remove('saving'); moved.classList.add('saved'); setTimeout(function () { moved.classList.remove('saved'); }, 900); if (window.appToast) window.appToast('ترتیب ذخیره شد'); })
        .catch(function () { moved.classList.remove('saving'); alert('ذخیره ترتیب انجام نشد؛ صفحه را دوباره باز کنید.'); });
    }
    list.addEventListener('pointerup', end);
    list.addEventListener('pointercancel', end);
  });
})();

/* ---------------------------------------------------------------- stage requirements: mark unsaved rows */
(function () {
  var form = document.getElementById('stage-form'); if (!form) return;
  var dirty = false;
  document.querySelectorAll('[data-item-row]').forEach(function (row) {
    row.addEventListener('input', mark); row.addEventListener('change', mark);
    function mark() {
      row.closest('tr').classList.add('is-dirty'); dirty = true;
      var n = document.querySelector('[data-unsaved-note]');
      if (n) { n.classList.add('warn'); n.textContent = 'تغییرات ذخیره‌نشده دارید — «ذخیره همه تغییرات» یا «ذخیره مرحله» را بزنید.'; }
    }
  });
  form.addEventListener('submit', function () { dirty = false; });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();

/* ---------------------------------------------------------------- move lesson to another course */
(function () {
  var dlg = document.getElementById('move-dialog'); if (!dlg) return;
  var form = dlg.querySelector('[data-move-form]');
  var secs = {}; try { secs = JSON.parse((document.getElementById('move-sections') || {}).textContent || '{}'); } catch (e) {}
  var mode = form.querySelector('[data-section-mode]'), pick = form.querySelector('[data-section-pick]'), course = form.querySelector('select[name=course_id]');
  function fillSections() {
    var list = secs[course.value] || [];
    pick.innerHTML = '';
    if (!list.length) { var o = document.createElement('option'); o.value = ''; o.textContent = 'این دوره سرفصلی ندارد'; pick.appendChild(o); }
    list.forEach(function (s) { var o = document.createElement('option'); o.value = s; o.textContent = s; pick.appendChild(o); });
  }
  function syncMode() { pick.classList.toggle('hide', mode.value !== 'pick'); if (mode.value === 'pick') fillSections(); }
  mode.addEventListener('change', syncMode); course.addEventListener('change', function () { if (mode.value === 'pick') fillSections(); });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-move-lesson]');
    if (b) {
      form.action = form.getAttribute('data-base') + b.getAttribute('data-move-lesson') + '/transfer';
      form.querySelector('[data-move-title]').textContent = b.getAttribute('data-title');
      var sec = b.getAttribute('data-section');
      form.querySelector('[data-move-section]').textContent = sec || 'بدون سرفصل';
      course.selectedIndex = -1; mode.value = 'keep'; syncMode();
      dlg.showModal ? dlg.showModal() : dlg.setAttribute('open', '');
      return;
    }
    if (e.target.closest('[data-close-dialog]')) dlg.close();
  });
  form.addEventListener('submit', function (e) {
    if (!course.value) { e.preventDefault(); alert('دوره مقصد را انتخاب کنید.'); return; }
    var name = course.options[course.selectedIndex].textContent.trim();
    if (!confirm('درس به دوره «' + name + '» منتقل شود؟')) e.preventDefault();
  });
})();

/* ---------------------------------------------------------------- password fields that browsers must not autofill */
(function () {
  // [data-nofill] stays readonly until the user really focuses it — password managers skip readonly fields
  document.querySelectorAll('input[data-nofill]').forEach(function (el) {
    var open = function () { el.removeAttribute('readonly'); };
    el.addEventListener('focus', open);
    el.addEventListener('pointerdown', open);
    // if something filled it anyway before the user touched it, wipe it
    setTimeout(function () { if (el.hasAttribute('readonly') && el.value) el.value = ''; }, 600);
  });
  // checkbox [data-enables="#id"] enables / disables its target field
  document.querySelectorAll('input[type=checkbox][data-enables]').forEach(function (cb) {
    var t = document.querySelector(cb.getAttribute('data-enables'));
    if (!t) return;
    var sync = function () {
      t.disabled = !cb.checked;
      if (!cb.checked) { t.value = ''; t.setAttribute('readonly', ''); }
      else { t.removeAttribute('readonly'); t.focus(); }
    };
    cb.addEventListener('change', sync);
    cb.checked = false; t.disabled = true; t.value = '';
  });
})();

// header dropdowns: lock page scroll while one is open (phones show them as a bottom sheet), Esc closes
(function () {
  function sync() { document.documentElement.classList.toggle('dd-open', !!document.querySelector('details.dropdown[open]')); }
  document.addEventListener('toggle', function (e) { if (e.target && e.target.matches && e.target.matches('details.dropdown')) sync(); }, true);
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('details.dropdown[open]').forEach(function (d) { d.removeAttribute('open'); });
    sync();
  });
  window.addEventListener('pageshow', sync);
})();

// exam form: reload the «درس مرتبط» list whenever the course changes
(function () {
  document.querySelectorAll('select[data-lessons-url]').forEach(function (sel) {
    var target = document.getElementById(sel.getAttribute('data-lessons-target'));
    if (!target) return;
    sel.addEventListener('change', function () {
      var keep = target.options[0] ? target.options[0].cloneNode(true) : null;
      target.innerHTML = ''; if (keep) target.appendChild(keep);
      if (!sel.value) return;
      target.disabled = true;
      fetch(sel.getAttribute('data-lessons-url') + (sel.getAttribute('data-lessons-url').indexOf('?') === -1 ? '?' : '&') + 'course_id=' + encodeURIComponent(sel.value), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var groups = {};
          (d.lessons || []).forEach(function (l) {
            var parent = target;
            if (l.section) {
              if (!groups[l.section]) { groups[l.section] = document.createElement('optgroup'); groups[l.section].label = l.section; target.appendChild(groups[l.section]); }
              parent = groups[l.section];
            }
            var o = document.createElement('option');
            o.value = l.id; o.textContent = l.title + (l.draft ? ' (پیش‌نویس)' : '');
            parent.appendChild(o);
          });
        })
        .catch(function () {})
        .then(function () { target.disabled = false; });
    });
  });
})();
