import 'dart:convert';

import '../data/database.dart';
import '../device/platform_services.dart';

/// Shows a phone notification for each unread inbox item not shown before.
/// Runs after every sync, in the app and in the background.
Future<int> announceNew(AppDatabase db, Alerts alerts) async {
  final shown = await _announced(db);
  final fresh = (await db.records('notifications')).where((n) => n['read_at'] == null && !shown.contains(n['id'])).toList()
    ..sort((a, b) => (a['created_at'] as String? ?? '').compareTo(b['created_at'] as String? ?? ''));
  for (final n in fresh) {
    await alerts.show((n['id'] as String).hashCode & 0x7fffffff, n['title'] as String? ?? 'SFMTP', n['body'] as String?);
  }
  await markAnnounced(db, [for (final n in fresh) n['id'] as String]);
  return fresh.length;
}

/// Notes inbox items as shown on the phone (by us, or by the system for a push).
Future<void> markAnnounced(AppDatabase db, List<String> ids) async {
  if (ids.isEmpty) return;
  final shown = (await _announced(db))..addAll(ids);
  // Keep the list short: notifications older than 30 days leave the mirror anyway.
  final keep = shown.toList();
  await db.setSetting('announced', jsonEncode(keep.length > 300 ? keep.sublist(keep.length - 300) : keep));
}

Future<Set<String>> _announced(AppDatabase db) async => ((jsonDecode(await db.setting('announced') ?? '[]') as List).cast<String>()).toSet();
