// Confirm before destructive actions: <form data-confirm="…">.
document.addEventListener('submit', function (e) {
  var msg = e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) {
    e.preventDefault();
    return;
  }
  // Signing out: forget the field app's saved copy of the farm's data (queued work stays until sent).
  if (/logout\.php$/.test(e.target.getAttribute('action') || '')) {
    try {
      Object.keys(localStorage).filter(function (k) { return k.indexOf('sfmtp.field.state.') === 0; }).forEach(function (k) { localStorage.removeItem(k); });
    } catch (err) { /* storage blocked */ }
    if (window.caches) { caches.delete('sfmtp-field-v1'); }
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
// Links that open by themselves (handing over to the payment page).
var autoFollow = document.querySelector('a[data-autofollow]');
if (autoFollow) {
  window.setTimeout(function () { window.location.href = autoFollow.href; }, 800);
}
