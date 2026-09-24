
import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:sfmtp_mobile/main.dart';
import 'package:sfmtp_mobile/src/api/api_client.dart';
import 'package:sfmtp_mobile/src/api/token_store.dart';
import 'package:sfmtp_mobile/src/app_state.dart';
import 'package:sfmtp_mobile/src/data/database.dart';
import 'package:sfmtp_mobile/src/device/location.dart';
import 'package:sfmtp_mobile/src/sync/field_work.dart';
import 'package:sfmtp_mobile/src/sync/sync_engine.dart';

import 'support/fake_server.dart';

void main() {
  testWidgets('sign in, see today\'s tasks, check in and start work offline-first', (tester) async {
    final server = FakeServer()..addTask('t1', 'Morning milking');
    final db = AppDatabase(DatabaseConnection(NativeDatabase.memory(), closeStreamsSynchronously: true));
    final api = ApiClient(baseUrl: 'http://api.test/api/v1', tokens: MemoryTokenStore(), httpClient: server.client());
    final state = AppState(
      db: db,
      api: api,
      work: FieldWork(db, const NoLocation(Place(0.4, 32.3, 5))),
      engine: SyncEngine(db, api, readFile: (_) async => Uint8List(0)),
      syncAfterActions: false,
    );
    await tester.runAsync(state.restore);
    await tester.pumpWidget(SfmtpApp(state: state));
    expect(find.text('Sign in'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('email')), 'worker@aggfarms.test');
    await tester.enterText(find.byKey(const Key('password')), 'Password123!');
    await tester.runAsync(() async {
      await tester.tap(find.text('Sign in'));
      await Future<void>.delayed(const Duration(milliseconds: 300));
    });
    await tester.pumpAndSettle();

    // One farm: straight to the day, with the synced task.
    expect(find.text('AGG Mixed Farm'), findsOneWidget);
    expect(find.text('Morning milking'), findsOneWidget);
    expect(find.text('To do'), findsOneWidget);

    await tester.runAsync(() async {
      await tester.tap(find.byKey(const Key('check-in')));
      await Future<void>.delayed(const Duration(milliseconds: 300));
    });
    await tester.pumpAndSettle();
    expect(find.textContaining('On site since'), findsOneWidget);

    await tester.tap(find.text('Morning milking'));
    await tester.pumpAndSettle();
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 100)));
    await tester.pumpAndSettle();
    expect(find.textContaining('To do'), findsOneWidget);
    await tester.runAsync(() async {
      await tester.tap(find.byKey(const Key('action-start')));
      await Future<void>.delayed(const Duration(milliseconds: 300));
    });
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('action-pause')), findsOneWidget);
    // Both changes wait in the outbox, in order, with the place; sync_test covers sending them.
    final queued = (await tester.runAsync(db.pendingMutations))!;
    expect(queued.map((o) => '${o.entity}.${o.op}'), ['worker_attendance.check_in', 'worker_task_logs.insert']);
    expect(queued.last.data, contains('"event":"start"'));
    expect(queued.last.data, contains('"lat":0.4'));
    await tester.pumpWidget(const SizedBox());
    state.dispose();
    await tester.runAsync(db.close);
  }, timeout: const Timeout(Duration(seconds: 30)));
}
