import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../sync/field_work.dart';
import 'labels.dart';
import 'scope.dart';

class TaskScreen extends StatelessWidget {
  const TaskScreen({super.key, required this.taskId});
  final String taskId;

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return StreamBuilder(
      stream: app.db.watchRecords('tasks').map((all) => all.where((t) => t['id'] == taskId).firstOrNull),
      builder: (context, snap) {
        final t = snap.data;
        if (t == null) return Scaffold(appBar: AppBar(), body: const Center(child: Text('This task is no longer assigned to you.')));
        final activity = (t['activity'] as Map?) ?? {};
        final status = t['status'] as String;
        final photos = (t['local_photos'] as List?)?.cast<String>() ?? const [];
        final target = activity['target_quantity'];
        return Scaffold(
          appBar: AppBar(title: Text(t['code'] as String? ?? 'Task')),
          body: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text(activity['title'] as String? ?? '', style: Theme.of(context).textTheme.headlineSmall),
              const SizedBox(height: 4),
              Text([statusLabels[status] ?? status, ?((activity['subject'] as Map?)?['label'] as String?), if (t['due_on'] != null) 'due ${t['due_on']}'].join(' · '),
                  style: TextStyle(color: statusColor(status, Theme.of(context).colorScheme))),
              if (activity['instructions'] != null) ...[
                const SizedBox(height: 16),
                Card(child: Padding(padding: const EdgeInsets.all(12), child: Text(activity['instructions'] as String))),
              ],
              if (status == 'rejected' && t['review_note'] != null) ...[
                const SizedBox(height: 12),
                Text('Sent back: ${t['review_note']}', style: TextStyle(color: Theme.of(context).colorScheme.error)),
              ],
              if (target != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text('Target: $target ${activity['target_unit'] ?? ''}')),
              if (t['quantity'] != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text('Done: ${t['quantity']} ${t['unit'] ?? ''}')),
              const SizedBox(height: 24),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final a in workerActions(status))
                    a == 'pause'
                        ? OutlinedButton(key: Key('action-$a'), onPressed: () => _step(context, a), child: Text(actionLabels[a]!))
                        : FilledButton(key: Key('action-$a'), onPressed: () => a == 'submit' ? _submit(context, t) : _step(context, a), child: Text(actionLabels[a]!)),
                  if (!['verified', 'cancelled'].contains(status))
                    OutlinedButton.icon(key: const Key('photo'), onPressed: () => _photo(context), icon: const Icon(Icons.photo_camera), label: const Text('Photo')),
                ],
              ),
              if (photos.isNotEmpty) ...[
                const SizedBox(height: 16),
                Wrap(spacing: 8, children: [
                  for (final p in photos)
                    ClipRRect(
                      borderRadius: BorderRadius.circular(8),
                      child: Image.file(File(p), width: 96, height: 96, fit: BoxFit.cover, errorBuilder: (_, _, _) => const SizedBox(width: 96, height: 96, child: Icon(Icons.image))),
                    ),
                ]),
              ],
            ],
          ),
        );
      },
    );
  }

  Future<void> _step(BuildContext context, String event) async {
    try {
      await AppScope.of(context).act((w) => w.taskStep(taskId, event));
    } on StateError catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _submit(BuildContext context, Map<String, dynamic> task) async {
    final activity = (task['activity'] as Map?) ?? {};
    final quantity = TextEditingController(text: activity['target_quantity']?.toString() ?? '');
    final note = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Finish this task'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(
            key: const Key('quantity'),
            controller: quantity,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: 'How much did you do?', suffixText: activity['target_unit'] as String?),
          ),
          TextField(key: const Key('note'), controller: note, decoration: const InputDecoration(labelText: 'Note (optional)')),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          FilledButton(key: const Key('confirm-submit'), onPressed: () => Navigator.pop(context, true), child: const Text('Submit')),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
    await AppScope.of(context).act((w) => w.taskStep(taskId, 'submit',
        quantity: double.tryParse(quantity.text.replaceAll(',', '.')), unit: activity['target_unit'] as String?, note: note.text.trim()));
  }

  Future<void> _photo(BuildContext context) async {
    final shot = await ImagePicker().pickImage(source: ImageSource.camera, maxWidth: 1600, imageQuality: 80);
    if (shot == null || !context.mounted) return;
    final bytes = await shot.readAsBytes();
    if (!context.mounted) return;
    await AppScope.of(context).act((w) => w.addPhoto(taskId, shot.path, bytes));
  }
}
