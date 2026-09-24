import 'package:flutter/material.dart';

import 'labels.dart';
import 'scope.dart';
import 'sync_screen.dart';
import 'task_screen.dart';

/// The home screen: attendance and today's tasks, from the phone's database.
class TodayScreen extends StatelessWidget {
  const TodayScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(app.farmName ?? 'My day'),
        actions: [
          StreamBuilder(
            stream: app.db.watchOutbox(),
            builder: (context, snap) {
              final waiting = (snap.data ?? []).where((o) => o.status == 'pending').length;
              final problems = (snap.data ?? []).where((o) => o.status != 'pending').length;
              return IconButton(
                key: const Key('sync-status'),
                tooltip: waiting > 0 ? '$waiting changes not sent yet' : 'Sync',
                onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const SyncScreen())),
                icon: Badge(
                  isLabelVisible: waiting + problems > 0,
                  backgroundColor: problems > 0 ? Theme.of(context).colorScheme.error : null,
                  label: Text('${waiting + problems}'),
                  child: Icon(app.syncing ? Icons.sync : (waiting > 0 ? Icons.cloud_upload_outlined : Icons.cloud_done_outlined)),
                ),
              );
            },
          ),
          PopupMenuButton<String>(
            onSelected: (v) => v == 'signout' ? app.signOut() : null,
            itemBuilder: (_) => const [PopupMenuItem(value: 'signout', child: Text('Sign out'))],
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: app.syncNow,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: const [_AttendanceCard(), SizedBox(height: 16), _TaskList()],
        ),
      ),
    );
  }
}

class _AttendanceCard extends StatelessWidget {
  const _AttendanceCard();

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return StreamBuilder(
      stream: app.db.watchRecords('attendance'),
      builder: (context, snap) {
        final today = todayIso();
        final days = (snap.data ?? []).where((a) => a['work_date'] == today || a['check_out_at'] == null).toList()
          ..sort((a, b) => (b['check_in_at'] as String).compareTo(a['check_in_at'] as String));
        final day = days.isEmpty ? null : days.first;
        final onSite = day != null && day['check_out_at'] == null;
        return Card(
          child: ListTile(
            leading: Icon(onSite ? Icons.badge : Icons.schedule),
            title: Text(day == null ? 'Not checked in' : (onSite ? 'On site since ${clock(day['check_in_at'] as String?)}' : 'Checked out at ${clock(day['check_out_at'] as String?)}')),
            subtitle: const Text('Your location is recorded only while you work.'),
            trailing: day == null || onSite
                ? FilledButton.tonal(
                    key: Key(onSite ? 'check-out' : 'check-in'),
                    onPressed: () => _run(context, () => app.act((w) => onSite ? w.checkOut() : w.checkIn())),
                    child: Text(onSite ? 'Check out' : 'Check in'),
                  )
                : null,
          ),
        );
      },
    );
  }
}

class _TaskList extends StatelessWidget {
  const _TaskList();

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return StreamBuilder(
      stream: app.db.watchRecords('tasks'),
      builder: (context, snap) {
        final today = todayIso();
        const order = {'in_progress': 0, 'paused': 1, 'rejected': 2, 'assigned': 3, 'submitted': 4};
        final tasks = (snap.data ?? [])
            .where((t) => order.containsKey(t['status']) && ((t['due_on'] as String?) == null || (t['due_on'] as String).compareTo(today) <= 0 || t['status'] != 'assigned'))
            .toList()
          ..sort((a, b) => order[a['status']]!.compareTo(order[b['status']]!));
        final later = (snap.data ?? []).where((t) => t['status'] == 'assigned' && (t['due_on'] as String?) != null && (t['due_on'] as String).compareTo(today) > 0).length;

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text("Today's tasks", style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 8),
            if (tasks.isEmpty) const Padding(padding: EdgeInsets.symmetric(vertical: 24), child: Text('Nothing to do right now.', textAlign: TextAlign.center)),
            for (final t in tasks) _TaskTile(task: t),
            if (later > 0) Padding(padding: const EdgeInsets.only(top: 8), child: Text('$later more planned for the coming days.', style: Theme.of(context).textTheme.bodySmall)),
          ],
        );
      },
    );
  }
}

class _TaskTile extends StatelessWidget {
  const _TaskTile({required this.task});
  final Map<String, dynamic> task;

  @override
  Widget build(BuildContext context) {
    final activity = (task['activity'] as Map?) ?? {};
    final subject = (activity['subject'] as Map?)?['label'] as String?;
    final status = task['status'] as String;
    return Card(
      child: ListTile(
        key: Key('task-${task['id']}'),
        title: Text(activity['title'] as String? ?? task['code'] as String? ?? 'Task'),
        subtitle: Text([task['code'], ?subject].join(' · ')),
        trailing: Chip(
          label: Text(statusLabels[status] ?? status),
          labelStyle: TextStyle(color: statusColor(status, Theme.of(context).colorScheme)),
          visualDensity: VisualDensity.compact,
        ),
        onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => TaskScreen(taskId: task['id'] as String))),
      ),
    );
  }
}

Future<void> _run(BuildContext context, Future<void> Function() action) async {
  try {
    await action();
  } on StateError catch (e) {
    if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
  }
}
