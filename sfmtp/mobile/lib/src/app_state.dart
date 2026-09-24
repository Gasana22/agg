import 'dart:async';

import 'package:flutter/widgets.dart';

import 'api/api_client.dart';
import 'data/database.dart';
import 'sync/field_work.dart';
import 'sync/sync_engine.dart';

/// App-wide state: who is signed in, which farm, and the sync loop.
/// Syncs on start, when the app comes back to the foreground, every
/// 15 minutes while open, after each action, and on "Sync now".
class AppState extends ChangeNotifier with WidgetsBindingObserver {
  AppState({required this.db, required this.api, required this.work, required this.engine, this.syncAfterActions = true});

  final AppDatabase db;
  final ApiClient api;
  final FieldWork work;
  final SyncEngine engine;

  /// Off in widget tests, which sync explicitly.
  final bool syncAfterActions;

  bool signedIn = false;
  String? farmId;
  String? farmName;
  bool syncing = false;
  SyncReport? lastReport;
  Timer? _timer;

  Future<void> restore() async {
    signedIn = await api.tokens.refreshToken() != null;
    farmId = await db.setting('farm_id');
    farmName = await db.setting('farm_name');
    if (signedIn && farmId != null) _startLoop();
    notifyListeners();
  }

  Future<List<Map<String, dynamic>>> signIn(String email, String password) async {
    await api.login(email, password);
    signedIn = true;
    notifyListeners();
    final farms = (await api.workspaces()).where((w) => w['type'] == 'farm').toList();
    return farms;
  }

  Future<void> chooseFarm(String id, String name) async {
    if (id != farmId) {
      await db.clearFarmData();
      await db.setSetting('farm_id', id);
      await db.setSetting('farm_name', name);
    }
    farmId = id;
    farmName = name;
    notifyListeners();
    _startLoop();
    await syncNow();
  }

  Future<void> signOut() async {
    _timer?.cancel();
    final unsent = (await db.pendingMutations()).isNotEmpty;
    if (!unsent) await db.clearFarmData();
    await api.logout();
    signedIn = false;
    farmId = null;
    notifyListeners();
  }

  Future<void> syncNow() async {
    if (syncing) return;
    syncing = true;
    notifyListeners();
    try {
      lastReport = await engine.sync();
    } finally {
      syncing = false;
      notifyListeners();
    }
  }

  /// Run a worker action, then try to sync in the background.
  Future<void> act(Future<void> Function(FieldWork work) action) async {
    await action(work);
    if (syncAfterActions) unawaited(syncNow());
  }

  void _startLoop() {
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(minutes: 15), (_) => syncNow());
    WidgetsBinding.instance.removeObserver(this);
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && signedIn && farmId != null) syncNow();
  }

  @override
  void dispose() {
    _timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }
}
