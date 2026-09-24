@Tags(['live'])
library;

import 'dart:convert';
import 'dart:io';

import 'package:drift/drift.dart' hide isNull, isNotNull;
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
  driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;
  final base = Platform.environment['SFMTP_LIVE_API'];
  final skip = base == null ? 'Set SFMTP_LIVE_API to run against a live API' : false;

  /// A phone signed in to the demo farm, synced once.
  Future<(AppDatabase, SyncEngine, FieldWork)> phone(String email, String name, {String farmName = 'AGG Mixed Farm'}) async {
    final db = AppDatabase(NativeDatabase.memory());
    final api = ApiClient(baseUrl: base!, tokens: MemoryTokenStore());
    final engine = SyncEngine(db, api, readFile: (_) async => Uint8List(0));
    await api.login(email, 'Password123!', deviceName: name);
    final farm = (await api.workspaces()).firstWhere((w) => w['name'] == farmName);
    await db.setSetting('farm_id', farm['id'] as String);
    final report = await engine.sync();
    expect(report.ok, isTrue, reason: report.error);
    return (db, engine, FieldWork(db, const NoLocation(Place(0.4049, 32.389, 6))));
  }

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
  }, skip: skip);

  test('Amina records a spray and a pest offline; both reach the crop cycle', () async {
    final (db, engine, work) = await phone('agronomist@aggfarms.test', 'Agronomist phone', farmName: 'AGG Crop Farm');
    final cycles = await db.records('crop_cycles');
    expect(cycles, isNotEmpty, reason: 'the agronomist gets the crop cycles on the phone');
    final cycle = cycles.first['id'] as String;

    await work.recordOperation(cycle, type: 'spraying', labourHours: 2, notes: 'Offline spray');
    await work.reportObservation(cycle, kind: 'pest', severity: 'medium', title: 'Aphids on the lower leaves', affectedPct: 5);
    final report = await engine.sync();
    expect(report.ok, isTrue, reason: report.error);
    expect(report.applied, 2, reason: '$report');
    expect(await db.pendingMutations(), isEmpty);
    await db.close();
  }, skip: skip);

  test('two phones edit one animal: one field merges, the other is a conflict', () async {
    final (db1, engine1, work1) = await phone('livestock@aggfarms.test', 'Livestock phone 1');
    final (db2, engine2, work2) = await phone('livestock@aggfarms.test', 'Livestock phone 2');
    final animal = (await db1.records('animals')).firstWhere((a) => a['status'] == 'active');
    final id = animal['id'] as String;
    final stamp = DateTime.now().microsecondsSinceEpoch;

    await work1.editAnimal(id, {'notes': 'Phone one $stamp'});
    await work2.editAnimal(id, {'notes': 'Phone two $stamp', 'breed_note': 'Cross $stamp'});
    expect((await engine1.sync()).ok, isTrue);
    final report = await engine2.sync();
    expect(report.conflicts, 1, reason: '$report');

    // The pull holds back changes younger than the lag (2 s): the next sync
    // brings the conflict and its notification.
    await Future<void>.delayed(const Duration(seconds: 3));
    await engine2.sync();
    final merged = (await db2.record('animals', id))!;
    expect(merged['breed_note'], 'Cross $stamp', reason: 'changed on one phone only: merged');
    expect(merged['notes'], 'Phone one $stamp', reason: 'changed on both: the first to sync stays until resolved');
    final conflict = (await db2.records('conflicts')).firstWhere((c) => c['record_id'] == id && '${c['fields']}'.contains('$stamp'));
    expect((conflict['fields'] as List).single['field'], 'notes');
    expect((await db2.records('notifications')).where((n) => n['kind'] == 'sync_conflict'), isNotEmpty);

    await work2.resolveConflict(conflict['id'] as String, {'notes': 'mine'});
    expect((await engine2.sync()).ok, isTrue);
    await Future<void>.delayed(const Duration(seconds: 3));
    await engine1.sync();
    expect((await db1.record('animals', id))!['notes'], 'Phone two $stamp', reason: 'the other phone gets the resolved value');
    await db1.close();
    await db2.close();
  }, skip: skip);
}
