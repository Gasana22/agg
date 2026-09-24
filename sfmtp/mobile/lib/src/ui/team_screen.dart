import 'package:flutter/material.dart';

import 'forms.dart';
import 'labels.dart';
import 'scope.dart';

/// Work submitted by the team, waiting for the supervisor's check.
class TeamView extends StatelessWidget {
  const TeamView({super.key});

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return StreamBuilder(
      stream: app.db.watchRecords('team_tasks'),
      builder: (context, snap) {
        final tasks = (snap.data ?? []).where((t) => t['status'] == 'submitted').toList()
          ..sort((a, b) => (a['submitted_at'] as String? ?? '').compareTo(b['submitted_at'] as String? ?? ''));
        return RefreshIndicator(
          onRefresh: app.syncNow,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text('Waiting for your check', style: Theme.of(context).textTheme.titleLarge),
              const SizedBox(height: 8),
              if (tasks.isEmpty) const Padding(padding: EdgeInsets.symmetric(vertical: 24), child: Text('Nothing to check.', textAlign: TextAlign.center)),
              for (final t in tasks)
                Card(
                  child: ListTile(
                    key: Key('review-${t['id']}'),
                    title: Text(((t['activity'] as Map?)?['title'] as String?) ?? t['code'] as String),
                    subtitle: Text([t['code'], (t['worker'] as Map?)?['full_name'], if (t['submitted_at'] != null) 'done ${clock(t['submitted_at'] as String)}'].whereType<String>().join(' · ')),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => ReviewScreen(taskId: t['id'] as String))),
                  ),
                ),
            ],
          ),
        );
      },
    );
  }
}

class ReviewScreen extends StatelessWidget {
  const ReviewScreen({super.key, required this.taskId});
  final String taskId;

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return FutureBuilder(
      future: app.db.record('team_tasks', taskId),
      builder: (context, snap) {
        final t = snap.data;
        final activity = (t?['activity'] as Map?) ?? {};
        return Scaffold(
          appBar: AppBar(title: Text(t?['code'] as String? ?? 'Task')),
          body: t == null
              ? const Center(child: CircularProgressIndicator())
              : ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    Text(activity['title'] as String? ?? '', style: Theme.of(context).textTheme.titleLarge),
                    if (activity['instructions'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(activity['instructions'] as String)),
                    const SizedBox(height: 12),
                    ListTile(dense: true, title: const Text('Worker'), trailing: Text((t['worker'] as Map?)?['full_name'] as String? ?? '—')),
                    ListTile(dense: true, title: const Text('Done at'), trailing: Text(clock(t['submitted_at'] as String?))),
                    if (t['quantity'] != null) ListTile(dense: true, title: const Text('Quantity'), trailing: Text('${t['quantity']} ${t['unit'] ?? ''}')),
                    if (t['submit_note'] != null) ListTile(dense: true, title: const Text('Note'), subtitle: Text(t['submit_note'] as String)),
                    const SizedBox(height: 16),
                    FilledButton.icon(
                      key: const Key('approve'),
                      onPressed: () async {
                        await app.act((w) => w.reviewTask(taskId, approve: true));
                        if (context.mounted) Navigator.of(context).pop();
                      },
                      icon: const Icon(Icons.check),
                      label: const Text('Approve'),
                    ),
                    const SizedBox(height: 8),
                    OutlinedButton.icon(
                      key: const Key('send-back'),
                      onPressed: () async {
                        final reason = TextEditingController();
                        final saved = await showFormSheet(context,
                            title: 'Send back',
                            saveLabel: 'Send back',
                            fields: (_) => [field('reason', reason, 'What needs redoing?', lines: 2)],
                            onSave: () => app.act((w) => w.reviewTask(taskId, approve: false, note: text(reason))));
                        if (saved && context.mounted) Navigator.of(context).pop();
                      },
                      icon: const Icon(Icons.undo),
                      label: const Text('Send back'),
                    ),
                  ],
                ),
        );
      },
    );
  }
}
