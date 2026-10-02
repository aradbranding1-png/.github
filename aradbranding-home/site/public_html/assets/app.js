/* Progressive enhancement only: every page works without JavaScript. */
(function () {
  'use strict';
  var root = document.documentElement;
  root.classList.add('js');

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
        msg.textContent = 'فقط تصاویر JPG، PNG و WebP پذیرفته می‌شوند.';
        msg.hidden = false; input.value = ''; restore(); return;
      }
      if (maxKb && file.size > maxKb * 1024) {
        var size = Math.ceil(file.size / 1024).toLocaleString('fa-IR');
        msg.textContent = 'حجم این تصویر ' + size + ' کیلوبایت است. حداکثر مجاز ' +
          maxKb.toLocaleString('fa-IR') + ' کیلوبایت است؛ تصویر را فشرده کنید و دوباره انتخاب کنید.';
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
        msg.textContent = 'حداکثر ' + maxFiles.toLocaleString('fa-IR') + ' تصویر می‌توانید انتخاب کنید.';
      } else if (tooBig.length) {
        msg.textContent = 'حجم ' + tooBig.length.toLocaleString('fa-IR') + ' تصویر بیشتر از ' + maxKb.toLocaleString('fa-IR') + ' کیلوبایت است؛ آن‌ها را فشرده کنید.';
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
          if (!res.data.next) { foot.innerHTML = '<p class="muted">به انتهای فهرست رسیدید.</p>'; io.disconnect(); }
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
    panel.innerHTML = '<div class="ss-search"><input type="search" class="input" autocomplete="off" placeholder="جستجو…" ' +
      'role="combobox" aria-autocomplete="list" aria-controls="' + listId + '" aria-expanded="true"></div>' +
      '<ul class="ss-list" role="listbox" id="' + listId + '"></ul><p class="ss-empty" hidden>موردی پیدا نشد.</p>';
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
