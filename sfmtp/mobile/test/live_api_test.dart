@Tags(['live'])
library;

import 'dart:convert';
import 'dart:io';

import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sfmtp_mobile/src/api/api_client.dart';
import 'package:sfmtp_mobile/src/api/token_store.dart';
import 'package:sfmtp_mobile/src/data/database.dart';
import 'package:sfmtp_mobile/src/device/location.dart';
import 'package:sfmtp_mobile/src/sync/field_work.dart';
import 'package:sfmtp_mobile/src/sync/sync_engine.dart';

/// The offline scenario against a running API with the demo data:
///   SFMTP_LIVE_API=http://127.0.0.1:8000/api/v1 flutter test test/live_api_test.dart
/// Skipped when the variable is not set (as in CI).
void main() {
  final base = Platform.environment['SFMTP_LIVE_API'];

  test('Wilson finishes the milking offline and it reaches the server', () async {
    final db = AppDatabase(NativeDatabase.memory());
    final api = ApiClient(baseUrl: base!, tokens: MemoryTokenStore());
    final png = base64Decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    final engine = SyncEngine(db, api, readFile: (_) async => png);
    final work = FieldWork(db, const NoLocation(Place(0.4049, 32.389, 6)));

    await api.login('worker@aggfarms.test', 'Password123!', deviceName: 'Test phone');
    final farm = (await api.workspaces()).firstWhere((w) => w['name'] == 'AGG Mixed Farm');
    await db.setSetting('farm_id', farm['id'] as String);

    var report = await engine.sync();
    expect(report.ok, isTrue, reason: report.error);
    final milking = (await db.records('tasks')).firstWhere((t) => (t['activity'] as Map)['title'] == 'Morning and evening milking');
    expect(milking['status'], 'in_progress');

    // "Offline": nothing is sent until sync.
    await work.taskStep(milking['id'] as String, 'pause');
    await work.taskStep(milking['id'] as String, 'resume');
    await work.addPhoto(milking['id'] as String, '/tmp/milk.png', png);
    await work.taskStep(milking['id'] as String, 'submit', quantity: 96, unit: 'l', note: 'Bella discarded');
    await work.checkOut();
    expect((await db.pendingMutations()).length, 5);

    report = await engine.sync();
    expect(report.ok, isTrue, reason: report.error);
    final queue = await db.select(db.mediaQueue).get();
    expect(report.applied, 5, reason: '$report ${queue.map((m) => m.lastError)}');
    expect(await db.pendingMutations(), isEmpty);
    final after = await db.record('tasks', milking['id'] as String);
    expect(after!['status'], 'submitted');
    expect(after['quantity'], 96);
    expect(after['is_mine'], isTrue);
    final attendance = await db.records('attendance');
    expect(attendance.where((a) => a['check_out_at'] != null), isNotEmpty);

    // A second sync is quiet.
    report = await engine.sync();
    expect(report.applied, 0);
    await db.close();
  }, skip: base == null ? 'Set SFMTP_LIVE_API to run against a live API' : false);
}
