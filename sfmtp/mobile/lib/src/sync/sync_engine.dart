import 'dart:convert';

import 'package:drift/drift.dart';

import '../api/api_client.dart';
import '../data/database.dart';

class SyncReport {
  int applied = 0;
  int conflicts = 0;
  int rejected = 0;
  int waiting = 0;
  int pulled = 0;
  String? error;

  /// The API's problem code when the sync failed, e.g. `device_revoked`.
  String? errorCode;

  bool get ok => error == null;

  @override
  String toString() => 'SyncReport(applied: $applied, conflicts: $conflicts, rejected: $rejected, waiting: $waiting, pulled: $pulled, error: $error)';
}

/// One sync cycle (docs/08 §3): upload queued photos, push the outbox in
/// the order the changes were made, then pull what changed on the server.
/// Safe to interrupt at any point: nothing leaves the outbox until the
/// server has answered for it, and a repeated push is answered `duplicate`.
class SyncEngine {
  SyncEngine(this.db, this.api, {required this.readFile, DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  final AppDatabase db;
  final ApiClient api;
  final Future<Uint8List> Function(String path) readFile;
  final DateTime Function() _clock;
  bool _running = false;

  static const batchSize = 200;

  Future<SyncReport> sync() async {
    final report = SyncReport();
    final farmId = await db.setting('farm_id');
    if (farmId == null || _running) return report..error = _running ? 'Already syncing' : 'No farm selected';
    _running = true;
    try {
      await _uploadMedia(farmId);
      final needsSnapshot = await _push(farmId, report);
      await _pull(farmId, report, snapshot: needsSnapshot);
      await db.setSetting('last_sync_at', _clock().toUtc().toIso8601String());
    } on ApiException catch (e) {
      report.error = e.isNetwork ? 'Offline: changes are kept on the phone.' : e.title;
      report.errorCode = e.code;
    } finally {
      _running = false;
    }
    return report;
  }

  Future<void> _uploadMedia(String farmId) async {
    final queued = await (db.select(db.mediaQueue)..where((m) => m.mediaId.isNull())).get();
    for (final m in queued) {
      try {
        final bytes = await readFile(m.path);
        final id = await api.upload(farmId, bytes, m.sha256, m.path.split('/').last);
        await (db.update(db.mediaQueue)..where((q) => q.localId.equals(m.localId))).write(MediaQueueCompanion(mediaId: Value(id)));
      } on ApiException catch (e) {
        if (e.isNetwork) rethrow;
        await (db.update(db.mediaQueue)..where((q) => q.localId.equals(m.localId)))
            .write(MediaQueueCompanion(attempts: Value(m.attempts + 1), lastError: Value(e.title)));
      }
    }
  }

  /// Returns true when a refused change means the local copy must be rebuilt from the server.
  Future<bool> _push(String farmId, SyncReport report) async {
    var rebuild = false;
    final media = {for (final m in await db.select(db.mediaQueue).get()) m.localId: m.mediaId};
    final pending = await db.pendingMutations();

    final ready = <OutboxEntry>[];
    final payload = <Map<String, dynamic>>[];
    for (final o in pending) {
      final data = jsonDecode(o.data) as Map<String, dynamic>;
      final local = data.remove('local_media') as String?;
      if (local != null) {
        final mediaId = media[local];
        if (mediaId == null) {
          report.waiting++;
          continue; // the photo is still on its way
        }
        data['media_id'] = mediaId;
      }
      ready.add(o);
      payload.add({
        'mutation_id': o.mutationId,
        'entity': o.entity,
        'op': o.op,
        'id': o.recordId,
        'occurred_at': o.occurredAt,
        if (o.baseVersion != null) 'base_version': o.baseVersion,
        'data': data,
      });
    }

    for (var i = 0; i < payload.length; i += batchSize) {
      final batch = payload.sublist(i, (i + batchSize).clamp(0, payload.length));
      final results = {for (final r in await api.push(farmId, batch)) r['mutation_id'] as String: r};
      for (final o in ready.sublist(i, (i + batchSize).clamp(0, ready.length))) {
        final r = results[o.mutationId];
        final status = r?['status'] as String? ?? 'error';
        final server = r?['server'] as Map<String, dynamic>?;
        final message = ((r?['error'] as Map?)?['message'] as String?) ?? '';
        final conflictId = r == null ? null : r['conflict_id'] as String?;
        switch (status) {
          case 'applied' || 'duplicate':
            await (db.delete(db.outbox)..where((x) => x.seq.equals(o.seq))).go();
            report.applied++;
          case 'conflict' when conflictId != null:
            // A field conflict: merged fields are in, the rest waits in the inbox.
            await (db.delete(db.outbox)..where((x) => x.seq.equals(o.seq))).go();
            report.conflicts++;
          case 'conflict':
            await _mark(o, 'conflict', message);
            report.conflicts++;
            if (server == null) rebuild = true;
          case 'rejected':
            await _mark(o, 'rejected', message);
            report.rejected++;
            rebuild = true;
          default: // deferred, error: retried next time
            await (db.update(db.outbox)..where((x) => x.seq.equals(o.seq)))
                .write(OutboxCompanion(attempts: Value(o.attempts + 1), lastError: Value(message)));
            report.waiting++;
            continue;
        }
        if (server != null && server['entity'] != null && server['data'] != null) {
          await db.upsertRecord(server['entity'] as String, server['id'] as String, server['version'] as int?, (server['data'] as Map).cast<String, dynamic>());
        }
      }
    }
    return rebuild;
  }

  Future<void> _mark(OutboxEntry o, String status, String message) =>
      (db.update(db.outbox)..where((x) => x.seq.equals(o.seq))).write(OutboxCompanion(status: Value(status), lastError: Value(message)));

  Future<void> _pull(String farmId, SyncReport report, {required bool snapshot}) async {
    var cursor = snapshot ? null : await db.setting('pull_cursor');
    final keep = await db.pendingTargets();
    var more = true;
    while (more) {
      final page = await api.pull(farmId, cursor: cursor);
      final changes = (page['changes'] as List).cast<Map<String, dynamic>>();
      await db.transaction(() async {
        if (cursor == null) {
          // A snapshot replaces the mirror, except records with unsent changes.
          final seen = {for (final c in changes) '${c['entity']}:${c['id']}'};
          for (final r in await db.select(db.mirrorRecords).get()) {
            final key = '${r.entity}:${r.id}';
            if (!seen.contains(key) && !keep.contains(key)) await db.removeRecord(r.entity, r.id);
          }
        }
        for (final c in changes) {
          final key = '${c['entity']}:${c['id']}';
          if (keep.contains(key)) continue;
          if (c['op'] == 'remove') {
            await db.removeRecord(c['entity'] as String, c['id'] as String);
          } else {
            await db.upsertRecord(c['entity'] as String, c['id'] as String, c['version'] as int?, (c['data'] as Map).cast<String, dynamic>());
          }
          report.pulled++;
        }
        cursor = page['next_cursor'] as String;
        await db.setSetting('pull_cursor', cursor);
      });
      more = page['has_more'] == true;
    }
  }
}
