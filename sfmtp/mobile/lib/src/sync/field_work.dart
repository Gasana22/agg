import 'dart:convert';

import 'package:crypto/crypto.dart';
import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../data/database.dart';
import '../device/location.dart';

/// The steps a worker can take on a task, by status (the server's state machine).
List<String> workerActions(String status) => switch (status) {
      'assigned' || 'rejected' => ['start'],
      'in_progress' => ['pause', 'submit'],
      'paused' => ['resume', 'submit'],
      _ => [],
    };

const _next = {'start': 'in_progress', 'resume': 'in_progress', 'pause': 'paused', 'submit': 'submitted'};

/// Everything the worker does in the app. Each action changes the local
/// copy at once (the screen never waits for the network) and adds a
/// mutation to the outbox; the sync engine sends it later (docs/08 §1).
class FieldWork {
  FieldWork(this.db, this.location, {DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  final AppDatabase db;
  final LocationSource location;
  final DateTime Function() _clock;
  static const _uuid = Uuid();

  String _now() => _clock().toUtc().toIso8601String();

  Future<void> taskStep(String taskId, String event, {double? quantity, String? unit, String? note}) async {
    final task = await db.record('tasks', taskId) ?? (throw StateError('Unknown task'));
    final status = task['status'] as String;
    if (event != 'note' && !workerActions(status).contains(event)) {
      throw StateError('You cannot $event a task that is ${status.replaceAll('_', ' ')}.');
    }
    final place = await location.current();
    final at = _now();

    await db.transaction(() async {
      final updated = Map<String, dynamic>.from(task);
      if (_next[event] != null) updated['status'] = _next[event];
      if (event == 'start') updated['started_at'] ??= at;
      if (event == 'submit') {
        updated['submitted_at'] = at;
        if (quantity != null) updated['quantity'] = quantity;
        if (unit != null) updated['unit'] = unit;
        updated['submit_note'] = note;
      }
      await db.upsertRecord('tasks', taskId, task['version'] as int?, updated, pending: true);
      await _enqueue('worker_task_logs', 'insert', at, target: 'tasks:$taskId', data: {
        'task_id': taskId,
        'event': event,
        ...?place?.toJson(),
        'quantity': ?quantity,
        'unit': ?unit,
        if (note != null && note.isNotEmpty) 'note': note,
      });
    });
  }

  /// Keep the photo on the phone and queue it: it uploads first, then its record.
  Future<void> addPhoto(String taskId, String path, Uint8List bytes, {String? caption}) async {
    final place = await location.current();
    final at = _now();
    final localId = _uuid.v7();
    await db.transaction(() async {
      await db.into(db.mediaQueue).insert(MediaQueueCompanion.insert(localId: localId, path: path, sha256: sha256.convert(bytes).toString()));
      final task = await db.record('tasks', taskId);
      if (task != null) {
        final photos = [...(task['local_photos'] as List? ?? []), path];
        await db.upsertRecord('tasks', taskId, task['version'] as int?, {...task, 'local_photos': photos}, pending: true);
      }
      await _enqueue('worker_task_photos', 'insert', at, target: 'tasks:$taskId', data: {
        'task_id': taskId,
        'local_media': localId,
        'taken_at': at,
        ...?place?.toJson(),
        'caption': ?caption,
      });
    });
  }

  Future<void> checkIn() async {
    if (await openAttendance() != null) throw StateError('You are already checked in.');
    final place = await location.current();
    final at = _now();
    final id = _uuid.v7();
    final local = _clock();
    await db.transaction(() async {
      await db.upsertRecord('attendance', id, null, {
        'id': id,
        'work_date': '${local.year.toString().padLeft(4, '0')}-${local.month.toString().padLeft(2, '0')}-${local.day.toString().padLeft(2, '0')}',
        'check_in_at': at,
        'check_out_at': null,
        'source': 'mobile',
      }, pending: true);
      await _enqueue('worker_attendance', 'check_in', at, id: id, target: 'attendance:$id', data: {...?place?.toJson()});
    });
  }

  Future<void> checkOut() async {
    final open = await openAttendance() ?? (throw StateError('You are not checked in.'));
    final place = await location.current();
    final at = _now();
    await db.transaction(() async {
      await db.upsertRecord('attendance', open['id'] as String, null, {...open, 'check_out_at': at}, pending: true);
      await _enqueue('worker_attendance', 'check_out', at, target: 'attendance:${open['id']}', data: {'attendance_id': open['id'], ...?place?.toJson()});
    });
  }

  Future<void> requestLeave(String kind, String fromOn, String toOn, {String? reason}) async {
    final id = _uuid.v7();
    await db.transaction(() async {
      await db.upsertRecord('leave', id, null, {'id': id, 'kind': kind, 'from_on': fromOn, 'to_on': toOn, 'reason': reason, 'status': 'requested'}, pending: true);
      await _enqueue('worker_leave', 'insert', _now(), id: id, target: 'leave:$id', data: {'kind': kind, 'from_on': fromOn, 'to_on': toOn, 'reason': ?reason});
    });
  }

  /// Today's attendance still open (checked in, not out).
  Future<Map<String, dynamic>?> openAttendance() async {
    final all = await db.records('attendance');
    all.sort((a, b) => (b['check_in_at'] as String).compareTo(a['check_in_at'] as String));
    for (final a in all) {
      if (a['check_out_at'] == null) return a;
    }
    return null;
  }

  Future<void> _enqueue(String entity, String op, String occurredAt, {required Map<String, dynamic> data, String? id, String? target}) =>
      db.into(db.outbox).insert(OutboxCompanion.insert(
            mutationId: _uuid.v7(),
            entity: entity,
            op: op,
            recordId: Value(id ?? (op == 'insert' ? _uuid.v7() : null)),
            target: Value(target),
            occurredAt: occurredAt,
            data: jsonEncode(data),
          ));
}
