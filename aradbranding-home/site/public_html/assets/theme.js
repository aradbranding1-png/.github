/* Runs in <head> before paint.
   - Signed-in panel (<html data-default-theme="system">): automatic by default — light by day, dark at night by the
     device clock (dark from 18:00 to 06:00), re-checked every minute; the user can pin light or dark from the theme
     menu (key "sadt-theme-mode").
   - Other pages: dark navy, unless the user explicitly chose light earlier (key "sadt-theme"). */
(function () {
  var root = document.documentElement;
  var system = function () { var h = new Date().getHours(); return h >= 18 || h < 6 ? 'dark' : 'light'; };
  var get = function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } };
  if (root.getAttribute('data-default-theme') === 'system') {
    var mode = get('sadt-theme-mode');
    if (mode !== 'light' && mode !== 'dark') mode = 'system';
    root.setAttribute('data-theme-mode', mode);
    root.setAttribute('data-theme', mode === 'system' ? system() : mode);
    setInterval(function () {
      if (root.getAttribute('data-theme-mode') === 'system' && root.getAttribute('data-theme') !== system()) root.setAttribute('data-theme', system());
    }, 60000);
    window.__sadtSystemTheme = system;
    return;
  }
  var t = 'dark';
  var saved = get('sadt-theme');
  if (saved === 'light' || saved === 'dark') t = saved;
  root.setAttribute('data-theme', t);
})();
