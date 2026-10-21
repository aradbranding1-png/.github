(function () {
  var f = document.getElementById('f-package');
  var n = document.querySelector('[data-su-name]');
  if (f && n) {
    f.addEventListener('change', function () {
      n.textContent = f.files.length ? f.files[0].name : 'انتخاب فایل ZIP';
    });
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
