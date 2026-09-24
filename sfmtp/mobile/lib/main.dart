import 'dart:io';

import 'package:drift_flutter/drift_flutter.dart';
import 'package:flutter/material.dart';

import 'src/api/api_client.dart';
import 'src/api/token_store.dart';
import 'src/app_state.dart';
import 'src/data/database.dart';
import 'src/device/location.dart';
import 'src/sync/field_work.dart';
import 'src/sync/sync_engine.dart';
import 'src/ui/login_screen.dart';
import 'src/ui/scope.dart';
import 'src/ui/today_screen.dart';

/// The API, e.g. `--dart-define=SFMTP_API_URL=https://api.example.com/api/v1`.
/// The default reaches a local API from the Android emulator.
const apiUrl = String.fromEnvironment('SFMTP_API_URL', defaultValue: 'http://10.0.2.2:8000/api/v1');

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final db = AppDatabase(driftDatabase(name: 'sfmtp'));
  final api = ApiClient(baseUrl: apiUrl, tokens: SecureTokenStore());
  final state = AppState(
    db: db,
    api: api,
    work: FieldWork(db, DeviceLocation()),
    engine: SyncEngine(db, api, readFile: (path) => File(path).readAsBytes()),
  );
  await state.restore();
  runApp(SfmtpApp(state: state));
  if (state.signedIn && state.farmId != null) state.syncNow();
}

class SfmtpApp extends StatelessWidget {
  const SfmtpApp({super.key, required this.state});
  final AppState state;

  @override
  Widget build(BuildContext context) {
    return AppScope(
      state: state,
      child: MaterialApp(
        title: 'SFMTP',
        theme: ThemeData(colorSchemeSeed: const Color(0xFF1E7B3E), useMaterial3: true),
        home: const _Home(),
      ),
    );
  }
}

class _Home extends StatelessWidget {
  const _Home();

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return app.signedIn && app.farmId != null ? const TodayScreen() : const LoginScreen();
  }
}
