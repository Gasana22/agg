import 'dart:async';
import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/widgets.dart';

import '../data/open_database.dart';
import '../sync/announcer.dart';

/// Push from the server (Firebase Cloud Messaging). A push says "something
/// new is waiting": the app syncs, and the inbox is the source of truth.
abstract class PushChannel {
  /// This phone's push token, or null when push is off.
  Future<String?> token();

  /// A new token (Firebase rotates them now and then).
  Stream<String> get tokenChanges;

  /// A push that arrived while the app is open.
  Stream<Map<String, String>> get messages;
}

/// Firebase settings come from `--dart-define`s, so no google-services.json
/// or GoogleService-Info.plist is kept in the repository:
/// SFMTP_FIREBASE_PROJECT_ID, SFMTP_FIREBASE_SENDER_ID, SFMTP_FIREBASE_API_KEY
/// and SFMTP_FIREBASE_ANDROID_APP_ID / SFMTP_FIREBASE_IOS_APP_ID.
const _projectId = String.fromEnvironment('SFMTP_FIREBASE_PROJECT_ID');
const _senderId = String.fromEnvironment('SFMTP_FIREBASE_SENDER_ID');
const _apiKey = String.fromEnvironment('SFMTP_FIREBASE_API_KEY');
const _androidAppId = String.fromEnvironment('SFMTP_FIREBASE_ANDROID_APP_ID');
const _iosAppId = String.fromEnvironment('SFMTP_FIREBASE_IOS_APP_ID');

FirebaseOptions? _options() {
  final appId = Platform.isIOS ? _iosAppId : _androidAppId;
  if (_projectId.isEmpty || _senderId.isEmpty || _apiKey.isEmpty || appId.isEmpty) return null;
  return FirebaseOptions(apiKey: _apiKey, appId: appId, messagingSenderId: _senderId, projectId: _projectId);
}

class FirebasePush implements PushChannel {
  FirebasePush._();

  /// Firebase push when the build carries its settings, otherwise [NoPush]
  /// (notifications then reach the phone with the next sync).
  static Future<PushChannel> init() async {
    final options = _options();
    if (options == null) return NoPush();
    try {
      await Firebase.initializeApp(options: options);
      FirebaseMessaging.onBackgroundMessage(pushBackgroundHandler);
      await FirebaseMessaging.instance.requestPermission();
      return FirebasePush._();
    } catch (_) {
      return NoPush();
    }
  }

  @override
  Future<String?> token() async {
    try {
      return await FirebaseMessaging.instance.getToken();
    } catch (_) {
      return null; // e.g. no Google Play services
    }
  }

  @override
  Stream<String> get tokenChanges => FirebaseMessaging.instance.onTokenRefresh;

  @override
  Stream<Map<String, String>> get messages =>
      FirebaseMessaging.onMessage.map((m) => m.data.map((k, v) => MapEntry(k, '$v')));
}

/// A push that arrives while the app is closed is shown by the system. Note
/// it as shown, so the next sync does not show it a second time.
@pragma('vm:entry-point')
Future<void> pushBackgroundHandler(RemoteMessage message) async {
  final id = message.data['notification_id'];
  if (id == null || message.notification == null) return;
  WidgetsFlutterBinding.ensureInitialized();
  try {
    final db = await openAppDatabase(background: true);
    try {
      await markAnnounced(db, [id as String]);
    } finally {
      await db.close();
    }
  } catch (_) {
    // At worst the next sync shows it once more.
  }
}

class NoPush implements PushChannel {
  NoPush([this.value]);

  String? value;
  final _tokens = StreamController<String>.broadcast();
  final _messages = StreamController<Map<String, String>>.broadcast();

  void rotate(String token) {
    value = token;
    _tokens.add(token);
  }

  void deliver(Map<String, String> data) => _messages.add(data);

  @override
  Future<String?> token() async => value;

  @override
  Stream<String> get tokenChanges => _tokens.stream;

  @override
  Stream<Map<String, String>> get messages => _messages.stream;
}
