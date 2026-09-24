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
        // Like the API, refuse mobile POSTs without an Idempotency-Key.
        if (req.method == 'POST' && path != '/auth/login' && req.headers['Idempotency-Key'] == null) {
          return _json({'code': 'idempotency_key_required', 'title': 'Mobile requests must include an Idempotency-Key header.'}, 422);
        }
        if (path == '/auth/login') return _json({'data': {'mfa_required': false, 'access_token': 'a', 'refresh_token': 'r'}});
        if (path == '/me/workspaces') return _json({'data': [{'type': 'farm', 'id': 'farm-1', 'name': 'AGG Mixed Farm', 'dashboards': ['worker']}]});
        if (path.endsWith('/sync/pull')) return _json({'data': _pull(req.url.queryParameters['cursor'])});
        if (path.endsWith('/sync/push')) return _json({'data': {'results': _push((jsonDecode(req.body) as Map)['mutations'] as List)}});
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
        'changes': [for (final t in tasks.values) upsert('tasks', t), for (final a in attendance.values) upsert('attendance', a)],
        'next_cursor': '${feed.length}',
        'has_more': false,
      };
    }
    final seen = <String>{};
    final changes = <Map<String, dynamic>>[];
    for (final f in feed.skip(int.parse(cursor))) {
      if (!seen.add('${f['entity']}:${f['id']}')) continue;
      changes.add(upsert(f['entity']!, (f['entity'] == 'tasks' ? tasks : attendance)[f['id']]!));
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
      default:
        result = {'status': 'rejected', 'error': {'code': 'unsupported', 'message': 'Unsupported'}};
    }
    applied[id] = result;
    return {...result, 'mutation_id': id};
  }

  http.Response _json(Map<String, dynamic> body, [int status = 200]) =>
      http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}
