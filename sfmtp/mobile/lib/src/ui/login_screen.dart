import 'package:flutter/material.dart';

import '../api/api_client.dart';
import 'scope.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  String? _error;
  List<Map<String, dynamic>>? _farms;

  Future<void> _signIn() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final app = AppScope.of(context);
    try {
      final farms = await app.signIn(_email.text.trim(), _password.text);
      if (farms.length == 1) {
        await app.chooseFarm(farms.single['id'] as String, farms.single['name'] as String);
      } else {
        setState(() => _farms = farms);
      }
    } on ApiException catch (e) {
      setState(() => _error = e.isNetwork ? 'No connection. Sign in once with a connection; after that the app works offline.' : e.title);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final farms = _farms;
    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            const SizedBox(height: 32),
            Icon(Icons.eco, size: 48, color: Theme.of(context).colorScheme.primary),
            const SizedBox(height: 12),
            Text('SFMTP', textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineMedium),
            const SizedBox(height: 32),
            if (farms == null) ...[
              TextField(
                key: const Key('email'),
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                autofillHints: const [AutofillHints.email],
                decoration: const InputDecoration(labelText: 'Email', border: OutlineInputBorder()),
              ),
              const SizedBox(height: 12),
              TextField(
                key: const Key('password'),
                controller: _password,
                obscureText: true,
                decoration: const InputDecoration(labelText: 'Password', border: OutlineInputBorder()),
                onSubmitted: (_) => _signIn(),
              ),
              if (_error != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error))),
              const SizedBox(height: 20),
              FilledButton(onPressed: _busy ? null : _signIn, child: Text(_busy ? 'Signing in…' : 'Sign in')),
            ] else ...[
              Text('Which farm?', style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 8),
              if (farms.isEmpty) const Text('Your account is not a member of any farm yet.'),
              for (final f in farms)
                Card(
                  child: ListTile(
                    title: Text(f['name'] as String),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => AppScope.of(context).chooseFarm(f['id'] as String, f['name'] as String),
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}
