import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:workmanager/workmanager.dart';

/// Runs the sync every 15 minutes while the app is closed (docs/08 §3).
abstract class BackgroundScheduler {
  Future<void> start();
  Future<void> stop();
}

const backgroundSyncTask = 'sfmtp-sync';

class WorkmanagerScheduler implements BackgroundScheduler {
  @override
  Future<void> start() => Workmanager().registerPeriodicTask(
        backgroundSyncTask,
        backgroundSyncTask,
        frequency: const Duration(minutes: 15),
        existingWorkPolicy: ExistingPeriodicWorkPolicy.keep,
        constraints: Constraints(networkType: NetworkType.connected),
        backoffPolicy: BackoffPolicy.exponential,
      );

  @override
  Future<void> stop() => Workmanager().cancelByUniqueName(backgroundSyncTask);
}

class NoBackground implements BackgroundScheduler {
  int starts = 0;
  int stops = 0;

  @override
  Future<void> start() async => starts++;

  @override
  Future<void> stop() async => stops++;
}

/// Phone notifications for new inbox items (task assigned, work sent back,
/// a conflict to resolve).
abstract class Alerts {
  Future<void> show(int id, String title, String? body);
}

class LocalAlerts implements Alerts {
  LocalAlerts._(this._plugin);
  final FlutterLocalNotificationsPlugin _plugin;

  static Future<LocalAlerts> init() async {
    final plugin = FlutterLocalNotificationsPlugin();
    await plugin.initialize(settings: const InitializationSettings(
      android: AndroidInitializationSettings('@mipmap/ic_launcher'),
      iOS: DarwinInitializationSettings(),
    ));
    await plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()?.requestNotificationsPermission();
    return LocalAlerts._(plugin);
  }

  @override
  Future<void> show(int id, String title, String? body) => _plugin.show(
        id: id,
        title: title,
        body: body,
        notificationDetails: const NotificationDetails(
          android: AndroidNotificationDetails('sfmtp_inbox', 'Farm notifications', channelDescription: 'Tasks, reviews and sync conflicts', importance: Importance.high),
          iOS: DarwinNotificationDetails(),
        ),
      );
}

class NoAlerts implements Alerts {
  final shown = <String>[];

  @override
  Future<void> show(int id, String title, String? body) async => shown.add(title);
}

/// Whether the phone has a network: a reconnect triggers a sync.
abstract class NetworkWatch {
  Stream<bool> get online;
}

class DeviceNetwork implements NetworkWatch {
  @override
  Stream<bool> get online => Connectivity().onConnectivityChanged.map((r) => r.any((c) => c != ConnectivityResult.none)).distinct();
}

class ManualNetwork implements NetworkWatch {
  final _c = StreamController<bool>.broadcast();

  void set(bool value) => _c.add(value);

  @override
  Stream<bool> get online => _c.stream;
}
