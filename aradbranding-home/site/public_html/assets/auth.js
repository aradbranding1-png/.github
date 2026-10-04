/* Sign-in / sign-up page: show/hide password and the short "entering" transition. Nothing here delays the form. */
(function () {
  'use strict';
  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    var input = document.getElementById(btn.getAttribute('data-toggle-password'));
    if (!input) return;
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      var T = window.sadtT || function (s) { return s; };
      btn.setAttribute('aria-label', show ? T('پنهان کردن رمز عبور') : T('نمایش رمز عبور'));
      input.focus();
    });
  });
  document.querySelectorAll('[data-auth-form]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var empty = Array.prototype.some.call(form.querySelectorAll('input[required]'), function (i) { return i.value.trim() === ''; });
      if (empty) return;
      var btn = form.querySelector('[data-busy]');
      if (btn) {
        var label = btn.querySelector('span');
        if (label) label.textContent = btn.getAttribute('data-busy');
        btn.classList.add('is-busy');
        btn.setAttribute('aria-busy', 'true');
      }
      document.body.classList.add('is-launching');
      window.dispatchEvent(new Event('tg:launch'));
    });
  });
})();
