/* Progressive enhancement only: every page works without JavaScript. */
(function () {
  'use strict';
  var root = document.documentElement;
  root.classList.add('js');

  // Interface language: the Persian text is the key; other languages get their wording from #i18n-js on the page.
  var I18N = null;
  try { var i18nEl = document.getElementById('i18n-js'); I18N = i18nEl ? JSON.parse(i18nEl.textContent) : null; } catch (e) {}
  // ICU plural blocks in a translation: {n, plural, one {# item} other {# items}} — # is the formatted number.
  var plural = function (out, p) {
    return out.replace(/\{(\w+),\s*plural,\s*((?:[^{}]*\{[^{}]*\})+)\s*\}/g, function (all, key, body) {
      var shown = p && p[key] !== undefined ? String(p[key]) : '';
      var num = parseFloat(shown.replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; }).replace(/[^\d.]/g, '')) || 0;
      var forms = {};
      body.replace(/(=?\w+)\s*\{([^{}]*)\}/g, function (m, cat, text) { forms[cat] = text; });
      var cat = 'other';
      try { cat = new Intl.PluralRules(root.lang).select(num); } catch (e) {}
      var text = forms['=' + num] !== undefined ? forms['=' + num] : (forms[cat] !== undefined ? forms[cat] : (forms.other || ''));
      return text.split('#').join(shown);
    });
  };
  var T = function (s, p) {
    var out = I18N && I18N[s] ? I18N[s] : s;
    if (out.indexOf(', plural,') >= 0) out = plural(out, p);
    if (p) Object.keys(p).forEach(function (k) { out = out.split(':' + k).join(p[k]); });
    return out;
  };
  var NUM = root.lang === 'fa' ? 'fa-IR' : (root.lang === 'ar' ? 'ar-u-nu-latn' : (root.lang || 'en'));
  window.sadtT = T;
  window.sadtNum = NUM;

  // Theme toggle
  document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var current = root.getAttribute('data-theme') || 'dark';
      var next = current === 'light' ? 'dark' : 'light';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('sadt-theme', next); } catch (e) {}
    });
  });

  // Confirm destructive actions
  // A single risky button inside a form (e.g. admin "حذف") asks first, too.
  document.querySelectorAll('button[data-confirm]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      if (!window.confirm(btn.getAttribute('data-confirm'))) e.preventDefault();
    });
  });
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // Country select fills the calling code (user can still edit it)
  document.querySelectorAll('select[data-calling-target]').forEach(function (select) {
    var target = document.getElementById(select.getAttribute('data-calling-target'));
    if (!target) return;
    select.addEventListener('change', function () {
      var opt = select.options[select.selectedIndex];
      if (opt && opt.dataset.cc) target.value = opt.dataset.cc;
    });
  });

  // Image size check + preview before upload (the server checks again).
  document.querySelectorAll('input[type=file][data-preview]').forEach(function (input) {
    var target = document.getElementById(input.getAttribute('data-preview'));
    var original = target ? target.cloneNode(true) : null;
    var maxKb = parseInt(input.getAttribute('data-max-kb') || '0', 10);
    var msg = document.createElement('div');
    msg.className = 'error';
    msg.setAttribute('role', 'alert');
    msg.hidden = true;
    input.insertAdjacentElement('afterend', msg);

    function restore() {
      var current = document.getElementById(input.getAttribute('data-preview'));
      if (current && original) current.replaceWith(original.cloneNode(true));
    }

    input.addEventListener('change', function () {
      msg.hidden = true;
      var file = input.files && input.files[0];
      if (!file) { restore(); return; }
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
        msg.textContent = T('فقط تصاویر JPG، PNG و WebP پذیرفته می‌شوند.');
        msg.hidden = false; input.value = ''; restore(); return;
      }
      if (maxKb && file.size > maxKb * 1024) {
        var size = Math.ceil(file.size / 1024).toLocaleString(NUM);
        msg.textContent = T('حجم این تصویر :size کیلوبایت است. حداکثر مجاز :max کیلوبایت است؛ تصویر را فشرده کنید و دوباره انتخاب کنید.',
          { size: size, max: maxKb.toLocaleString(NUM) });
        msg.hidden = false; input.value = ''; restore(); return;
      }
      var current = document.getElementById(input.getAttribute('data-preview'));
      if (!current) return;
      var img = current;
      if (current.tagName !== 'IMG') {
        img = document.createElement('img');
        img.id = current.id; img.alt = '';
        current.replaceWith(img);
      }
      img.src = URL.createObjectURL(file);
    });
  });

  // Multiple-file inputs (gallery): count and size check
  document.querySelectorAll('input[type=file][multiple][data-max-kb]').forEach(function (input) {
    var maxKb = parseInt(input.getAttribute('data-max-kb'), 10);
    var maxFiles = parseInt(input.getAttribute('data-max-files') || '10', 10);
    var msg = document.createElement('div');
    msg.className = 'error'; msg.setAttribute('role', 'alert'); msg.hidden = true;
    input.insertAdjacentElement('afterend', msg);
    input.addEventListener('change', function () {
      msg.hidden = true;
      var files = Array.prototype.slice.call(input.files || []);
      var tooBig = files.filter(function (f) { return f.size > maxKb * 1024; });
      if (files.length > maxFiles) {
        msg.textContent = T('حداکثر :n تصویر می‌توانید انتخاب کنید.', { n: maxFiles.toLocaleString(NUM) });
      } else if (tooBig.length) {
        msg.textContent = T('حجم :n تصویر بیشتر از :max کیلوبایت است؛ آن‌ها را فشرده کنید.', { n: tooBig.length.toLocaleString(NUM), max: maxKb.toLocaleString(NUM) });
      } else { return; }
      msg.hidden = false; input.value = '';
    });
  });

  // Infinite scroll for the proposal feed (the "more" link is the no-JS fallback)
  var feed = document.getElementById('feed');
  var foot = document.getElementById('feed-foot');
  if (feed && foot && 'IntersectionObserver' in window) {
    var loading = false;
    var io = new IntersectionObserver(function (entries) {
      if (!entries[0].isIntersecting || loading) return;
      var url = feed.getAttribute('data-more');
      if (!url) { io.disconnect(); return; }
      loading = true;
      fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          var tpl = document.createElement('template');
          tpl.innerHTML = res.data.html;
          Array.prototype.forEach.call(tpl.content.querySelectorAll('.pcard'), function (card) {
            if (!feed.querySelector('.pcard[data-id="' + card.getAttribute('data-id') + '"]')) feed.appendChild(card);
          });
          feed.setAttribute('data-more', res.data.next || '');
          if (!res.data.next) { foot.innerHTML = '<p class="muted">' + T('به انتهای فهرست رسیدید.') + '</p>'; io.disconnect(); }
        })
        .catch(function () { foot.querySelector('[data-feed-next]') && (foot.querySelector('[data-feed-next]').style.display = 'inline-flex'); })
        .finally(function () { loading = false; });
    }, { rootMargin: '600px 0px' });
    io.observe(foot);
  }


  // ---------------------------------------------------------------------------
  // Searchable select: any single <select> with more than 10 options becomes a
  // combobox with a search box and a scrollable list. The native <select> stays in
  // the form (hidden) and keeps the value, so forms work exactly as before.
  // ---------------------------------------------------------------------------
  function norm(t) {
    return (t || '').toString()
      .replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[ة]/g, 'ه').replace(/[أإآ]/g, 'ا')
      .replace(/[\u200c\u200f\u200e]/g, '')
      .replace(/[۰-۹]/g, function (d) { return String(d.charCodeAt(0) - 1776); })
      .replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 1632); })
      .toLowerCase().trim();
  }

  var uid = 0;
  function enhanceSelect(select) {
    if (select.multiple || (select.options.length <= 10 && !select.hasAttribute('data-ss')) || select.dataset.enhanced) return;
    select.dataset.enhanced = '1';
    uid++;
    var listId = 'ss-list-' + uid;

    var wrap = document.createElement('div');
    wrap.className = 'ss';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add('ss-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'select ss-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    var label = select.id ? document.querySelector('label[for="' + select.id + '"]') : null;
    if (label) { trigger.id = select.id + '-ss'; label.setAttribute('for', trigger.id); }
    else if (select.getAttribute('aria-label')) { trigger.setAttribute('aria-label', select.getAttribute('aria-label')); }
    wrap.appendChild(trigger);

    var panel = document.createElement('div');
    panel.className = 'ss-panel';
    panel.hidden = true;
    panel.innerHTML = '<div class="ss-search"><input type="search" class="input" autocomplete="off" placeholder="' + T('جستجو…') + '" ' +
      'role="combobox" aria-autocomplete="list" aria-controls="' + listId + '" aria-expanded="true"></div>' +
      '<ul class="ss-list" role="listbox" id="' + listId + '"></ul><p class="ss-empty" hidden>' + T('موردی پیدا نشد.') + '</p>';
    wrap.appendChild(panel);
    var input = panel.querySelector('input');
    var list = panel.querySelector('ul');
    var empty = panel.querySelector('.ss-empty');

    var items = Array.prototype.map.call(select.options, function (opt, i) {
      var li = document.createElement('li');
      li.id = listId + '-' + i;
      li.setAttribute('role', 'option');
      li.textContent = opt.textContent.trim();
      if (opt.disabled) li.setAttribute('aria-disabled', 'true');
      list.appendChild(li);
      return { li: li, opt: opt, key: norm(opt.textContent + ' ' + (opt.getAttribute('data-search') || '') + ' ' + opt.value) };
    });
    var active = -1;

    function sync() {
      var opt = select.options[select.selectedIndex];
      trigger.textContent = opt ? opt.textContent.trim() : '';
      trigger.classList.toggle('ss-placeholder', !opt || opt.value === '');
      items.forEach(function (it) { it.li.setAttribute('aria-selected', it.opt.selected ? 'true' : 'false'); });
    }
    function visible() { return items.filter(function (it) { return !it.li.hidden && it.li.getAttribute('aria-disabled') !== 'true'; }); }
    function setActive(idx) {
      var vis = visible();
      items.forEach(function (it) { it.li.classList.remove('is-active'); });
      if (!vis.length) { active = -1; input.removeAttribute('aria-activedescendant'); return; }
      active = Math.max(0, Math.min(idx, vis.length - 1));
      vis[active].li.classList.add('is-active');
      input.setAttribute('aria-activedescendant', vis[active].li.id);
      // Scroll only the list (scrollIntoView would also move the page, which jumps on phones).
      var li = vis[active].li;
      var top = li.offsetTop - list.offsetTop;
      var bottom = top + li.offsetHeight;
      if (top < list.scrollTop) list.scrollTop = top;
      else if (bottom > list.scrollTop + list.clientHeight) list.scrollTop = bottom - list.clientHeight;
    }
    function filter() {
      var q = norm(input.value);
      var shown = 0;
      items.forEach(function (it) {
        var hit = q === '' || it.key.indexOf(q) !== -1;
        it.li.hidden = !hit;
        if (hit) shown++;
      });
      empty.hidden = shown > 0;
      setActive(0);
    }
    function open() {
      if (!panel.hidden) return;
      document.querySelectorAll('.ss.is-open').forEach(function (other) { if (other !== wrap) other.__close(); });
      panel.hidden = false;
      wrap.classList.add('is-open');
      trigger.setAttribute('aria-expanded', 'true');
      input.value = '';
      filter();
      place();
      var vis = visible();
      var sel = vis.findIndex(function (it) { return it.opt.selected; });
      setActive(sel >= 0 ? sel : 0);
      // Touch screens: no auto-focus (it pops the keyboard and scrolls the page); desktop: focus without scrolling.
      if (!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches)) {
        setTimeout(function () { try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); } }, 0);
      }
    }
    // Open right under the field (or above it when there is more room there) and size the list to the visible
    // space, so the choices are always on screen without scrolling the page.
    function place() {
      var r = trigger.getBoundingClientRect();
      var vh = window.innerHeight || document.documentElement.clientHeight;
      var floor = vh;
      var bar = document.querySelector('.bottom-nav');
      if (bar && getComputedStyle(bar).display !== 'none') floor = Math.min(floor, bar.getBoundingClientRect().top);
      var below = floor - r.bottom - 16;
      var above = r.top - 16;
      var up = below < 240 && above > below;
      wrap.classList.toggle('ss-up', up);
      list.style.maxHeight = Math.max(140, Math.min(300, (up ? above : below) - 72)) + 'px';
    }
    function close(focusTrigger) {
      if (panel.hidden) return;
      panel.hidden = true;
      wrap.classList.remove('is-open');
      trigger.setAttribute('aria-expanded', 'false');
      if (focusTrigger) trigger.focus();
    }
    wrap.__close = function () { close(false); };
    function choose(it) {
      if (!it || it.li.getAttribute('aria-disabled') === 'true') return;
      select.value = it.opt.value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      sync();
      close(true);
    }

    trigger.addEventListener('click', function () { panel.hidden ? open() : close(true); });
    trigger.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey) { open(); input.value = e.key; filter(); }
    });
    input.addEventListener('input', filter);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
      else if (e.key === 'Enter') { e.preventDefault(); choose(visible()[active]); }
      else if (e.key === 'Escape') { e.preventDefault(); close(true); }
      else if (e.key === 'Tab') { close(false); }
    });
    list.addEventListener('mousedown', function (e) { e.preventDefault(); });
    list.addEventListener('click', function (e) {
      var li = e.target.closest('li');
      if (li) choose(items.find(function (it) { return it.li === li; }));
    });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(false); });
    select.addEventListener('change', sync);
    sync();
  }
  document.querySelectorAll('select').forEach(enhanceSelect);

  // Auto refresh (public letter progress)
  var auto = document.querySelector('[data-autorefresh]');
  if (auto) setTimeout(function () { location.reload(); }, parseInt(auto.getAttribute('data-autorefresh'), 10) * 1000);

  // Send wizard: show the fields that belong to the chosen kind
  var sendForm = document.querySelector('[data-send-form]');
  if (sendForm) {
    var applyKind = function () {
      var checked = sendForm.querySelector('input[name=kind]:checked');
      var k = checked ? checked.value : '';
      sendForm.querySelectorAll('[data-kind-only]').forEach(function (el) { el.hidden = el.getAttribute('data-kind-only') !== k; });
      sendForm.querySelectorAll('[data-kind-hide]').forEach(function (el) { el.hidden = el.getAttribute('data-kind-hide') === k; });
    };
    sendForm.addEventListener('change', function (e) { if (e.target.name === 'kind') applyKind(); });
    applyKind();
  }

  // PWA: register the service worker (static assets only; never private data)
  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    window.addEventListener('load', function () { navigator.serviceWorker.register('/service-worker.js').catch(function () {}); });
  }

  // PWA install prompt for phones and tablets that have not installed the app yet.
  // Android/Chrome-like browsers: a real «نصب» button (beforeinstallprompt). iPhone/iPad Safari: how to add it
  // from the Share menu. Other in-app browsers: open the site in Safari/Chrome first. "بعداً" hides it for 3 days.
  (function () {
    var KEY = 'sadt-install-snooze';
    var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
    var touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
    if (standalone || !touch || /^\/(login|register|install)/.test(location.pathname)) return;
    try { if (Date.now() < +(localStorage.getItem(KEY) || 0)) return; } catch (e) {}
    var ua = navigator.userAgent || '';
    var ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var iosSafari = ios && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS|Instagram|FBAN|FBAV|Telegram/.test(ua);
    var deferred = null;
    var box = null;
    var share = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V3M8 7l4-4 4 4"/><path d="M6 11H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-1"/></svg>';
    var plus = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="4"/><path d="M12 8v8M8 12h8"/></svg>';
    var dots = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>';

    function snooze(days) { try { localStorage.setItem(KEY, String(Date.now() + days * 864e5)); } catch (e) {} }
    function close(days) {
      if (!box) return;
      snooze(days);
      box.classList.remove('is-in');
      setTimeout(function () { if (box && box.parentNode) box.parentNode.removeChild(box); box = null; }, 350);
    }
    function render() {
      if (box) return;
      var steps;
      if (deferred) {
        steps = '<p class="pwa-text">' + T('با نصب، سامانه مثل یک اپلیکیشن روی صفحه اصلی گوشی شما قرار می‌گیرد؛ سریع‌تر باز می‌شود و نامه‌ها و پیشنهادها همیشه در دسترس‌اند.') + '</p>';
      } else if (iosSafari) {
        steps = '<ol class="pwa-steps"><li>' + T('در پایین سافاری دکمه اشتراک‌گذاری :icon را بزنید.', { icon: '<span class="pwa-ic">' + share + '</span>' }) + '</li>' +
          '<li>' + T('گزینه <b>Add to Home Screen</b> :icon را انتخاب کنید.', { icon: '<span class="pwa-ic">' + plus + '</span>' }) + '</li><li>' + T('در بالا روی <b>Add</b> بزنید.') + '</li></ol>';
      } else if (ios) {
        steps = '<p class="pwa-text">' + T('برای نصب، همین صفحه را در <b>Safari</b> باز کنید، سپس از دکمه اشتراک‌گذاری :icon گزینه <b>Add to Home Screen</b> را بزنید.', { icon: '<span class="pwa-ic">' + share + '</span>' }) + '</p>';
      } else {
        steps = '<ol class="pwa-steps"><li>' + T('منوی مرورگر :icon را باز کنید.', { icon: '<span class="pwa-ic">' + dots + '</span>' }) + '</li>' +
          '<li>' + T('گزینه <b>نصب برنامه</b> یا <b>Add to Home screen</b> را بزنید.') + '</li></ol>';
      }
      box = document.createElement('div');
      box.className = 'pwa-pop';
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-label', T('نصب اپلیکیشن سامانه توسعه تجارت'));
      box.innerHTML = '<button class="pwa-x" type="button" aria-label="' + T('بستن') + '">×</button>' +
        '<div class="pwa-head"><img src="/assets/brand/logo-192.webp?v=4" alt="" width="44" height="44"><div><b>' + T('اپلیکیشن سامانه توسعه تجارت') + '</b><small>' + T('نصب رایگان، بدون نیاز به فروشگاه برنامه') + '</small></div></div>' +
        steps +
        '<div class="pwa-actions">' + (deferred ? '<button class="pwa-btn" type="button" data-pwa="install">' + T('نصب اپلیکیشن') + '</button>' : '<button class="pwa-btn" type="button" data-pwa="ok">' + T('متوجه شدم') + '</button>') +
        '<button class="pwa-later" type="button" data-pwa="later">' + T('بعداً') + '</button></div>';
      document.body.appendChild(box);
      box.querySelector('.pwa-x').addEventListener('click', function () { close(3); });
      box.querySelector('[data-pwa="later"]').addEventListener('click', function () { close(3); });
      var ok = box.querySelector('[data-pwa="ok"]');
      if (ok) ok.addEventListener('click', function () { close(14); });
      var inst = box.querySelector('[data-pwa="install"]');
      if (inst) inst.addEventListener('click', function () {
        var ev = deferred; deferred = null;
        ev.prompt();
        (ev.userChoice || Promise.resolve({})).then(function (r) { close(r && r.outcome === 'accepted' ? 365 : 3); });
      });
      requestAnimationFrame(function () { requestAnimationFrame(function () { if (box) box.classList.add('is-in'); }); });
    }
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      deferred = e;
      if (box) { box.parentNode.removeChild(box); box = null; }
      setTimeout(render, 1500);
    });
    window.addEventListener('appinstalled', function () { close(365); });
    // Without the browser event (iPhone, Firefox, Samsung Internet…) show the how-to after a short delay.
    setTimeout(function () { if (!deferred) render(); }, 6000);
  })();

  // Close the floating action sheet on outside click / Escape
  var fab = document.querySelector('details.fab');
  if (fab) {
    document.addEventListener('click', function (e) { if (fab.open && !fab.contains(e.target)) fab.open = false; });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fab.open = false; });
  }

  // Prevent double submit
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      var btn = form.querySelector('button[type=submit]');
      if (btn) setTimeout(function () { btn.disabled = true; }, 0);
    });
  });
})();
