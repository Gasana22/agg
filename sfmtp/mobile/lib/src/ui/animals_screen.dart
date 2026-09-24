import 'package:flutter/material.dart';

import 'forms.dart';
import 'scope.dart';

const healthKinds = {'treatment': 'Treatment', 'vaccination': 'Vaccination', 'deworming': 'Deworming', 'checkup': 'Check-up', 'injury': 'Injury', 'other': 'Other'};

/// Active animals the member may see, searchable, from the phone's database.
class AnimalsView extends StatefulWidget {
  const AnimalsView({super.key});

  @override
  State<AnimalsView> createState() => _AnimalsViewState();
}

class _AnimalsViewState extends State<AnimalsView> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return StreamBuilder(
      stream: app.db.watchRecords('animals'),
      builder: (context, snap) {
        final q = _q.toLowerCase();
        final animals = (snap.data ?? [])
            .where((a) => q.isEmpty || [a['animal_code'], a['tag_number'], a['name']].any((v) => (v as String? ?? '').toLowerCase().contains(q)))
            .toList()
          ..sort((a, b) => (a['animal_code'] as String? ?? '').compareTo(b['animal_code'] as String? ?? ''));
        return RefreshIndicator(
          onRefresh: app.syncNow,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              TextField(
                key: const Key('animal-search'),
                decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'Code, tag or name', border: OutlineInputBorder()),
                onChanged: (v) => setState(() => _q = v.trim()),
              ),
              const SizedBox(height: 12),
              if (animals.isEmpty) const Padding(padding: EdgeInsets.symmetric(vertical: 24), child: Text('No animals found.', textAlign: TextAlign.center)),
              for (final a in animals.take(200))
                Card(
                  child: ListTile(
                    key: Key('animal-${a['id']}'),
                    leading: const Icon(Icons.pets),
                    title: Text([a['animal_code'], a['name']].whereType<String>().join(' · ')),
                    subtitle: Text([
                      (a['group'] as Map?)?['name'],
                      if (a['tag_number'] != null) 'tag ${a['tag_number']}',
                      if (a['milk_withdrawal_until'] != null) 'no milk until ${a['milk_withdrawal_until']}',
                    ].whereType<String>().join(' · ')),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => AnimalScreen(animalId: a['id'] as String))),
                  ),
                ),
            ],
          ),
        );
      },
    );
  }
}

class AnimalScreen extends StatelessWidget {
  const AnimalScreen({super.key, required this.animalId});
  final String animalId;

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return StreamBuilder(
      stream: app.db.watchRecord('animals', animalId),
      builder: (context, snap) {
        final a = snap.data;
        final canRecord = app.can('livestock.records.record');
        return Scaffold(
          appBar: AppBar(title: Text(a == null ? 'Animal' : [a['animal_code'], a['name']].whereType<String>().join(' · '))),
          body: a == null
              ? const Center(child: Text('This animal is no longer on the phone.'))
              : ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    if (a['milk_withdrawal_until'] != null || a['meat_withdrawal_until'] != null)
                      Card(
                        color: Theme.of(context).colorScheme.errorContainer,
                        child: ListTile(
                          leading: const Icon(Icons.warning_amber),
                          title: const Text('Under withdrawal'),
                          subtitle: Text([
                            if (a['milk_withdrawal_until'] != null) 'Milk until ${a['milk_withdrawal_until']}',
                            if (a['meat_withdrawal_until'] != null) 'Meat until ${a['meat_withdrawal_until']}',
                          ].join(' · ')),
                        ),
                      ),
                    _row('Species', (a['species'] as Map?)?['name'] as String?),
                    _row('Sex', a['sex'] as String?),
                    _row('Tag', a['tag_number'] as String?),
                    _row('Group', (a['group'] as Map?)?['name'] as String?),
                    _row('Last weight', a['last_weight_kg'] == null ? null : '${a['last_weight_kg']} kg (${a['last_weighed_on']})'),
                    if (a['notes'] != null) Padding(padding: const EdgeInsets.all(16), child: Text(a['notes'] as String)),
                    const SizedBox(height: 12),
                    if (canRecord) ...[
                      FilledButton.icon(key: const Key('record-health'), onPressed: () => _health(context), icon: const Icon(Icons.medical_services_outlined), label: const Text('Health record')),
                      const SizedBox(height: 8),
                      OutlinedButton.icon(key: const Key('record-weight'), onPressed: () => _weight(context), icon: const Icon(Icons.monitor_weight_outlined), label: const Text('Weight')),
                      const SizedBox(height: 8),
                      OutlinedButton.icon(key: const Key('record-production'), onPressed: () => _production(context, a), icon: const Icon(Icons.water_drop_outlined), label: const Text('Milk or eggs')),
                    ],
                    if (app.can('livestock.animals.manage')) ...[
                      const SizedBox(height: 8),
                      TextButton.icon(key: const Key('edit-animal'), onPressed: () => _edit(context, a), icon: const Icon(Icons.edit_outlined), label: const Text('Edit details')),
                    ],
                  ],
                ),
        );
      },
    );
  }

  Widget _row(String label, String? value) => ListTile(dense: true, title: Text(label), trailing: Text(value ?? '—'));

  Future<void> _health(BuildContext context) {
    final app = AppScope.of(context);
    var kind = 'treatment';
    final product = TextEditingController();
    final diagnosis = TextEditingController();
    final milk = TextEditingController();
    final meat = TextEditingController();
    return showFormSheet(
      context,
      title: 'Health record',
      fields: (setState) => [
        choice('health-kind', 'Kind', kind, healthKinds, (v) => setState(() => kind = v)),
        field('health-diagnosis', diagnosis, 'Diagnosis'),
        field('health-product', product, 'Product given'),
        Row(children: [
          Expanded(child: field('health-milk', milk, 'Milk withdrawal (days)', keyboard: TextInputType.number)),
          const SizedBox(width: 8),
          Expanded(child: field('health-meat', meat, 'Meat withdrawal (days)', keyboard: TextInputType.number)),
        ]),
      ],
      onSave: () async {
        if ((kind == 'vaccination' || kind == 'deworming') && text(product) == null) throw StateError('Name the product given.');
        await app.act((w) => w.recordHealth(animalId: animalId, kind: kind, productName: text(product), diagnosis: text(diagnosis),
            milkWithdrawalDays: number(milk)?.round(), meatWithdrawalDays: number(meat)?.round()));
      },
    );
  }

  Future<void> _weight(BuildContext context) {
    final app = AppScope.of(context);
    final kg = TextEditingController();
    return showFormSheet(
      context,
      title: 'Weight',
      fields: (_) => [field('weight-kg', kg, 'Weight (kg)', keyboard: TextInputType.number)],
      onSave: () async {
        final v = number(kg);
        if (v == null || v <= 0) throw StateError('Enter the weight in kilograms.');
        await app.act((w) => w.recordWeight(animalId, v));
      },
    );
  }

  Future<void> _production(BuildContext context, Map<String, dynamic> animal) {
    final app = AppScope.of(context);
    var product = 'milk';
    var session = 'am';
    var discarded = animal['milk_withdrawal_until'] != null;
    final quantity = TextEditingController();
    return showFormSheet(
      context,
      title: 'Milk or eggs',
      fields: (setState) => [
        choice('production-product', 'Product', product, const {'milk': 'Milk (litres)', 'eggs': 'Eggs'}, (v) => setState(() => product = v)),
        if (product == 'milk') choice('production-session', 'Milking', session, const {'am': 'Morning', 'pm': 'Evening', 'day': 'Whole day'}, (v) => setState(() => session = v)),
        field('production-quantity', quantity, product == 'milk' ? 'Litres' : 'Eggs', keyboard: TextInputType.number),
        CheckboxListTile(
          key: const Key('production-discarded'),
          contentPadding: EdgeInsets.zero,
          value: discarded,
          onChanged: (v) => setState(() => discarded = v ?? false),
          title: const Text('Discarded (under withdrawal)'),
        ),
      ],
      onSave: () async {
        final q = number(quantity);
        if (q == null || q < 0) throw StateError('Enter the quantity.');
        await app.act((w) => w.recordProduction(animalId: animalId, product: product, quantity: q, unit: product == 'milk' ? 'l' : 'pcs', session: product == 'milk' ? session : null, discarded: discarded));
      },
    );
  }

  Future<void> _edit(BuildContext context, Map<String, dynamic> a) {
    final app = AppScope.of(context);
    final name = TextEditingController(text: a['name'] as String? ?? '');
    final tag = TextEditingController(text: a['tag_number'] as String? ?? '');
    final notes = TextEditingController(text: a['notes'] as String? ?? '');
    return showFormSheet(
      context,
      title: 'Edit details',
      fields: (_) => [
        field('edit-name', name, 'Name'),
        field('edit-tag', tag, 'Ear tag'),
        field('edit-notes', notes, 'Notes', lines: 3),
        const Text('If someone else changes the same detail meanwhile, you will be asked which to keep.', style: TextStyle(fontSize: 12)),
      ],
      onSave: () => app.act((w) => w.editAnimal(animalId, {'name': text(name), 'tag_number': text(tag), 'notes': text(notes)})),
    );
  }
}
