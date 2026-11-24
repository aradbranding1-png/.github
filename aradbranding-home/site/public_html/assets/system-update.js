(function () {
  var f = document.getElementById('f-package');
  var n = document.querySelector('[data-su-name]');
  var drop = document.querySelector('.su-drop');
  var empty = n ? n.textContent : '';
  var isZip = function (file) { return /\.zip$/i.test(file.name) || /zip/i.test(file.type); };

  function show() {
    var file = f.files.length ? f.files[0] : null;
    if (n) n.textContent = file ? file.name : empty;
    if (drop) drop.classList.toggle('is-ready', !!file);
  }
  // Puts a ZIP from the clipboard or a drag-and-drop into the file field, as if it had been picked.
  function take(files) {
    var zip = null;
    for (var i = 0; files && i < files.length; i++) { if (isZip(files[i])) { zip = files[i]; break; } }
    if (!zip) return false;
    try {
      var dt = new DataTransfer();
      dt.items.add(zip);
      f.files = dt.files;
    } catch (e) { return false; }
    show();
    return true;
  }

  if (f) {
    f.addEventListener('change', show);
    // Ctrl+V / Cmd+V anywhere on the page with a copied ZIP file. Pasting text into a field works as usual.
    document.addEventListener('paste', function (e) {
      var files = e.clipboardData && e.clipboardData.files;
      if (files && files.length && take(files)) e.preventDefault();
    });
    if (drop) {
      ['dragenter', 'dragover'].forEach(function (t) {
        drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
      });
      ['dragleave', 'drop'].forEach(function (t) {
        drop.addEventListener(t, function () { drop.classList.remove('is-over'); });
      });
      drop.addEventListener('drop', function (e) {
        e.preventDefault();
        take(e.dataTransfer && e.dataTransfer.files);
      });
    }
  }

  var form = document.querySelector('[data-su-form]');
  var b = document.querySelector('[data-su-submit]');
  if (form && b) {
    form.addEventListener('submit', function () {
      // Let the browser start the upload first, then block double submits.
      setTimeout(function () { b.disabled = true; b.textContent = 'در حال بروزرسانی…'; }, 0);
    });
  }
})();
