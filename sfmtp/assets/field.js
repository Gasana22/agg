// The field app. What you do is saved on the phone first (the outbox) and
// sent to sync.php when there is a connection; the server applies each
// action once, so resending after a dropped connection is safe.
(function () {
  'use strict';
  // Everything is kept per person, so a shared phone never sends one person's work as another's.
  var uid = document.querySelector('meta[name="uid"]').content;
  var KEY_STATE = 'sfmtp.field.state.' + uid, KEY_OUT = 'sfmtp.field.outbox.' + uid, KEY_PROBLEMS = 'sfmtp.field.problems.' + uid, KEY_DEVICE = 'sfmtp.field.device';
  var csrf = document.querySelector('meta[name="csrf"]').content;
  var base = location.pathname.replace(/[^/]*$/, '');
  var syncing = false;

  function load(k, d) { try { var v = localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } }
  function save(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* storage full or blocked */ } }
  function uuid() {
    if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
    var b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128;
    var h = Array.prototype.map.call(b, function (x) { return (x + 256).toString(16).slice(1); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }
  function utcNow() { return new Date().toISOString().slice(0, 19).replace('T', ' '); }
  function el(tag, attrs, text) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }
  function $(id) { return document.getElementById(id); }
  function label(s) { return s ? (s.charAt(0).toUpperCase() + s.slice(1)).replace(/_/g, ' ') : ''; }

  var device = load(KEY_DEVICE, null);
  if (!device) { device = uuid(); save(KEY_DEVICE, device); }

  // Queue an action and show its effect straight away.
  function queue(type, data) {
    var state = load(KEY_STATE, null);
    if (!state || !state.farm) { return; }
    var out = load(KEY_OUT, []);
    out.push({ id: uuid(), farm_id: state.farm.id, type: type, occurred_at: utcNow(), data: data || {} });
    save(KEY_OUT, out);
    render();
    sync();
  }

  // The state as the server last sent it, with the queued actions applied on top.
  function view() {
    var s = JSON.parse(JSON.stringify(load(KEY_STATE, { farm: null })));
    var next = { 'task.start': 'in_progress', 'task.pause': 'paused', 'task.resume': 'in_progress', 'task.submit': 'submitted' };
    load(KEY_OUT, []).forEach(function (m) {
      if (!s.farm || m.farm_id !== s.farm.id) { return; }
      if (m.type === 'attendance.check_in') { s.attendance = { check_in_at: m.occurred_at, check_out_at: null, pending: true }; }
      if (m.type === 'attendance.check_out' && s.attendance) { s.attendance.check_out_at = m.occurred_at; s.attendance.pending = true; }
      if (next[m.type]) {
        (s.tasks || []).forEach(function (t) { if (t.id === m.data.task_id) { t.status = next[m.type]; t.pending = true; } });
      }
    });
    return s;
  }

  function localTime(utc) {
    var d = new Date(utc.replace(' ', 'T').slice(0, 19) + 'Z');
    return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  function render() {
    var s = view();
    var out = load(KEY_OUT, []);
    $('net').textContent = navigator.onLine ? 'online' : 'offline';
    $('net').className = 'badge ' + (navigator.onLine ? 'ok' : 'warn');
    $('queue').textContent = out.length ? out.length + ' waiting to send' : 'All sent';
    if (!s.farm) { $('farm').textContent = navigator.onLine ? 'Loading…' : 'Open this app once with a connection.'; return; }
    $('farm').textContent = s.farm.name;
    $('who').textContent = s.worker ? s.worker.name + ' · ' + s.worker.code : s.user.name;
    $('synced').textContent = s.synced_at ? 'Last updated ' + new Date(s.synced_at).toLocaleString() : '';

    var problems = load(KEY_PROBLEMS, []);
    var box = $('problems');
    box.textContent = '';
    problems.slice(-5).forEach(function (p, i) {
      var d = el('div', { class: 'flash error' }, p);
      var x = el('button', { class: 'small', type: 'button' }, 'OK');
      x.addEventListener('click', function () { var all = load(KEY_PROBLEMS, []); all.splice(problems.length - Math.min(5, problems.length) + i, 1); save(KEY_PROBLEMS, all); render(); });
      d.appendChild(document.createTextNode(' '));
      d.appendChild(x);
      box.appendChild(d);
    });

    $('attendance').hidden = !s.can.attendance;
    if (s.can.attendance) {
      var a = s.attendance;
      $('att-status').textContent = !a ? 'Not checked in yet.' : (a.check_out_at ? 'Day done: ' + localTime(a.check_in_at) + ' – ' + localTime(a.check_out_at) : 'Checked in at ' + localTime(a.check_in_at) + '.')
        + (a && a.pending ? ' (waiting to send)' : '');
      $('checkin').hidden = !!a;
      $('checkout').hidden = !a || !!a.check_out_at;
    }

    $('tasks').hidden = !s.can.tasks;
    var list = $('task-list');
    list.textContent = '';
    if (s.can.tasks && !(s.tasks || []).length) { list.appendChild(el('p', { class: 'muted' }, 'No open tasks.')); }
    (s.tasks || []).forEach(function (t) {
      var card = el('div', { class: 'task' });
      var head = el('div', { class: 'row', style: 'justify-content:space-between' });
      head.appendChild(el('b', {}, t.code + ' · ' + t.title));
      head.appendChild(el('span', { class: 'badge ' + (t.status === 'submitted' ? 'warn' : t.status === 'rejected' ? 'bad' : 'neutral') }, label(t.status) + (t.pending ? ' ⏳' : '')));
      card.appendChild(head);
      if (t.subject_label) { card.appendChild(el('div', { class: 'muted' }, t.subject_label)); }
      if (t.instructions) { card.appendChild(el('div', {}, t.instructions)); }
      card.appendChild(el('div', { class: 'muted' }, 'Due ' + t.due_on + (t.target_quantity ? ' · target ' + (+t.target_quantity) + ' ' + (t.target_unit || '') : '')));
      var row = el('div', { class: 'row' });
      function btn(text, type, cls) {
        var b = el('button', { type: 'button', class: 'small ' + (cls || '') }, text);
        b.addEventListener('click', function () { queue(type, { task_id: t.id }); });
        row.appendChild(b);
      }
      if (t.status === 'assigned' || t.status === 'rejected') { btn('Start', 'task.start', 'primary'); }
      if (t.status === 'in_progress') { btn('Pause', 'task.pause'); }
      if (t.status === 'paused') { btn('Resume', 'task.resume', 'primary'); }
      if (t.status === 'in_progress' || t.status === 'paused') {
        var f = el('form', { class: 'row' });
        var q = el('input', { name: 'quantity', inputmode: 'decimal', placeholder: 'Quantity done', style: 'max-width:9rem' });
        var u = el('input', { name: 'unit', placeholder: 'Unit', value: t.target_unit || '', style: 'max-width:6rem' });
        var n = el('input', { name: 'note', placeholder: 'Note', style: 'max-width:12rem' });
        var go = el('button', { class: 'small primary' }, 'Done');
        [q, u, n, go].forEach(function (x) { f.appendChild(x); });
        f.addEventListener('submit', function (e) {
          e.preventDefault();
          queue('task.submit', { task_id: t.id, quantity: q.value, unit: u.value, note: n.value });
        });
        row.appendChild(f);
      }
      card.appendChild(row);
      list.appendChild(card);
    });

    $('report').hidden = !s.can.reports;
    if (s.can.reports) {
      var form = $('report-form');
      fill(form.cycle_id, (s.cycles || []).map(function (c) { return [c.id, c.plot + ' · ' + c.crop + ' (' + c.code + ')']; }));
      fill(form.kind, (s.kinds || []).map(function (k) { return [k, label(k)]; }));
      fill(form.severity, (s.severities || []).map(function (k) { return [k, label(k)]; }));
    }
  }

  function fill(select, options) {
    var keep = select.value;
    if (select.options.length === options.length) { return; }
    select.textContent = '';
    options.forEach(function (o) { select.appendChild(el('option', { value: o[0] }, o[1])); });
    if (keep) { select.value = keep; }
  }

  function banner(text) {
    $('banner').hidden = !text;
    $('banner').textContent = text || '';
  }

  // Send the outbox (if any) and take the fresh state.
  function sync() {
    if (syncing || !navigator.onLine) { render(); return; }
    syncing = true;
    var out = load(KEY_OUT, []);
    var req = out.length
      ? fetch(base + 'sync.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ device_id: device, mutations: out }) })
      : fetch(base + 'sync.php', { credentials: 'same-origin' });
    req.then(function (res) {
      if (res.status === 401) {
        banner('Your sign-in has ended. Sign in again on the full site; your work stays on this phone until then.');
        return null;
      }
      if (res.status === 419) { location.reload(); return null; }
      if (!res.ok) { throw new Error('HTTP ' + res.status); }
      return res.json();
    }).then(function (body) {
      if (!body) { return; }
      banner('');
      if (body.results) {
        var problems = load(KEY_PROBLEMS, []);
        var left = load(KEY_OUT, []).filter(function (m) {
          var r = body.results[m.id];
          if (!r || r.status === 'retry') { return true; }
          if (r.status === 'rejected') { problems.push('Not saved: ' + r.message); }
          return false;
        });
        save(KEY_PROBLEMS, problems);
        save(KEY_OUT, left);
      }
      if (body.state) { save(KEY_STATE, body.state); }
    }).catch(function () {
      // No connection or the server is down: everything stays queued.
    }).then(function () {
      syncing = false;
      render();
    });
  }

  $('checkin').addEventListener('click', function () { withGps(function (g) { queue('attendance.check_in', g); }); });
  $('checkout').addEventListener('click', function () { withGps(function (g) { queue('attendance.check_out', g); }); });
  $('syncnow').addEventListener('click', sync);
  $('report-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target;
    queue('observation.create', { cycle_id: f.cycle_id.value, kind: f.kind.value, severity: f.severity.value, title: f.title.value, affected_pct: f.affected_pct.value, description: f.description.value });
    f.title.value = ''; f.affected_pct.value = ''; f.description.value = '';
  });

  // Location for attendance, when the phone allows it (never blocks the action).
  function withGps(done) {
    if (!navigator.geolocation) { done({}); return; }
    var called = false;
    $('att-status').textContent = 'Getting your location…';
    var finish = function (g) { if (!called) { called = true; done(g); } };
    navigator.geolocation.getCurrentPosition(function (p) {
      finish({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy });
    }, function () { finish({}); }, { enableHighAccuracy: true, timeout: 5000, maximumAge: 60000 });
    setTimeout(function () { finish({}); }, 6000);
  }

  window.addEventListener('online', sync);
  window.addEventListener('offline', render);
  setInterval(sync, 60000);
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(base + 'sw.js').catch(function () { /* works online without it */ });
  }
  render();
  sync();
})();
