import 'dart:async';
import 'dart:convert';

import 'package:flutter/widgets.dart';

import 'api/api_client.dart';
import 'data/database.dart';
import 'device/platform_services.dart';
import 'device/push.dart';
import 'sync/announcer.dart';
import 'sync/field_work.dart';
import 'sync/sync_engine.dart';

/// A farm the member may open, with their permissions there.
class FarmChoice {
  FarmChoice(this.id, this.name, this.permissions);
  final String id;
  final String name;
  final Map<String, String> permissions;

  static FarmChoice fromWorkspace(Map<String, dynamic> w) =>
      FarmChoice(w['id'] as String, w['name'] as String, ((w['permissions'] as Map?) ?? {}).map((k, v) => MapEntry(k as String, v as String)));
}

/// App-wide state: who is signed in, which farm and what they may do there,
/// and the sync loop. Syncs on start, when the app comes back to the
/// foreground, when the network returns, every 15 minutes (in the
/// background too), after each action, and on "Sync now".
class AppState extends ChangeNotifier with WidgetsBindingObserver {
  AppState({
    required this.db,
    required this.api,
    required this.work,
    required this.engine,
    this.syncAfterActions = true,
    BackgroundScheduler? background,
    Alerts? alerts,
    NetworkWatch? network,
    PushChannel? push,
  })  : background = background ?? NoBackground(),
        alerts = alerts ?? NoAlerts(),
        network = network ?? ManualNetwork(),
        push = push ?? NoPush();

  final AppDatabase db;
  final ApiClient api;
  final FieldWork work;
  final SyncEngine engine;
  final BackgroundScheduler background;
  final Alerts alerts;
  final NetworkWatch network;
  final PushChannel push;

  /// Off in widget tests, which sync explicitly.
  final bool syncAfterActions;

  bool signedIn = false;
  String? farmId;
  String? farmName;
  Map<String, String> permissions = {};
  bool syncing = false;
  SyncReport? lastReport;

  /// Set when the phone was signed out remotely and its data wiped.
  String? notice;
  Timer? _timer;
  StreamSubscription<bool>? _net;
  StreamSubscription<String>? _pushToken;
  StreamSubscription<Map<String, String>>? _pushMessages;

  /// Holds the permission, or any of `a|b`.
  bool can(String permission) => permission.split('|').any(permissions.containsKey);

  Future<void> restore() async {
    signedIn = await api.tokens.refreshToken() != null;
    farmId = await db.setting('farm_id');
    farmName = await db.setting('farm_name');
    permissions = _decodePermissions(await db.setting('permissions'));
    if (signedIn && farmId != null) _startLoop();
    notifyListeners();
  }

  /// Signs in. Returns an MFA token when a code is needed, otherwise null.
  Future<String?> signIn(String email, String password) async {
    final mfa = await api.login(email, password);
    if (mfa == null) {
      signedIn = true;
      notice = null;
      notifyListeners();
    }
    return mfa;
  }

  Future<void> confirmCode(String mfaToken, String code) async {
    await api.mfaChallenge(mfaToken, code);
    signedIn = true;
    notice = null;
    notifyListeners();
  }

  Future<List<FarmChoice>> farms() async => [for (final w in await api.workspaces()) if (w['type'] == 'farm') FarmChoice.fromWorkspace(w)];

  Future<void> chooseFarm(FarmChoice farm) async {
    if (farm.id != farmId) {
      await db.clearFarmData();
      await db.setSetting('farm_id', farm.id);
      await db.setSetting('farm_name', farm.name);
    }
    await db.setSetting('permissions', jsonEncode(farm.permissions));
    farmId = farm.id;
    farmName = farm.name;
    permissions = farm.permissions;
    notifyListeners();
    _startLoop();
    await syncNow();
  }

  Future<void> signOut() async {
    _stopLoop();
    final unsent = (await db.pendingMutations()).isNotEmpty;
    if (!unsent) await db.clearFarmData();
    await api.logout();
    await db.setSetting('push_token', null);
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
      if (lastReport!.errorCode == 'device_revoked') {
        await _wipe();
        return;
      }
      await announceNew(db, alerts);
    } finally {
      syncing = false;
      notifyListeners();
    }
  }

  /// Run an action, then try to sync in the background.
  Future<void> act(Future<void> Function(FieldWork work) action) async {
    await action(work);
    if (syncAfterActions) unawaited(syncNow());
  }

  /// docs/08 §5: a device signed out from the web keeps nothing.
  Future<void> _wipe() async {
    _stopLoop();
    await db.wipe();
    await api.tokens.clear();
    signedIn = false;
    farmId = null;
    farmName = null;
    permissions = {};
    notice = 'This phone was signed out from the web, so its farm data was removed. Sign in again to continue.';
  }

  void _startLoop() {
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(minutes: 15), (_) => syncNow());
    _net?.cancel();
    _net = network.online.listen((online) {
      if (online && signedIn && farmId != null) syncNow();
    });
    unawaited(background.start());
    _pushToken?.cancel();
    _pushToken = push.tokenChanges.listen((t) => unawaited(_registerPush(t)));
    _pushMessages?.cancel();
    // A push while the app is open: fetch it now (the sync shows it).
    _pushMessages = push.messages.listen((_) {
      if (signedIn && farmId != null) syncNow();
    });
    unawaited(push.token().then(_registerPush));
    WidgetsBinding.instance.removeObserver(this);
    WidgetsBinding.instance.addObserver(this);
  }

  /// Tells the server where to push; once per token.
  Future<void> _registerPush(String? token) async {
    if (token == null || token == await db.setting('push_token')) return;
    try {
      await api.registerPushToken(token);
      await db.setSetting('push_token', token);
    } on ApiException {
      // Offline or refused: tried again on the next start.
    }
  }

  void _stopLoop() {
    _timer?.cancel();
    _net?.cancel();
    _pushToken?.cancel();
    _pushMessages?.cancel();
    unawaited(background.stop());
  }

  static Map<String, String> _decodePermissions(String? json) =>
      json == null ? {} : (jsonDecode(json) as Map).map((k, v) => MapEntry(k as String, v as String));

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && signedIn && farmId != null) syncNow();
  }

  @override
  void dispose() {
    _timer?.cancel();
    _net?.cancel();
    _pushToken?.cancel();
    _pushMessages?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }
}
