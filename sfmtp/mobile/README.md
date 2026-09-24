# SFMTP field app (Flutter)

The field worker's app, v0 (Phase 6): sign in, pick the farm, check in and
out, see today's tasks, start / pause / resume / finish them, take photos
of the work. It is **offline-first** (docs/08): every action changes the
phone's database at once and goes into an outbox; the sync engine pushes
the outbox in order and pulls the server's changes.

## Layout

| Path | What |
|---|---|
| `lib/src/data/database.dart` | Drift (SQLite) tables: the mirror of server records, the outbox, the photo queue, settings |
| `lib/src/sync/field_work.dart` | The worker's actions: local change + outbox mutation |
| `lib/src/sync/sync_engine.dart` | One sync cycle: upload photos, push, pull (snapshot or cursor) |
| `lib/src/api/` | The API client (tokens in the Keystore / Keychain, idempotency keys, refresh) |
| `lib/src/ui/` | Screens: sign-in, today, task, sync status |

## Run

```bash
flutter pub get
# Android emulator against a local API (the default URL):
flutter run
# Another API:
flutter run --dart-define=SFMTP_API_URL=https://api.example.com/api/v1
```

Demo account: `worker@aggfarms.test` / `Password123!` (Wilson Worker; the
mixed farm has his milking task in progress).

## Test

```bash
flutter analyze
flutter test                      # unit + widget tests, fake server
SFMTP_LIVE_API=http://127.0.0.1:8000/api/v1 flutter test test/live_api_test.dart
```

The live test runs the offline scenario against a real API with the demo
data (reseed first: `php artisan migrate:fresh --seed`).

After changing the tables, regenerate the Drift code:
`dart run build_runner build`.

## Not yet (Phase 11)

Encrypted local database (SQLCipher), background sync and GPS tracks,
push notifications, conflict resolution screens for editable records,
and the agronomist, livestock and manager flows.
