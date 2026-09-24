import 'dart:io';

import 'package:drift/drift.dart' hide isNull, isNotNull;
import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sfmtp_mobile/main.dart';
import 'package:sfmtp_mobile/src/api/api_client.dart';
import 'package:sfmtp_mobile/src/api/token_store.dart';
import 'package:sfmtp_mobile/src/app_state.dart';
import 'package:sfmtp_mobile/src/data/database.dart';
import 'package:sfmtp_mobile/src/data/open_database.dart';
import 'package:sfmtp_mobile/src/device/location.dart';
import 'package:sfmtp_mobile/src/device/platform_services.dart';
import 'package:sfmtp_mobile/src/device/push.dart';
import 'package:sfmtp_mobile/src/sync/announcer.dart';
import 'package:sfmtp_mobile/src/sync/field_work.dart';
import 'package:sfmtp_mobile/src/sync/sync_engine.dart';
import 'package:sqlite3/sqlite3.dart';

import 'support/fake_server.dart';

class MemoryKeys implements KeyStore {
  String? key;

  @override
  Future<String?> read() async => key;

  @override
  Future<void> write(String k) async => key = k;
}

const agronomist = {'crops.plans.view': 'all', 'crops.operations.record': 'all', 'structure.view': 'all'};
const keeper = {'livestock.animals.view': 'all', 'livestock.animals.manage': 'all', 'livestock.records.record': 'all'};
const manager = {'tasks.view': 'all', 'tasks.verify': 'all', 'workers.view': 'all'};

class Rig {
  Rig(this.server, {Map<String, String>? permissions}) {
    if (permissions != null) server.permissions = permissions;
    db = AppDatabase(DatabaseConnection(NativeDatabase.memory(), closeStreamsSynchronously: true));
    api = ApiClient(baseUrl: 'http://api.test/api/v1', tokens: MemoryTokenStore(), httpClient: server.client());
    state = AppState(
      db: db,
      api: api,
      work: FieldWork(db, const NoLocation(Place(0.34, 32.58, 6))),
      engine: SyncEngine(db, api, readFile: (_) async => Uint8List(0)),
      syncAfterActions: false,
      background: background,
      alerts: alerts,
      network: network,
      push: push,
    );
  }

  final FakeServer server;
  late final AppDatabase db;
  late final ApiClient api;
  late final AppState state;
  final background = NoBackground();
  final alerts = NoAlerts();
  final network = ManualNetwork();
  final push = NoPush('token-1');

  Future<void> signIn() async {
    await state.signIn('someone@aggfarms.test', 'x');
    await state.chooseFarm((await state.farms()).single);
  }

  Future<List<OutboxEntry>> outbox() => db.select(db.outbox).get();
}

void main() {
  // Each test opens its own in-memory database on purpose.
  driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;

  group('encrypted database (docs/08 §5)', () {
    test('the key is made once and the file is unreadable without it', () async {
      final keys = MemoryKeys();
      final key = await databaseKey(keys);
      expect(key, hasLength(64));
      expect(await databaseKey(keys), key, reason: 'the same key on every start');

      final dir = Directory.systemTemp.createTempSync('sfmtp');
      final file = File('${dir.path}/app.sqlite');
      final db = AppDatabase(encryptedExecutor(file, key));
      await db.setSetting('farm_name', 'Plain-text-marker farm');
      await db.close();

      expect(String.fromCharCodes(file.readAsBytesSync()).contains('Plain-text-marker'), isFalse, reason: 'nothing readable on disk');
      final wrong = AppDatabase(encryptedExecutor(file, 'ff' * 32));
      await expectLater(wrong.setting('farm_name'), throwsA(anything), reason: 'a wrong key cannot open it');
      await wrong.close();
      final again = AppDatabase(encryptedExecutor(file, key));
      expect(await again.setting('farm_name'), 'Plain-text-marker farm');
      await again.close();
    });

    test('the background never makes a key; a lost key starts the app empty', () async {
      final dir = Directory.systemTemp.createTempSync('sfmtp');
      final keys = MemoryKeys();
      await expectLater(openAppDatabase(keys: keys, background: true, directory: dir), throwsA(isA<DatabaseUnavailable>()));
      expect(keys.key, isNull, reason: 'a key it cannot read yet is not replaced');

      var db = await openAppDatabase(keys: keys, directory: dir);
      await db.setSetting('farm_id', 'farm-1');
      await db.close();
      db = await openAppDatabase(keys: keys, background: true, directory: dir);
      expect(await db.setting('farm_id'), 'farm-1', reason: 'the background opens it with the same key');
      await db.close();

      keys.key = null; // e.g. restored to a new phone without the Keychain
      await expectLater(openAppDatabase(keys: MemoryKeys()..key = 'cd' * 32, background: true, directory: dir), throwsA(isA<DatabaseUnavailable>()));
      db = await openAppDatabase(keys: keys, directory: dir);
      expect(await db.setting('farm_id'), isNull, reason: 'starts empty instead of failing');
      await db.close();
    });

    test('a v0 plain database is encrypted in place with its unsent work', () async {
      final dir = Directory.systemTemp.createTempSync('sfmtp');
      final plain = File('${dir.path}/sfmtp.sqlite');
      final v0 = AppDatabase(NativeDatabase(plain));
      await v0.setSetting('farm_id', 'farm-1');
      await v0.close();
      expect(String.fromCharCodes(plain.readAsBytesSync()).contains('farm-1'), isTrue);

      final target = File('${dir.path}/sfmtp_secure.sqlite');
      encryptPlainDatabase(plain, target, 'ab' * 32);
      expect(plain.existsSync(), isFalse);
      expect(String.fromCharCodes(target.readAsBytesSync()).contains('farm-1'), isFalse);
      expect(() => sqlite3.open(target.path).select('SELECT * FROM sync_state'), throwsA(anything));
      final db = AppDatabase(encryptedExecutor(target, 'ab' * 32));
      expect(await db.setting('farm_id'), 'farm-1');
      await db.close();
    });
  });

  test('the agronomist records field work offline; it syncs on reconnect with the field times', () async {
    final server = FakeServer()..put('crop_cycles', {'id': 'cc1', 'code': 'CC-001', 'stage': 'growing', 'crop': {'label': 'Maize'}, 'plot': {'code': 'B-3'}, 'area_ha': 2.5});
    final rig = Rig(server, permissions: agronomist);
    await rig.signIn();
    expect(rig.background.starts, 1, reason: 'background sync is scheduled after choosing the farm');
    expect(await rig.db.records('crop_cycles'), hasLength(1));

    server.online = false;
    await rig.state.act((w) => w.recordOperation('cc1', type: 'spraying', labourHours: 3, inputs: [
          {'product_name': 'Emamectin benzoate', 'quantity': 0.4, 'unit': 'kg', 'withholding_days': 14},
        ]));
    await rig.state.act((w) => w.reportObservation('cc1', kind: 'pest', severity: 'high', title: 'Fall armyworm', affectedPct: 15));
    await rig.state.syncNow();
    expect(rig.state.lastReport!.error, contains('Offline'));
    expect(await rig.outbox(), hasLength(2));

    server.online = true;
    rig.network.set(true);
    await Future<void>.delayed(const Duration(milliseconds: 50));
    expect(await rig.outbox(), isEmpty, reason: 'the reconnect triggers a sync');
    final op = server.inserted['crop_operations']!.single;
    expect(op['type'], 'spraying');
    expect(op['occurred_at'], isNotNull);
    expect((op['inputs'] as List).single['withholding_days'], 14);
    expect(server.inserted['crop_observations']!.single['latitude'], 0.34);
  });

  test('two edits of one animal: merged fields go in, the rest is resolved from the inbox', () async {
    final server = FakeServer()..put('animals', {'id': 'a1', 'animal_code': 'COW-004', 'name': 'Bella', 'tag_number': 'UG-1001', 'notes': null});
    final rig = Rig(server, permissions: keeper);
    await rig.signIn();

    // Offline on the phone: new tag and a note. Meanwhile the web changes the note.
    await rig.state.work.editAnimal('a1', {'tag_number': 'UG-2002', 'notes': 'Limps on left hind'});
    server.change('animals', 'a1', {'notes': 'Due for hoof trimming'});
    expect((await rig.db.record('animals', 'a1'))!['notes'], 'Limps on left hind', reason: 'the phone shows its own edit while unsent');

    await rig.state.syncNow();
    expect(rig.state.lastReport!.conflicts, 1);
    expect(await rig.outbox(), isEmpty, reason: 'the conflict now lives on the server, not in the outbox');
    final animal = (await rig.db.record('animals', 'a1'))!;
    expect(animal['tag_number'], 'UG-2002', reason: 'changed on one side only: merged');
    expect(animal['notes'], 'Due for hoof trimming', reason: 'changed on both: the server keeps its value until resolved');
    final conflict = (await rig.db.records('conflicts')).single;
    expect((conflict['fields'] as List).single['field'], 'notes');
    expect(rig.alerts.shown, ['Choose which details to keep for COW-004'], reason: 'a phone notification for the conflict');

    await rig.state.work.resolveConflict(conflict['id'] as String, {'notes': 'mine'});
    await rig.state.syncNow();
    expect(server.records['animals']!['a1']!['notes'], 'Limps on left hind');
    expect(await rig.db.records('conflicts'), isEmpty, reason: 'a resolved conflict leaves the phone');
    expect(rig.alerts.shown, hasLength(1), reason: 'the same notification is not shown twice');
  });

  test('the manager checks work offline; a check made too late is a conflict', () async {
    final server = FakeServer()
      ..put('team_tasks', {'id': 'tt1', 'code': 'TSK-001', 'status': 'submitted', 'activity': {'title': 'Dig the trench'}, 'worker': {'full_name': 'Okello'}})
      ..put('team_tasks', {'id': 'tt2', 'code': 'TSK-002', 'status': 'submitted', 'activity': {'title': 'Mend the fence'}, 'worker': {'full_name': 'Okello'}});
    final rig = Rig(server, permissions: manager);
    await rig.signIn();

    await rig.state.work.reviewTask('tt1', approve: true, note: 'Good');
    expect(() => rig.state.work.reviewTask('tt2', approve: false), throwsStateError, reason: 'sending back needs a reason');
    await rig.state.work.reviewTask('tt2', approve: false, note: 'Posts are loose');
    server.change('team_tasks', 'tt2', {'status': 'verified'}); // the owner approved it from the web meanwhile
    await rig.state.syncNow();

    expect(server.records['team_tasks']!['tt1']!['status'], 'verified');
    expect(server.records['team_tasks']!['tt2']!['status'], 'verified', reason: 'the late rejection is not applied');
    final left = await rig.outbox();
    expect(left.single.status, 'conflict');
    expect(await rig.db.records('team_tasks'), isEmpty, reason: 'nothing left to check');
  });

  test('push: the token is registered once, a push while open syncs, and a push the system showed is not shown again', () async {
    final server = FakeServer();
    final rig = Rig(server, permissions: keeper);
    await rig.signIn();
    await Future<void>.delayed(const Duration(milliseconds: 20));
    expect(server.pushTokens, ['token-1']);

    await rig.state.restore(); // the next app start
    await Future<void>.delayed(const Duration(milliseconds: 20));
    expect(server.pushTokens, ['token-1'], reason: 'an unchanged token is not sent again');
    rig.push.rotate('token-2');
    await Future<void>.delayed(const Duration(milliseconds: 20));
    expect(server.pushTokens, ['token-1', 'token-2']);

    // Open app: the push triggers a sync, which shows the notification.
    server.notify('task_assigned', 'New task: Dip the goats');
    rig.push.deliver({'notification_id': 'n-0', 'kind': 'task_assigned'});
    await Future<void>.delayed(const Duration(milliseconds: 50));
    expect(rig.alerts.shown, ['New task: Dip the goats']);

    // Closed app: the system showed it; the background handler notes it.
    server.notify('task_verified', 'Work approved');
    final id = (server.records['notifications']!.keys).last;
    await markAnnounced(rig.db, [id]);
    await rig.state.syncNow();
    expect(rig.alerts.shown, ['New task: Dip the goats'], reason: 'not shown a second time');
    rig.state.dispose();
  });

  test('a device signed out from the web wipes its data', () async {
    final server = FakeServer()..addTask('t1', 'Morning milking');
    final rig = Rig(server);
    await rig.signIn();
    await rig.state.work.taskStep('t1', 'start');
    expect(await rig.db.records('tasks'), isNotEmpty);

    server.revoked = true;
    await rig.state.syncNow();
    expect(rig.state.signedIn, isFalse);
    expect(rig.state.notice, contains('signed out from the web'));
    expect(await rig.db.records('tasks'), isEmpty);
    expect(await rig.outbox(), isEmpty);
    expect(await rig.db.setting('farm_id'), isNull);
    expect(rig.background.stops, 1);
    expect(await rig.api.tokens.refreshToken(), isNull);
  });

  test('1,000 queued mutations sync within 60 s on a 3G link', () async {
    final server = FakeServer()..put('animals', {'id': 'a1', 'animal_code': 'COW-004', 'name': 'Bella'});
    final rig = Rig(server, permissions: keeper);
    await rig.signIn();
    server.online = false;
    for (var i = 0; i < 1000; i++) {
      await rig.state.work.recordWeight('a1', 300 + i / 10);
    }
    expect(await rig.outbox(), hasLength(1000));

    server.online = true;
    server.use3g();
    server.pushes = 0;
    final started = DateTime.now();
    await rig.state.syncNow();
    final local = DateTime.now().difference(started);

    expect(await rig.outbox(), isEmpty);
    expect(server.inserted['animal_weights'], hasLength(1000));
    expect(server.pushes, 5, reason: 'batches of 200');
    final total = server.simulated + local;
    // ignore: avoid_print
    print('1,000 mutations: ${server.simulated.inMilliseconds} ms on the simulated 3G link + ${local.inMilliseconds} ms on the phone');
    expect(total, lessThan(const Duration(seconds: 60)));
  });

  testWidgets('the tabs follow the member\'s permissions; a code completes sign-in', (tester) async {
    final server = FakeServer()
      ..mfa = true
      ..put('crop_cycles', {'id': 'cc1', 'code': 'CC-001', 'stage': 'growing', 'crop': {'label': 'Maize'}, 'plot': {'code': 'B-3'}, 'area_ha': 2.5})
      ..put('team_tasks', {'id': 'tt1', 'code': 'TSK-001', 'status': 'submitted', 'activity': {'title': 'Dig the trench'}, 'worker': {'full_name': 'Okello'}});
    final rig = Rig(server, permissions: {...agronomist, ...manager});
    await tester.runAsync(rig.state.restore);
    await tester.pumpWidget(SfmtpApp(state: rig.state));

    await tester.enterText(find.byKey(const Key('email')), 'agronomist@aggfarms.test');
    await tester.enterText(find.byKey(const Key('password')), 'Password123!');
    await tester.runAsync(() async {
      await tester.tap(find.text('Sign in'));
      await Future<void>.delayed(const Duration(milliseconds: 100));
    });
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('code')), findsOneWidget);
    await tester.enterText(find.byKey(const Key('code')), '123456');
    await tester.runAsync(() async {
      await tester.tap(find.byKey(const Key('confirm-code')));
      await Future<void>.delayed(const Duration(milliseconds: 300));
    });
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('tab-crops')), findsOneWidget);
    expect(find.byKey(const Key('tab-team')), findsOneWidget);
    expect(find.byKey(const Key('tab-inbox')), findsOneWidget);
    expect(find.byKey(const Key('tab-today')), findsNothing, reason: 'not a field worker');
    expect(find.byKey(const Key('tab-animals')), findsNothing);
    expect(find.text('Maize · Plot B-3'), findsOneWidget);

    await tester.tap(find.byKey(const Key('tab-team')));
    await tester.pumpAndSettle();
    expect(find.text('Dig the trench'), findsOneWidget);
  });

  testWidgets('resolving a conflict from the inbox', timeout: const Timeout(Duration(seconds: 60)), (tester) async {
    final server = FakeServer()
      ..put('animals', {'id': 'a1', 'animal_code': 'COW-004', 'name': 'Bella', 'notes': 'Theirs'})
      ..put('conflicts', {'id': 'c1', 'entity': 'animals', 'record_id': 'a1', 'label': 'COW-004 Bella', 'status': 'open', 'fields': [
        {'field': 'notes', 'base': null, 'mine': 'Mine', 'server': 'Theirs'},
      ]});
    final rig = Rig(server, permissions: keeper);
    await tester.runAsync(rig.signIn);
    await tester.pumpWidget(SfmtpApp(state: rig.state));
    // Drift streams need real time; pump in small real-time steps.
    Future<void> settle() async {
      for (var i = 0; i < 5; i++) {
        await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 40)));
        await tester.pump(const Duration(milliseconds: 100));
      }
    }

    await settle();
    await tester.tap(find.byKey(const Key('tab-inbox')));
    await settle();
    await tester.tap(find.byKey(const Key('conflict-c1')));
    await settle();
    expect(find.text('Mine: Mine'), findsOneWidget);
    expect(find.text('Theirs: Theirs'), findsOneWidget);
    await tester.tap(find.byKey(const Key('keep-mine-notes')));
    await tester.pump();
    await tester.runAsync(() async {
      await tester.tap(find.byKey(const Key('resolve')));
      await Future<void>.delayed(const Duration(milliseconds: 200));
    });
    await settle();
    expect(find.byKey(const Key('conflict-c1')), findsNothing, reason: 'resolved conflicts leave the inbox');

    // Unmount first: live drift streams re-query in the fake-async zone and
    // would hold the database lock while the test reads it in real time.
    await tester.pumpWidget(const SizedBox());
    await tester.pump();
    await tester.runAsync(() async {
      expect((await rig.outbox()).single.entity, 'sync_conflicts');
      await rig.state.syncNow();
    });
    expect(server.records['animals']!['a1']!['notes'], 'Mine');
    rig.state.dispose();
  });
}
