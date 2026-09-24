import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A small in-memory stand-in for the sync API: enough of the server's rules
/// (state machine, duplicates, conflicts, deferred photos, the change feed)
/// to exercise the app's offline behaviour.
class FakeServer {
  final tasks = <String, Map<String, dynamic>>{};
  final attendance = <String, Map<String, dynamic>>{};
  final applied = <String, Map<String, dynamic>>{}; // mutation_id → result
  final media = <String, String>{}; // sha → id
  final logs = <Map<String, dynamic>>[];
  final feed = <Map<String, String>>[];
  var online = true;
  var pushes = 0;

  /// Phase 11: other mirrored entities (crop_cycles, animals, team_tasks, notifications, conflicts).
  final records = <String, Map<String, Map<String, dynamic>>>{};

  /// Records created by inserts the app pushed, by entity.
  final inserted = <String, List<Map<String, dynamic>>>{};

  /// What /me/workspaces reports; a field worker by default.
  Map<String, String> permissions = {'tasks.view': 'assigned', 'tasks.execute': 'assigned', 'attendance.record': 'own', 'leave.request': 'own'};

  /// Accounts with a second factor answer the login with an MFA token.
  var mfa = false;

  /// The device was signed out from the web: every call is refused.
  var revoked = false;

  /// Push tokens the phone registered, in order.
  final pushTokens = <String?>[];

  /// A simulated link: time is added, not waited for.
  Duration simulated = Duration.zero;
  int latencyMs = 0;
  int upKbps = 0;
  int downKbps = 0;
  int serverMsPerMutation = 0;

  /// Chrome's "Regular 3G" profile, plus the server's measured time per mutation.
  void use3g({int serverMs = 15}) {
    latencyMs = 100;
    upKbps = 250;
    downKbps = 750;
    serverMsPerMutation = serverMs;
  }

  void _account(int upBytes, int downBytes, int mutations) {
    if (latencyMs == 0) return;
    final up = upBytes * 8 / (upKbps * 1000);
    final down = downBytes * 8 / (downKbps * 1000);
    simulated += Duration(milliseconds: latencyMs * 2 + ((up + down) * 1000).round() + mutations * serverMsPerMutation);
  }

  void put(String entity, Map<String, dynamic> data) {
    (records[entity] ??= {})[data['id'] as String] = {'version': 1, ...data};
    _touch(entity, data['id'] as String);
  }

  /// Someone else edits a record on the server (the web, another phone).
  void change(String entity, String id, Map<String, dynamic> fields) {
    final r = records[entity]![id]!;
    records[entity]![id] = {...r, ...fields, 'version': (r['version'] as int) + 1};
    _touch(entity, id);
  }

  void notify(String kind, String title) => put('notifications', {'id': 'n-${feed.length}', 'kind': kind, 'title': title, 'body': null, 'read_at': null, 'created_at': '2026-09-24T10:00:${feed.length.toString().padLeft(2, '0')}Z'});

  static const _next = {'start': 'in_progress', 'resume': 'in_progress', 'pause': 'paused', 'submit': 'submitted'};
  static const _from = {
    'start': ['assigned', 'rejected'],
    'pause': ['in_progress'],
    'resume': ['paused'],
    'submit': ['in_progress', 'paused'],
  };

  void addTask(String id, String title, {String status = 'assigned'}) {
    tasks[id] = {'id': id, 'code': 'TSK-${tasks.length + 1}', 'status': status, 'version': 1, 'due_on': '2026-09-24', 'activity': {'title': title}};
    _touch('tasks', id);
  }

  void cancel(String id) {
    tasks[id] = {...tasks[id]!, 'status': 'cancelled', 'version': (tasks[id]!['version'] as int) + 1};
    _touch('tasks', id);
  }

  void _touch(String entity, String id) => feed.add({'entity': entity, 'id': id});

  http.Client client() => MockClient((req) async {
        if (!online) throw http.ClientException('offline');
        final path = req.url.path.replaceFirst('/api/v1', '');
        if (revoked) {
          if (path == '/auth/refresh') return _json({'code': 'device_revoked', 'title': 'This device has been signed out.'}, 401);
          return _json({'code': 'unauthenticated', 'title': 'Token expired.'}, 401);
        }
        if (path == '/auth/login' && mfa) return _json({'data': {'mfa_required': true, 'mfa_token': 'mfa-1'}});
        if (path == '/auth/mfa/challenge') {
          final body = jsonDecode(req.body) as Map;
          return body['code'] == '123456' ? _json({'data': {'mfa_required': false, 'access_token': 'a', 'refresh_token': 'r'}}) : _json({'code': 'mfa_invalid', 'title': 'That code is not valid.'}, 422);
        }
        // Like the API, refuse mobile POSTs without an Idempotency-Key.
        if (req.method == 'POST' && path != '/auth/login' && req.headers['Idempotency-Key'] == null) {
          return _json({'code': 'idempotency_key_required', 'title': 'Mobile requests must include an Idempotency-Key header.'}, 422);
        }
        if (path == '/auth/login') return _json({'data': {'mfa_required': false, 'access_token': 'a', 'refresh_token': 'r'}});
        if (path == '/me/devices/current/push-token' && req.method == 'PUT') {
          pushTokens.add((jsonDecode(req.body) as Map)['token'] as String?);
          return _json({'data': {'push_platform': 'fcm'}});
        }
        if (path == '/me/workspaces') return _json({'data': [{'type': 'farm', 'id': 'farm-1', 'name': 'AGG Mixed Farm', 'dashboards': ['worker'], 'permissions': permissions}]});
        if (path.endsWith('/sync/pull')) {
          final res = _json({'data': _pull(req.url.queryParameters['cursor'])});
          _account(200, res.bodyBytes.length, 0);
          return res;
        }
        if (path.endsWith('/sync/push')) {
          final mutations = (jsonDecode(req.body) as Map)['mutations'] as List;
          final res = _json({'data': {'results': _push(mutations)}});
          _account(req.bodyBytes.length, res.bodyBytes.length, mutations.length);
          return res;
        }
        if (path.endsWith('/media/uploads')) {
          final sha = RegExp(r'name="sha256"\r\n\r\n([0-9a-f]{64})').firstMatch(utf8.decode(req.bodyBytes, allowMalformed: true))!.group(1)!;
          final existed = media.containsKey(sha);
          media[sha] ??= 'media-${media.length + 1}';
          return _json({'data': {'id': media[sha]}}, existed ? 200 : 201);
        }
        return _json({'code': 'not_found', 'title': 'Not found'}, 404);
      });

  Map<String, dynamic> _pull(String? cursor) {
    Map<String, dynamic> upsert(String e, Map<String, dynamic> d) => {'entity': e, 'op': 'upsert', 'id': d['id'], 'version': d['version'], 'data': d};
    if (cursor == null) {
      return {
        'changes': [
          for (final t in tasks.values) upsert('tasks', t),
          for (final a in attendance.values) upsert('attendance', a),
          for (final e in records.entries)
            for (final r in e.value.values)
              if (!(e.key == 'conflicts' && r['status'] != 'open')) upsert(e.key, r),
        ],
        'next_cursor': '${feed.length}',
        'has_more': false,
      };
    }
    final seen = <String>{};
    final changes = <Map<String, dynamic>>[];
    for (final f in feed.skip(int.parse(cursor))) {
      if (!seen.add('${f['entity']}:${f['id']}')) continue;
      final store = f['entity'] == 'tasks' ? tasks : (f['entity'] == 'attendance' ? attendance : records[f['entity']]!);
      final r = store[f['id']]!;
      final gone = (f['entity'] == 'conflicts' && r['status'] != 'open') || (f['entity'] == 'team_tasks' && r['status'] != 'submitted');
      changes.add(gone ? {'entity': f['entity'], 'op': 'remove', 'id': f['id'], 'version': null, 'data': null} : upsert(f['entity']!, r));
    }
    return {'changes': changes, 'next_cursor': '${feed.length}', 'has_more': false};
  }

  List<Map<String, dynamic>> _push(List mutations) {
    pushes++;
    return [
      for (final m in mutations.cast<Map<String, dynamic>>()) _apply(m),
    ];
  }

  Map<String, dynamic> _apply(Map<String, dynamic> m) {
    final id = m['mutation_id'] as String;
    if (applied.containsKey(id)) return {...applied[id]!, 'status': 'duplicate', 'mutation_id': id};
    final data = (m['data'] as Map).cast<String, dynamic>();
    Map<String, dynamic> result;
    switch ('${m['entity']}.${m['op']}') {
      case 'worker_task_logs.insert':
        final task = tasks[data['task_id']]!;
        final event = data['event'] as String;
        logs.add({...data, 'occurred_at': m['occurred_at'], 'applied': _from[event]!.contains(task['status'])});
        if (!_from[event]!.contains(task['status'])) {
          result = {'status': 'conflict', 'error': {'code': 'invalid_state_transition', 'message': 'The task is ${task['status']}.'}, 'server': {'entity': 'tasks', 'id': task['id'], 'version': task['version'], 'data': task}};
        } else {
          tasks[task['id']] = {...task, 'status': _next[event], 'version': (task['version'] as int) + 1, if (data['quantity'] != null) 'quantity': data['quantity']};
          _touch('tasks', task['id'] as String);
          result = {'status': 'applied', 'server': {'entity': 'tasks', 'id': task['id'], 'version': tasks[task['id']]!['version'], 'data': tasks[task['id']]}};
        }
      case 'worker_task_photos.insert':
        if (!media.containsValue(data['media_id'])) {
          return {'mutation_id': id, 'status': 'deferred', 'error': {'code': 'media_missing', 'message': 'Not uploaded yet'}};
        }
        result = {'status': 'applied'};
      case 'worker_attendance.check_in':
        attendance[m['id']] = {'id': m['id'], 'check_in_at': m['occurred_at'], 'check_out_at': null, 'version': 1, 'source': 'mobile'};
        _touch('attendance', m['id'] as String);
        result = {'status': 'applied', 'server': {'entity': 'attendance', 'id': m['id'], 'version': 1, 'data': attendance[m['id']]}};
      case 'worker_attendance.check_out':
        final a = attendance[data['attendance_id']]!;
        attendance[a['id']] = {...a, 'check_out_at': m['occurred_at'], 'version': 2};
        _touch('attendance', a['id'] as String);
        result = {'status': 'applied'};
      case 'crop_observations.insert' || 'crop_operations.insert' || 'animal_health.insert' || 'animal_weights.insert' || 'animal_production.insert' || 'worker_gps_points.insert':
        (inserted[m['entity'] as String] ??= []).add({...data, 'occurred_at': m['occurred_at']});
        result = {'status': 'applied'};
      case 'animals.update':
        result = _merge(m, data);
      case 'task_reviews.verify' || 'task_reviews.reject':
        final t = records['team_tasks']![data['task_id']]!;
        if (t['status'] != 'submitted') {
          result = {'status': 'conflict', 'error': {'code': 'invalid_state_transition', 'message': 'Already ${t['status']}.'}, 'server': {'entity': 'team_tasks', 'id': t['id'], 'version': t['version'], 'data': t}};
        } else {
          change('team_tasks', t['id'] as String, {'status': m['op'] == 'verify' ? 'verified' : 'rejected', 'review_note': data['note']});
          result = {'status': 'applied'};
        }
      case 'sync_conflicts.resolve':
        final c = records['conflicts']![data['conflict_id']]!;
        final choices = (data['choices'] as Map).cast<String, String>();
        final mine = {for (final f in (c['fields'] as List).cast<Map>()) if (choices[f['field']] == 'mine') f['field'] as String: f['mine']};
        if (mine.isNotEmpty) change(c['entity'] as String, c['record_id'] as String, mine);
        change('conflicts', c['id'] as String, {'status': 'resolved', 'resolution': choices});
        result = {'status': 'applied'};
      default:
        result = {'status': 'rejected', 'error': {'code': 'unsupported', 'message': 'Unsupported'}};
    }
    applied[id] = result;
    return {...result, 'mutation_id': id};
  }

  /// Field-level merge like the server's FieldMerge (docs/08 §4).
  Map<String, dynamic> _merge(Map<String, dynamic> m, Map<String, dynamic> data) {
    final id = m['id'] as String;
    final current = records['animals']![id]!;
    final changes = (data['changes'] as Map).cast<String, dynamic>();
    final base = (data['base'] as Map? ?? {}).cast<String, dynamic>();
    final apply = <String, dynamic>{};
    final conflicts = <Map<String, dynamic>>[];
    for (final e in changes.entries) {
      if (current[e.key] == e.value) continue;
      if (m['base_version'] == current['version'] || current[e.key] == base[e.key]) {
        apply[e.key] = e.value;
      } else {
        conflicts.add({'field': e.key, 'base': base[e.key], 'mine': e.value, 'server': current[e.key]});
      }
    }
    if (apply.isNotEmpty) change('animals', id, apply);
    final server = {'entity': 'animals', 'id': id, 'version': records['animals']![id]!['version'], 'data': records['animals']![id]};
    if (conflicts.isEmpty) return {'status': 'applied', 'merged': apply.keys.toList(), 'server': server};
    final conflictId = 'c-${feed.length}';
    put('conflicts', {'id': conflictId, 'entity': 'animals', 'record_id': id, 'label': current['animal_code'], 'fields': conflicts, 'status': 'open'});
    notify('sync_conflict', 'Choose which details to keep for ${current['animal_code']}');
    return {'status': 'conflict', 'merged': apply.keys.toList(), 'conflict_id': conflictId, 'server': server, 'error': {'code': 'field_conflict', 'message': 'Choose which to keep.'}};
  }

  http.Response _json(Map<String, dynamic> body, [int status = 200]) =>
      http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}
