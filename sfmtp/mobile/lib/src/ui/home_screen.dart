import 'package:flutter/material.dart';

import 'animals_screen.dart';
import 'crops_screen.dart';
import 'inbox_screen.dart';
import 'scope.dart';
import 'sync_screen.dart';
import 'team_screen.dart';
import 'today_screen.dart';

class _Tab {
  const _Tab(this.key, this.label, this.icon, this.permission, this.body);
  final String key;
  final String label;
  final IconData icon;
  final String? permission;
  final Widget body;
}

const _tabs = [
  _Tab('today', 'My day', Icons.today, 'tasks.execute|attendance.record', TodayView()),
  _Tab('crops', 'Crops', Icons.grass, 'crops.operations.record', CropsView()),
  _Tab('animals', 'Animals', Icons.pets, 'livestock.animals.view', AnimalsView()),
  _Tab('team', 'Team', Icons.fact_check_outlined, 'tasks.verify', TeamView()),
  _Tab('inbox', 'Inbox', Icons.inbox_outlined, null, InboxView()),
];

/// The app's home: one tab per kind of work the member does on this farm
/// (docs/10 Phase 11), all reading the phone's database.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  String? _current;

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    final tabs = [for (final t in _tabs) if (t.permission == null || app.can(t.permission!)) t];
    final index = tabs.indexWhere((t) => t.key == _current).clamp(0, tabs.length - 1);
    return Scaffold(
      appBar: AppBar(
        title: Text(app.farmName ?? 'SFMTP'),
        actions: [
          const SyncButton(),
          PopupMenuButton<String>(
            onSelected: (v) => v == 'signout' ? app.signOut() : null,
            itemBuilder: (_) => const [PopupMenuItem(value: 'signout', child: Text('Sign out'))],
          ),
        ],
      ),
      body: tabs[index].body,
      bottomNavigationBar: tabs.length < 2
          ? null
          : NavigationBar(
              selectedIndex: index,
              onDestinationSelected: (i) => setState(() => _current = tabs[i].key),
              destinations: [
                for (final t in tabs)
                  NavigationDestination(
                    key: Key('tab-${t.key}'),
                    icon: t.key == 'inbox' ? _InboxIcon(icon: t.icon) : Icon(t.icon),
                    label: t.label,
                  ),
              ],
            ),
    );
  }
}

/// Unread notifications plus open conflicts.
class _InboxIcon extends StatelessWidget {
  const _InboxIcon({required this.icon});
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    final db = AppScope.of(context).db;
    return StreamBuilder(
      stream: db.watchRecords('notifications'),
      builder: (context, n) => StreamBuilder(
        stream: db.watchRecords('conflicts'),
        builder: (context, c) {
          final count = (n.data ?? []).where((x) => x['read_at'] == null).length + (c.data ?? []).where((x) => x['status'] == 'open').length;
          return Badge(isLabelVisible: count > 0, label: Text('$count'), child: Icon(icon));
        },
      ),
    );
  }
}

/// The sync status in the app bar: waiting changes, problems, or all sent.
class SyncButton extends StatelessWidget {
  const SyncButton({super.key});

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return StreamBuilder(
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
    );
  }
}
