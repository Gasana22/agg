import 'package:flutter/material.dart';

const statusLabels = {
  'assigned': 'To do',
  'in_progress': 'In progress',
  'paused': 'Paused',
  'submitted': 'Waiting for check',
  'verified': 'Verified',
  'rejected': 'Redo',
  'cancelled': 'Cancelled',
};

Color statusColor(String status, ColorScheme c) => switch (status) {
      'in_progress' => c.primary,
      'paused' || 'submitted' => Colors.orange.shade800,
      'verified' => Colors.green.shade700,
      'rejected' => c.error,
      _ => c.outline,
    };

const actionLabels = {'start': 'Start', 'pause': 'Pause', 'resume': 'Resume', 'submit': 'Done'};

String clock(String? iso) {
  if (iso == null) return '—';
  final t = DateTime.parse(iso).toLocal();
  return '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';
}

String todayIso([DateTime? now]) {
  final d = now ?? DateTime.now();
  return '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
}
