// Confirm before destructive actions: <form data-confirm="…">.
document.addEventListener('submit', function (e) {
  var msg = e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) {
    e.preventDefault();
  }
});
// Print buttons.
document.addEventListener('click', function (e) {
  if (e.target.matches('[data-print]')) {
    window.print();
  }
});
// Selects that submit their form when changed (the farm switcher).
document.addEventListener('change', function (e) {
  if (e.target.matches('select[data-autosubmit]')) {
    e.target.form.submit();
  }
});
