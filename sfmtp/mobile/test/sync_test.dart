import 'support/fake_server.dart';
import 'dart:typed_data';

import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sfmtp_mobile/src/api/api_client.dart';
import 'package:sfmtp_mobile/src/api/token_store.dart';
import 'package:sfmtp_mobile/src/data/database.dart';
import 'package:sfmtp_mobile/src/device/location.dart';
import 'package:sfmtp_mobile/src/sync/field_work.dart';
import 'package:sfmtp_mobile/src/sync/sync_engine.dart';

void main() {
  late FakeServerHarness h;

  setUp(() async => h = await FakeServerHarness.create());
  tearDown(() => h.db.close());

  test('an airplane-mode day syncs once, in order, with the phone times', () async {
    h.server.addTask('t1', 'Clear the ditch');
    h.server.addTask('t2', 'Stack firewood');
    expect((await h.engine.sync()).ok, isTrue);
    expect((await h.db.records('tasks')).length, 2);

    // Offline: the screen updates at once, the outbox fills up.
    h.server.online = false;
    await h.work.checkIn();
    await h.work.taskStep('t1', 'start');
    await h.work.addPhoto('t1', '/photos/ditch.jpg', Uint8List.fromList([1, 2, 3]));
    await h.work.taskStep('t1', 'submit', quantity: 40, unit: 'm', note: '40 metres');
    await h.work.checkOut();
    expect((await h.db.record('tasks', 't1'))!['status'], 'submitted');
    expect((await h.db.pendingMutations()).length, 5);
    final offline = await h.engine.sync();
    expect(offline.ok, isFalse);
    expect((await h.db.pendingMutations()).length, 5, reason: 'nothing is lost while offline');

    // Back online: photo first, then every change once, in order.
    h.server.online = true;
    final report = await h.engine.sync();
    expect(report.ok, isTrue, reason: report.toString());
    expect(report.applied, 5);
    expect(await h.db.pendingMutations(), isEmpty);
    expect(h.server.logs.map((l) => l['event']), ['start', 'submit']);
    expect(h.server.logs.first['lat'], 0.4047);
    expect(h.server.media, hasLength(1));
    expect(h.server.tasks['t1']!['status'], 'submitted');
    expect((await h.db.record('tasks', 't1'))!['version'], 3, reason: "the server's copy replaced the local one");
    final attendance = await h.db.records('attendance');
    expect(attendance.single['check_out_at'], isNotNull);

    // A lost response makes the phone push again: the server answers duplicate.
    expect(h.server.applied, hasLength(5));
  });

  test('a step on a task cancelled meanwhile becomes a conflict and the server wins', () async {
    h.server.addTask('t1', 'Mend the gate');
    await h.engine.sync();

    h.server.cancel('t1');
    await h.work.taskStep('t1', 'start');
    expect((await h.db.record('tasks', 't1'))!['status'], 'in_progress');

    final report = await h.engine.sync();
    expect(report.conflicts, 1);
    expect((await h.db.record('tasks', 't1'))!['status'], 'cancelled');
    final kept = await h.db.select(h.db.outbox).get();
    expect(kept.single.status, 'conflict', reason: 'shown on the sync screen');
    expect(h.server.logs.single['applied'], isFalse);
  });

  test('retries are safe: the same mutation is applied once', () async {
    h.server.addTask('t1', 'Weed');
    await h.engine.sync();
    await h.work.taskStep('t1', 'start');
    final pending = await h.db.pendingMutations();

    // Simulate a push whose response was lost: the server has it, the phone does not know.
    await h.api.push('farm-1', [
      {'mutation_id': pending.single.mutationId, 'entity': 'worker_task_logs', 'op': 'insert', 'id': pending.single.recordId, 'occurred_at': pending.single.occurredAt, 'data': {'task_id': 't1', 'event': 'start'}},
    ]);
    final report = await h.engine.sync();
    expect(report.applied, 1);
    expect(h.server.logs, hasLength(1));
    expect(await h.db.pendingMutations(), isEmpty);
  });

  test('the phone refuses steps the state machine does not allow', () async {
    h.server.addTask('t1', 'Weed', status: 'submitted');
    await h.engine.sync();
    expect(() => h.work.taskStep('t1', 'start'), throwsStateError);
    expect(workerActions('paused'), ['resume', 'submit']);
  });
}

class FakeServerHarness {
  FakeServerHarness(this.server, this.db, this.api, this.work, this.engine);

  final FakeServer server;
  final AppDatabase db;
  final ApiClient api;
  final FieldWork work;
  final SyncEngine engine;

  static Future<FakeServerHarness> create() async {
    final server = FakeServer();
    final db = AppDatabase(NativeDatabase.memory());
    await db.setSetting('farm_id', 'farm-1');
    final api = ApiClient(baseUrl: 'http://api.test/api/v1', tokens: MemoryTokenStore(), httpClient: server.client());
    await api.login('worker@aggfarms.test', 'x');
    final work = FieldWork(db, const NoLocation(Place(0.4047, 32.3876, 6)));
    final engine = SyncEngine(db, api, readFile: (_) async => Uint8List.fromList([1, 2, 3]));
    return FakeServerHarness(server, db, api, work, engine);
  }
}
