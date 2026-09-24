# SFMTP mobile app (Flutter)

The phone app for everyone who works on the farm (Phase 6 started it for
field workers; Phase 11 completed it). It is **offline-first** (docs/08).
Every action changes the phone's database at once and goes into an outbox.
The sync engine pushes the outbox in order and pulls the server's changes.

What each member sees follows their permissions on the chosen farm:

| Tab | Shown with | What |
|---|---|---|
| Today | `tasks.execute` or `attendance.record` | Check in / out, today's tasks, start / pause / resume / finish with photos |
| Crops | `crops.operations.record` | Crop cycles in progress. Record an operation (with inputs and withholding) or report a problem (pest, disease …) at the phone's location |
| Animals | `livestock.animals.view` | Search the herd. Health, weight and milk / egg records (`livestock.records.record`); edit an animal's details (`livestock.animals.manage`) |
| Team | `tasks.verify` | Work waiting for a check: approve it, or send it back with a reason |
| Inbox | everyone | Notifications, and sync conflicts to resolve (keep mine / keep the server's, field by field) |

## How it works

- **Encrypted database.** SQLite through Drift, encrypted with
  SQLite3MultipleCiphers (the `sqlite3` package's `sqlite3mc` build,
  configured in `pubspec.yaml` under `hooks`). The 256-bit key is made on
  first start and kept in the Android Keystore / iOS Keychain. A plain
  database from v0 is encrypted in place on first start, with its unsent
  work.
- **Sync** runs:
  - on start and when the app returns to the foreground;
  - when the network comes back;
  - after each action;
  - on "Sync now";
  - every 15 minutes in the background (WorkManager on Android, BGTask on
    iOS), even when the app is closed.
- **Editing animals offline** sends the changed fields, their values at the
  last sync and the version seen. The server merges fields changed on one
  side only. A field changed on both sides becomes a conflict in the Inbox
  (and a notification) (ADR-0015).
- **Notifications.** The server's inbox is synced like any other data. The
  phone shows a notification for each new item: after a background sync,
  or at once when Firebase push is configured (below).
- **Signed out from the web.** The next sync wipes the farm data and the
  tokens (docs/08 §5).

## Layout

| Path | What |
|---|---|
| `lib/src/data/` | Drift tables (mirror of server records, outbox, photo queue, settings); opening the encrypted file |
| `lib/src/sync/field_work.dart` | Every action: local change + outbox mutation |
| `lib/src/sync/sync_engine.dart` | One sync cycle: upload photos, push, pull (snapshot or cursor) |
| `lib/src/sync/background.dart`, `announcer.dart` | The background sync entry point; phone notifications for new inbox items |
| `lib/src/device/` | Location, WorkManager, local notifications, connectivity, Firebase push |
| `lib/src/api/` | The API client (tokens in secure storage, idempotency keys, refresh, MFA) |
| `lib/src/ui/` | Sign-in (with the authenticator code) and farm choice; the tabs above |

## Run

```bash
flutter pub get
# Android emulator against a local API (the default URL):
flutter run
# Another API:
flutter run --dart-define=SFMTP_API_URL=https://api.example.com/api/v1
```

Push needs a Firebase project. Pass its settings as `--dart-define`s:
- `SFMTP_FIREBASE_PROJECT_ID`
- `SFMTP_FIREBASE_SENDER_ID`
- `SFMTP_FIREBASE_API_KEY`
- `SFMTP_FIREBASE_ANDROID_APP_ID` / `SFMTP_FIREBASE_IOS_APP_ID`

The API also needs `SFMTP_FCM_CREDENTIALS`. Without these settings,
notifications arrive with the next sync.

Demo accounts (password `Password123!`):
- `worker@aggfarms.test`: field worker; the milking task is in progress on
  the mixed farm.
- `agronomist@aggfarms.test`: crops on the crop farm.
- `livestock@aggfarms.test`: the herd on the mixed farm.
- `manager@aggfarms.test`: checks the team's work.

## Test

```bash
flutter analyze
flutter test                      # unit + widget tests against a fake server
SFMTP_LIVE_API=http://127.0.0.1:8000/api/v1 flutter test test/live_api_test.dart
```

`test/phase11_test.dart` holds the offline suite of docs/08 §6 for every
role:
- encryption and the plain-to-encrypted upgrade;
- the agronomist offline, syncing on reconnect;
- two edits of one animal (merge and conflict);
- the manager's late check (conflict and notification);
- push tokens;
- a remote sign-out wiping the phone;
- 1,000 queued mutations on a simulated 3G link (under 60 s);
- the role tabs and resolving a conflict in the UI.

The live test runs the worker's, agronomist's and two-phone animal
scenarios against a real API with the demo data. Reseed first with
`php artisan migrate:fresh --seed`.

After changing the tables, regenerate the Drift code:
`dart run build_runner build`.

## Store builds

CI builds release APKs split per ABI and checks their size. The
**SFMTP mobile release** workflow (run by hand) signs a build and sends
it to the internal testing tracks:
- the Play Console internal track;
- TestFlight.

It reads the secrets listed at the top of
`.github/workflows/sfmtp-mobile-release.yml`. Locally, a release build
uses `android/key.properties` when it exists, and otherwise the debug key.

## Not yet

- Background GPS tracks outside a work session. Not planned: location is
  only recorded during work (docs/08 §5).
- Harvest and treatment approvals on the phone.
- Offline editing of records other than animals.
- A generated Dart API client.
