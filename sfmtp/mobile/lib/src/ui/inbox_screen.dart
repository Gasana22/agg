import 'package:flutter/material.dart';

import 'scope.dart';

/// Notifications and sync conflicts for this member.
class InboxView extends StatelessWidget {
  const InboxView({super.key});

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return StreamBuilder(
      stream: app.db.watchRecords('conflicts'),
      builder: (context, c) => StreamBuilder(
        stream: app.db.watchRecords('notifications'),
        builder: (context, n) {
          final conflicts = (c.data ?? []).where((x) => x['status'] == 'open').toList();
          final notes = [...(n.data ?? [])]..sort((a, b) => (b['created_at'] as String? ?? '').compareTo(a['created_at'] as String? ?? ''));
          return RefreshIndicator(
            onRefresh: app.syncNow,
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (conflicts.isNotEmpty) ...[
                  Text('Choose what to keep', style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 8),
                  for (final x in conflicts)
                    Card(
                      color: Theme.of(context).colorScheme.tertiaryContainer,
                      child: ListTile(
                        key: Key('conflict-${x['id']}'),
                        leading: const Icon(Icons.call_split),
                        title: Text(x['label'] as String? ?? 'A record'),
                        subtitle: Text('Changed by someone else: ${(x['fields'] as List).map((f) => (f['field'] as String).replaceAll('_', ' ')).join(', ')}'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => ConflictScreen(conflict: x))),
                      ),
                    ),
                  const SizedBox(height: 16),
                ],
                Text('Notifications', style: Theme.of(context).textTheme.titleLarge),
                const SizedBox(height: 8),
                if (notes.isEmpty) const Padding(padding: EdgeInsets.symmetric(vertical: 24), child: Text('Nothing new.', textAlign: TextAlign.center)),
                for (final x in notes)
                  ListTile(
                    key: Key('notification-${x['id']}'),
                    leading: Icon(x['read_at'] == null ? Icons.circle : Icons.circle_outlined, size: 12, color: Theme.of(context).colorScheme.primary),
                    title: Text(x['title'] as String? ?? ''),
                    subtitle: x['body'] == null ? null : Text(x['body'] as String),
                  ),
              ],
            ),
          );
        },
      ),
    );
  }
}

/// Keep mine or the server's value, field by field (docs/08 §4).
class ConflictScreen extends StatefulWidget {
  const ConflictScreen({super.key, required this.conflict});
  final Map<String, dynamic> conflict;

  @override
  State<ConflictScreen> createState() => _ConflictScreenState();
}

class _ConflictScreenState extends State<ConflictScreen> {
  late final Map<String, String> _choices = {for (final f in widget.conflict['fields'] as List) f['field'] as String: 'server'};

  String _show(Object? v) => v == null || v == '' ? '(empty)' : '$v';

  @override
  Widget build(BuildContext context) {
    final fields = (widget.conflict['fields'] as List).cast<Map>();
    return Scaffold(
      appBar: AppBar(title: Text(widget.conflict['label'] as String? ?? 'Conflict')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const Text('While you were offline, someone else changed the same details. Choose which value to keep for each.'),
          const SizedBox(height: 12),
          for (final f in fields)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text((f['field'] as String).replaceAll('_', ' '), style: Theme.of(context).textTheme.titleMedium),
                    RadioGroup<String>(
                      groupValue: _choices[f['field']],
                      onChanged: (v) => setState(() => _choices[f['field'] as String] = v!),
                      child: Column(children: [
                        RadioListTile<String>(key: Key('keep-mine-${f['field']}'), value: 'mine', title: Text('Mine: ${_show(f['mine'])}')),
                        RadioListTile<String>(key: Key('keep-server-${f['field']}'), value: 'server', title: Text('Theirs: ${_show(f['server'])}')),
                      ]),
                    ),
                  ],
                ),
              ),
            ),
          const SizedBox(height: 12),
          FilledButton(
            key: const Key('resolve'),
            onPressed: () async {
              await AppScope.of(context).act((w) => w.resolveConflict(widget.conflict['id'] as String, _choices));
              if (context.mounted) Navigator.of(context).pop();
            },
            child: const Text('Keep these'),
          ),
        ],
      ),
    );
  }
}
