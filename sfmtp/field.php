<?php
/*
 * The field app: a phone page that keeps working without a connection.
 * Check in and out, work through your tasks and send crop field reports;
 * what you do offline waits on the phone and is sent when the network comes
 * back (assets/field.js, sw.js, sync.php). It can be added to the home screen.
 */
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();
if (!current_farm()) {
    redirect('index.php');
}
header('Cache-Control: no-cache');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1f7a43">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<meta name="uid" content="<?= e($user['id']) ?>">
<title>Field · <?= e(config('app_name', 'SFMTP')) ?></title>
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="icon" href="<?= e(url('assets/icon-192.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(url('assets/icon-192.png')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
</head>
<body class="field">
<header class="top"><span class="brand">🌱 Field</span><span id="net" class="badge neutral">…</span><div class="user"><a href="<?= e(url('dashboard.php')) ?>">Full site</a></div></header>
<main class="bare wide">
  <div id="banner" class="flash warn" hidden></div>
  <div class="card row" style="justify-content:space-between">
    <div><b id="farm">Loading…</b><div class="muted" id="who"></div></div>
    <div style="text-align:right"><div id="queue" class="muted"></div><button id="syncnow" class="small">Send now</button></div>
  </div>
  <div id="problems"></div>
  <section id="attendance" class="card" hidden><h2>Today</h2><p id="att-status" class="muted"></p><div class="row"><button id="checkin" class="primary">Check in</button><button id="checkout">Check out</button></div></section>
  <section id="tasks" class="card" hidden><h2>My tasks</h2><div id="task-list"></div></section>
  <section id="report" class="card" hidden><h2>Field report</h2>
    <form id="report-form" class="stack">
      <label class="field"><span>Crop</span><select name="cycle_id" required></select></label>
      <label class="field"><span>What</span><select name="kind" required></select></label>
      <label class="field"><span>How serious</span><select name="severity"></select></label>
      <label class="field"><span>Short title</span><input name="title" required maxlength="150" placeholder="e.g. Fall armyworm on the east side"></label>
      <label class="field"><span>Share of the field affected (%)</span><input name="affected_pct" inputmode="decimal"></label>
      <label class="field"><span>Details</span><input name="description" maxlength="2000"></label>
      <div class="actions"><button class="primary">Save report</button></div>
    </form>
  </section>
  <p class="muted" id="synced"></p>
</main>
<script src="<?= e(url('assets/field.js')) ?>"></script>
</body>
</html>
