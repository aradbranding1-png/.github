/* Runs in <head> before paint.
   - Signed-in panel (<html data-default-theme="system">): automatic by default (follows the device's light/dark
     setting and switches live); the user can pin light or dark with the theme button (key "sadt-theme-mode").
   - Other pages: dark navy, unless the user explicitly chose light earlier (key "sadt-theme"). */
(function () {
  var root = document.documentElement;
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  var system = function () { return mq && mq.matches ? 'dark' : 'light'; };
  var get = function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } };
  if (root.getAttribute('data-default-theme') === 'system') {
    var mode = get('sadt-theme-mode');
    if (mode !== 'light' && mode !== 'dark') mode = 'system';
    root.setAttribute('data-theme-mode', mode);
    root.setAttribute('data-theme', mode === 'system' ? system() : mode);
    if (mq) {
      var onChange = function () { if (root.getAttribute('data-theme-mode') === 'system') root.setAttribute('data-theme', system()); };
      if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);
    }
    window.__sadtSystemTheme = system;
    return;
  }
  var t = 'dark';
  var saved = get('sadt-theme');
  if (saved === 'light' || saved === 'dark') t = saved;
  root.setAttribute('data-theme', t);
})();
