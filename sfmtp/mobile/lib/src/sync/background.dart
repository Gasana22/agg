import 'dart:io';

import 'package:flutter/widgets.dart';
import 'package:workmanager/workmanager.dart';

import '../api/api_client.dart';
import '../api/token_store.dart';
import '../data/database.dart';
import '../data/open_database.dart';
import '../device/platform_services.dart';
import 'announcer.dart';
import 'sync_engine.dart';

/// Entry point of the background isolate (WorkManager on Android, BGTask on
/// iOS). It opens the same encrypted database, syncs once and shows
/// notifications for anything new. A push made here and one from the app
/// at the same moment are harmless: the server answers the second with
/// `duplicate`.
@pragma('vm:entry-point')
void backgroundDispatcher() {
  Workmanager().executeTask((task, input) async {
    WidgetsFlutterBinding.ensureInitialized();
    final apiUrl = input?['api_url'] as String? ?? const String.fromEnvironment('SFMTP_API_URL', defaultValue: 'http://10.0.2.2:8000/api/v1');
    final AppDatabase db;
    try {
      db = await openAppDatabase(background: true);
    } on DatabaseUnavailable {
      return true; // the app syncs when it next opens
    }
    try {
      if (await db.setting('farm_id') == null) return true;
      final api = ApiClient(baseUrl: apiUrl, tokens: SecureTokenStore());
      final report = await SyncEngine(db, api, readFile: (path) => File(path).readAsBytes()).sync();
      if (report.errorCode == 'device_revoked') {
        await db.wipe();
        return true;
      }
      await announceNew(db, await LocalAlerts.init());
      // A network error is retried by WorkManager with back-off.
      return report.errorCode != 'network';
    } finally {
      await db.close();
    }
  });
}
