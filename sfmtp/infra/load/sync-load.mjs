#!/usr/bin/env node
// SFMTP load test (docs/12-operations.md §3). No dependencies: Node 20+.
//
// Phones push a working day through the real sync API (check-in, task start,
// GPS track, task submit, check-out) while managers read dashboards and the
// task list, and phones pull their changes. Prints latency percentiles and
// throughput, and exits non-zero when a budget is missed.
//
//   php artisan sync:loadtest-prepare --devices=60 --tasks=20 --out=plan.json
//   node infra/load/sync-load.mjs plan.json --api=http://localhost:8000/api/v1 --duration=120

import { randomUUID } from "node:crypto";
import { readFileSync } from "node:fs";

const args = Object.fromEntries(process.argv.slice(3).map((a) => a.replace(/^--/, "").split("=")));
const plan = JSON.parse(readFileSync(process.argv[2], "utf8"));
const API = args.api ?? "http://localhost:8000/api/v1";
const DURATION = Number(args.duration ?? 120) * 1000;
const PUSH_EVERY = Number(args["push-every"] ?? 2500); // per phone; the API allows 30 pushes a minute
const READERS = Number(args.readers ?? 4); // managers with a dashboard open
const READ_EVERY = Number(args["read-every"] ?? 2000);
const BUDGET = { push: Number(args["p95-push"] ?? 500), read: Number(args["p95-read"] ?? 800), pull: Number(args["p95-pull"] ?? 800), errors: 0.005 };

const farm = `${API}/farms/${plan.farm_id}`;
const stats = {};
let activities = 0;
let gpsPoints = 0;
const record = (kind, ms, ok) => {
  const s = (stats[kind] ??= { times: [], errors: 0 });
  s.times.push(ms);
  if (!ok) s.errors++;
};

async function call(kind, url, token, init = {}) {
  const t0 = performance.now();
  try {
    const res = await fetch(url, { ...init, headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}`, ...(init.headers ?? {}) } });
    const body = await res.json().catch(() => ({}));
    record(kind, performance.now() - t0, res.ok);
    if (!res.ok && stats[kind].errors <= 3) console.error(`${kind} ${res.status}`, JSON.stringify(body).slice(0, 200));
    return body;
  } catch (e) {
    record(kind, performance.now() - t0, false);
    return {};
  }
}

const now = () => new Date().toISOString();
const point = (i) => ({ lat: 0.40 + Math.random() / 100, lng: 32.38 + Math.random() / 100, accuracy_m: 5 + (i % 10) });
const m = (entity, op, data, extra = {}) => ({ mutation_id: randomUUID(), entity, op, id: randomUUID(), occurred_at: now(), data, ...extra });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function push(token, mutations) {
  const body = await call("push", `${farm}/sync/push`, token, { method: "POST", headers: { "Idempotency-Key": randomUUID() }, body: JSON.stringify({ mutations }) });
  const results = body?.data?.results ?? [];
  const applied = results.filter((r) => r.status === "applied" || r.status === "duplicate").length;
  if (results.length && applied < results.length && stats.push.errors < 3) console.error("rejected", JSON.stringify(results.find((r) => r.status !== "applied")).slice(0, 200));
  return applied;
}

async function phone(device, index, until) {
  await sleep(Math.random() * PUSH_EVERY); // phones do not start in step
  activities += await push(device.token, [m("worker_attendance", "check_in", point(index))]);
  let cursor = null;
  let step = 0;
  for (const taskId of device.tasks) {
    for (const event of ["start", "submit"]) {
      if (performance.now() > until) return;
      const track = Array.from({ length: 5 }, (_, i) => ({ recorded_at: now(), ...point(i), task_id: taskId }));
      const task = m("worker_task_logs", "insert", { task_id: taskId, event, ...point(step), ...(event === "submit" ? { note: "Done (load test)", quantity: 1 } : {}) });
      const gps = m("worker_gps_points", "insert", { points: track });
      const applied = await push(device.token, [task, gps]);
      activities += Math.min(applied, 1);
      gpsPoints += applied > 1 ? track.length : 0;
      if (++step % 10 === 0) {
        const pulled = await call("pull", `${farm}/sync/pull${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ""}`, device.token);
        cursor = pulled?.data?.cursor ?? cursor;
      }
      await sleep(PUSH_EVERY * (0.8 + Math.random() * 0.4));
    }
  }
  if (performance.now() < until) activities += await push(device.token, [m("worker_attendance", "check_out", point(index))]);
}

async function reader(until) {
  const pages = [`${farm}/dashboards/manager?period=today`, `${farm}/tasks?per_page=50`, `${farm}/dashboards/manager?period=30d`, `${farm}/attendance?per_page=50`, `${farm}/workers`];
  let i = 0;
  while (performance.now() < until) {
    await call("read", pages[i++ % pages.length], plan.manager_token);
    await sleep(READ_EVERY * (0.8 + Math.random() * 0.4));
  }
}

const pct = (xs, p) => {
  if (!xs.length) return 0;
  const s = [...xs].sort((a, b) => a - b);
  return s[Math.min(s.length - 1, Math.ceil((p / 100) * s.length) - 1)];
};

const started = performance.now();
const until = started + DURATION;
console.log(`Load test on ${plan.farm_code}: ${plan.devices.length} phones pushing every ~${PUSH_EVERY} ms, ${READERS} dashboard readers, ${DURATION / 1000} s`);
await Promise.all([...plan.devices.map((d, i) => phone(d, i, until)), ...Array.from({ length: READERS }, () => reader(until))]);
const seconds = (performance.now() - started) / 1000;

let failed = false;
console.log("\nkind   requests  errors   p50 ms   p95 ms   p99 ms   max ms");
for (const [kind, s] of Object.entries(stats)) {
  const p95 = pct(s.times, 95);
  const errRate = s.errors / s.times.length;
  const over = (BUDGET[kind] && p95 > BUDGET[kind]) || errRate > BUDGET.errors;
  failed ||= over;
  console.log(`${kind.padEnd(6)} ${String(s.times.length).padStart(8)} ${String(s.errors).padStart(7)} ${pct(s.times, 50).toFixed(0).padStart(8)} ${p95.toFixed(0).padStart(8)} ${pct(s.times, 99).toFixed(0).padStart(8)} ${Math.max(...s.times).toFixed(0).padStart(8)}${over ? "  ← over budget" : ""}`);
}
const perSecond = activities / seconds;
const perDay = 10000 / 86400;
console.log(`\nField activities recorded: ${activities} in ${seconds.toFixed(0)} s = ${perSecond.toFixed(2)}/s (+ ${gpsPoints} GPS points)`);
console.log(`That is ${(perSecond / perDay).toFixed(0)}× the average of 10,000 activities a day, and ${(perSecond / (perDay * 0.3 * 24)).toFixed(0)}× a peak hour carrying 30% of the day.`);
console.log(failed ? "RESULT: over budget" : `RESULT: within budget (p95 push < ${BUDGET.push} ms, reads < ${BUDGET.read} ms, errors < ${BUDGET.errors * 100}%)`);
process.exit(failed ? 1 : 0);
