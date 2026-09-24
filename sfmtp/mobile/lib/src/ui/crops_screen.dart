import 'package:flutter/material.dart';

import 'forms.dart';
import 'scope.dart';

const operationTypes = {
  'land_preparation': 'Land preparation',
  'planting': 'Planting',
  'weeding': 'Weeding',
  'fertilizing': 'Fertilising',
  'spraying': 'Spraying',
  'irrigation': 'Irrigation',
  'scouting': 'Scouting',
  'pruning': 'Pruning',
  'thinning': 'Thinning',
  'other': 'Other',
};
const observationKinds = {'pest': 'Pest', 'disease': 'Disease', 'weed': 'Weeds', 'nutrient': 'Nutrient problem', 'water': 'Water', 'growth': 'Growth', 'weather': 'Weather damage', 'other': 'Other'};
const severities = {'low': 'Low', 'medium': 'Medium', 'high': 'High', 'critical': 'Critical'};

/// The agronomist's open crop cycles, from the phone's database.
class CropsView extends StatelessWidget {
  const CropsView({super.key});

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return StreamBuilder(
      stream: app.db.watchRecords('crop_cycles'),
      builder: (context, snap) {
        final cycles = [...(snap.data ?? [])]..sort((a, b) => ((a['plot'] as Map?)?['code'] as String? ?? '').compareTo((b['plot'] as Map?)?['code'] as String? ?? ''));
        return RefreshIndicator(
          onRefresh: app.syncNow,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text('Crops in the field', style: Theme.of(context).textTheme.titleLarge),
              const SizedBox(height: 8),
              if (cycles.isEmpty) const Padding(padding: EdgeInsets.symmetric(vertical: 24), child: Text('No open crop cycles.', textAlign: TextAlign.center)),
              for (final c in cycles)
                Card(
                  child: ListTile(
                    key: Key('cycle-${c['id']}'),
                    leading: const Icon(Icons.grass),
                    title: Text('${(c['crop'] as Map?)?['label'] ?? 'Crop'} · Plot ${(c['plot'] as Map?)?['code'] ?? '?'}'),
                    subtitle: Text([c['code'], (c['stage'] as String).replaceAll('_', ' '), if (c['safe_harvest_on'] != null) 'safe to harvest ${c['safe_harvest_on']}'].join(' · ')),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => CycleScreen(cycleId: c['id'] as String))),
                  ),
                ),
            ],
          ),
        );
      },
    );
  }
}

class CycleScreen extends StatelessWidget {
  const CycleScreen({super.key, required this.cycleId});
  final String cycleId;

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return StreamBuilder(
      stream: app.db.watchRecord('crop_cycles', cycleId),
      builder: (context, snap) {
        final c = snap.data;
        return Scaffold(
          appBar: AppBar(title: Text(c == null ? 'Crop' : '${(c['crop'] as Map?)?['label']} · Plot ${(c['plot'] as Map?)?['code']}')),
          body: c == null
              ? const Center(child: Text('This crop cycle is no longer on the phone.'))
              : StreamBuilder(
                  stream: app.db.watchOutbox(),
                  builder: (context, out) {
                    final waiting = (out.data ?? []).where((o) => o.target == 'crop_cycles:$cycleId' && o.status == 'pending').length;
                    return ListView(
                      padding: const EdgeInsets.all(16),
                      children: [
                        _row('Cycle', c['code'] as String?),
                        _row('Stage', (c['stage'] as String).replaceAll('_', ' ')),
                        _row('Planted', c['planted_on'] as String? ?? c['sown_on'] as String?),
                        _row('Expected harvest', c['expected_harvest_on'] as String?),
                        _row('Safe to harvest from', c['safe_harvest_on'] as String?),
                        _row('Area', '${c['area_ha']} ha'),
                        if (waiting > 0) Padding(padding: const EdgeInsets.only(top: 8), child: Text('$waiting records waiting to sync.', key: const Key('waiting'))),
                        const SizedBox(height: 16),
                        FilledButton.icon(key: const Key('record-operation'), onPressed: () => _operation(context), icon: const Icon(Icons.agriculture), label: const Text('Record field work')),
                        const SizedBox(height: 8),
                        OutlinedButton.icon(key: const Key('report-problem'), onPressed: () => _observation(context), icon: const Icon(Icons.bug_report_outlined), label: const Text('Report a problem')),
                      ],
                    );
                  },
                ),
        );
      },
    );
  }

  Widget _row(String label, String? value) => ListTile(dense: true, title: Text(label), trailing: Text(value ?? '—'));

  Future<void> _operation(BuildContext context) {
    final app = AppScope.of(context);
    var type = 'weeding';
    final notes = TextEditingController();
    final hours = TextEditingController();
    final product = TextEditingController();
    final quantity = TextEditingController();
    final unit = TextEditingController(text: 'kg');
    final withholding = TextEditingController();
    return showFormSheet(
      context,
      title: 'Record field work',
      fields: (setState) => [
        choice('operation-type', 'Work', type, operationTypes, (v) => setState(() => type = v)),
        field('labour-hours', hours, 'Labour hours', keyboard: TextInputType.number),
        field('notes', notes, 'Notes', lines: 2),
        if (['fertilizing', 'spraying', 'planting', 'other'].contains(type)) ...[
          const Text('Input used (optional)'),
          const SizedBox(height: 6),
          field('input-product', product, 'Product'),
          Row(children: [
            Expanded(child: field('input-quantity', quantity, 'Quantity', keyboard: TextInputType.number)),
            const SizedBox(width: 8),
            Expanded(child: field('input-unit', unit, 'Unit')),
          ]),
          field('input-withholding', withholding, 'Withholding days', keyboard: TextInputType.number),
        ],
      ],
      onSave: () async {
        final inputs = <Map<String, dynamic>>[];
        if (text(product) != null) {
          if (number(quantity) == null) throw StateError('Enter how much of ${product.text.trim()} was used.');
          inputs.add({'product_name': text(product), 'quantity': number(quantity), 'unit': text(unit) ?? 'kg', 'withholding_days': ?number(withholding)?.round()});
        }
        await app.act((w) => w.recordOperation(cycleId, type: type, notes: text(notes), labourHours: number(hours), inputs: inputs));
      },
    );
  }

  Future<void> _observation(BuildContext context) {
    final app = AppScope.of(context);
    var kind = 'pest';
    var severity = 'medium';
    final title = TextEditingController();
    final description = TextEditingController();
    final affected = TextEditingController();
    return showFormSheet(
      context,
      title: 'Report a problem',
      fields: (setState) => [
        choice('observation-kind', 'Kind', kind, observationKinds, (v) => setState(() => kind = v)),
        choice('observation-severity', 'Severity', severity, severities, (v) => setState(() => severity = v)),
        field('observation-title', title, 'What is it? (e.g. Fall armyworm)'),
        field('observation-affected', affected, '% of the plot affected', keyboard: TextInputType.number),
        field('observation-description', description, 'Details', lines: 3),
        const Text('Your location is attached when available.', style: TextStyle(fontSize: 12)),
      ],
      onSave: () async {
        if (text(title) == null) throw StateError('Say what you saw.');
        await app.act((w) => w.reportObservation(cycleId, kind: kind, severity: severity, title: text(title)!, description: text(description), affectedPct: number(affected)));
      },
    );
  }
}
