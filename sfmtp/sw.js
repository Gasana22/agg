// Service worker for the field app: keeps the app shell on the phone so it opens without a connection.
// Only the field app's own files are cached; every other page and all data go to the network.
var CACHE = 'sfmtp-field-v1';
var SHELL = ['field.php', 'assets/style.css', 'assets/field.js', 'manifest.webmanifest', 'assets/icon-192.png', 'assets/icon-512.png'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  if (e.request.method !== 'GET') {
    return;
  }
  var url = new URL(e.request.url);
  var path = url.pathname.split('/').pop();
  var shellFile = SHELL.filter(function (f) { return url.pathname.endsWith('/' + f); })[0];
  if (!shellFile || url.origin !== self.location.origin) {
    return;
  }
  // The page: network first (fresh sign-in state), the saved copy when offline.
  // Assets: the saved copy, refreshed in the background.
  if (path === 'field.php') {
    e.respondWith(fetch(e.request).then(function (res) {
      if (res.ok && !res.redirected) {
        var copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put(shellFile, copy); });
      }
      return res;
    }).catch(function () { return caches.match(shellFile); }));
    return;
  }
  e.respondWith(caches.match(shellFile).then(function (hit) {
    var net = fetch(e.request).then(function (res) {
      if (res.ok) {
        var copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put(shellFile, copy); });
      }
      return res;
    }).catch(function () { return hit; });
    return hit || net;
  }));
});
