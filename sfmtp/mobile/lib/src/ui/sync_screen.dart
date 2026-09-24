import 'package:flutter/material.dart';

import 'labels.dart';
import 'scope.dart';

const _entityLabels = {
  'worker_task_logs': 'Task step',
  'worker_task_photos': 'Photo',
  'worker_attendance': 'Attendance',
  'worker_leave': 'Leave request',
  'worker_gps_points': 'Location',
};

/// What is waiting to be sent, and anything the server refused.
class SyncScreen extends StatelessWidget {
  const SyncScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('Sync')),
      body: StreamBuilder(
        stream: app.db.watchOutbox(),
        builder: (context, snap) {
          final entries = snap.data ?? [];
          final report = app.lastReport;
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              FutureBuilder(
                future: app.db.setting('last_sync_at'),
                builder: (context, s) => Text(s.data == null ? 'Not synced yet' : 'Last sync ${clock(s.data)}'),
              ),
              if (report?.error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(report!.error!, style: TextStyle(color: Theme.of(context).colorScheme.error))),
              const SizedBox(height: 12),
              FilledButton.icon(key: const Key('sync-now'), onPressed: app.syncing ? null : app.syncNow, icon: const Icon(Icons.sync), label: Text(app.syncing ? 'Syncing…' : 'Sync now')),
              const SizedBox(height: 16),
              if (entries.isEmpty) const Text('Everything is sent.'),
              for (final o in entries)
                Card(
                  child: ListTile(
                    title: Text('${_entityLabels[o.entity] ?? o.entity}${o.op == 'insert' ? '' : ' (${o.op.replaceAll('_', ' ')})'}'),
                    subtitle: Text([
                      'Made at ${clock(o.occurredAt)}',
                      if (o.status == 'pending') 'waiting to send${o.attempts > 0 ? ' (${o.attempts} tries)' : ''}',
                      if (o.status == 'conflict') 'not applied: ${o.lastError}',
                      if (o.status == 'rejected') 'refused: ${o.lastError}',
                    ].join(' · ')),
                    leading: Icon(o.status == 'pending' ? Icons.schedule : Icons.error_outline, color: o.status == 'pending' ? null : Theme.of(context).colorScheme.error),
                    trailing: o.status == 'pending'
                        ? null
                        : IconButton(
                            tooltip: 'Dismiss',
                            icon: const Icon(Icons.close),
                            onPressed: () => (app.db.delete(app.db.outbox)..where((x) => x.seq.equals(o.seq))).go(),
                          ),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }
}
