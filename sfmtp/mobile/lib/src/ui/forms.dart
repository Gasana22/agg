import 'package:flutter/material.dart';

/// A bottom sheet with a form: fields, an error line and a save button.
/// `onSave` returns normally to close, or throws a StateError to show its message.
Future<bool> showFormSheet(BuildContext context, {required String title, required List<Widget> Function(StateSetter setState) fields, required Future<void> Function() onSave, String saveLabel = 'Save'}) async {
  String? error;
  var busy = false;
  final saved = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    builder: (sheet) => StatefulBuilder(
      builder: (sheet, setState) => Padding(
        padding: EdgeInsets.only(left: 16, right: 16, top: 16, bottom: MediaQuery.of(sheet).viewInsets.bottom + 16),
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(title, style: Theme.of(sheet).textTheme.titleLarge),
              const SizedBox(height: 12),
              ...fields(setState),
              if (error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(error!, style: TextStyle(color: Theme.of(sheet).colorScheme.error))),
              const SizedBox(height: 12),
              FilledButton(
                key: const Key('form-save'),
                onPressed: busy
                    ? null
                    : () async {
                        setState(() {
                          busy = true;
                          error = null;
                        });
                        try {
                          await onSave();
                          if (sheet.mounted) Navigator.of(sheet).pop(true);
                        } on StateError catch (e) {
                          setState(() {
                            error = e.message;
                            busy = false;
                          });
                        }
                      },
                child: Text(saveLabel),
              ),
            ],
          ),
        ),
      ),
    ),
  );
  if (saved == true && context.mounted) {
    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Saved on the phone. It syncs when there is a connection.')));
  }
  return saved == true;
}

Widget field(String key, TextEditingController c, String label, {TextInputType? keyboard, int lines = 1}) => Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: TextField(key: Key(key), controller: c, keyboardType: keyboard, maxLines: lines, decoration: InputDecoration(labelText: label, border: const OutlineInputBorder())),
    );

Widget choice<T>(String key, String label, T value, Map<T, String> options, void Function(T) onChanged) => Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: DropdownButtonFormField<T>(
        key: Key(key),
        initialValue: value,
        decoration: InputDecoration(labelText: label, border: const OutlineInputBorder()),
        items: [for (final e in options.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
        onChanged: (v) => v == null ? null : onChanged(v),
      ),
    );

double? number(TextEditingController c) => double.tryParse(c.text.trim().replaceAll(',', '.'));

String? text(TextEditingController c) => c.text.trim().isEmpty ? null : c.text.trim();
