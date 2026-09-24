import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../app_state.dart';
import 'scope.dart';

/// Sign in (with the authenticator code when the account uses one), then
/// choose the farm.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();
  bool _busy = false;
  String? _error;
  String? _mfaToken;
  List<FarmChoice>? _farms;

  Future<void> _run(Future<void> Function(AppState app) step) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final app = AppScope.of(context);
    try {
      await step(app);
    } on ApiException catch (e) {
      setState(() => _error = e.isNetwork ? 'No connection. Sign in once with a connection; after that the app works offline.' : e.title);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _afterSignIn(AppState app) async {
    final farms = await app.farms();
    if (farms.length == 1) {
      await app.chooseFarm(farms.single);
    } else {
      setState(() => _farms = farms);
    }
  }

  Future<void> _signIn() => _run((app) async {
        final mfa = await app.signIn(_email.text.trim(), _password.text);
        if (mfa != null) {
          setState(() => _mfaToken = mfa);
          return;
        }
        await _afterSignIn(app);
      });

  Future<void> _confirm() => _run((app) async {
        await app.confirmCode(_mfaToken!, _code.text.trim());
        await _afterSignIn(app);
      });

  @override
  Widget build(BuildContext context) {
    final farms = _farms;
    final notice = AppScope.of(context).notice;
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
            if (notice != null && farms == null && _mfaToken == null)
              Card(color: Theme.of(context).colorScheme.errorContainer, child: Padding(padding: const EdgeInsets.all(12), child: Text(notice, key: const Key('notice')))),
            if (farms != null) ...[
              Text('Which farm?', style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 8),
              if (farms.isEmpty) const Text('Your account is not a member of any farm yet.'),
              for (final f in farms)
                Card(
                  child: ListTile(
                    title: Text(f.name),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => AppScope.of(context).chooseFarm(f),
                  ),
                ),
            ] else if (_mfaToken != null) ...[
              Text('Enter the 6-digit code from your authenticator app, or a recovery code.', style: Theme.of(context).textTheme.bodyLarge),
              const SizedBox(height: 12),
              TextField(
                key: const Key('code'),
                controller: _code,
                keyboardType: TextInputType.number,
                autofillHints: const [AutofillHints.oneTimeCode],
                decoration: const InputDecoration(labelText: 'Code', border: OutlineInputBorder()),
                onSubmitted: (_) => _confirm(),
              ),
              if (_error != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error))),
              const SizedBox(height: 20),
              FilledButton(key: const Key('confirm-code'), onPressed: _busy ? null : _confirm, child: Text(_busy ? 'Checking…' : 'Continue')),
            ] else ...[
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
            ],
          ],
        ),
      ),
    );
  }
}
