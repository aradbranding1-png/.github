/* Runs in <head> before paint. Default: dark navy, or the page's own default (the signed-in panel uses light,
   <html data-default-theme="light">). An explicit user choice always wins. */
(function () {
  var root = document.documentElement;
  var t = root.getAttribute('data-default-theme') === 'light' ? 'light' : 'dark';
  try {
    var saved = localStorage.getItem('sadt-theme');
    if (saved === 'light' || saved === 'dark') t = saved;
  } catch (e) {}
  root.setAttribute('data-theme', t);
})();
