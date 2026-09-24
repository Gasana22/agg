# ADR-0015 — The complete mobile app: role feeds, field merge, conflicts, notifications, encryption

**Status:** Accepted (2026-09-24, Phase 11)

## Context
Phase 6 proved the sync protocol for the field worker ([ADR-0010](0010-sync-protocol.md)).
Phase 11 brings the agronomist, livestock and manager flows to the phone.
Docs/08 §4 asks for a field-level merge of editable master data, with
conflicts the member resolves, and docs/08 §5 asks for an encrypted
database. Members also need to hear about new work, work sent back and
conflicts, even with the app closed and on patchy networks.

## Decision
1. **One feed, filtered by permission.** The sync feed now carries:
   - `plots` (with `structure.view` for the whole farm);
   - `crop_cycles` not closed (`crops.plans.view`);
   - `animal_groups` and active `animals` (`livestock.animals.view`, with
     its scope);
   - `team_tasks`, the submitted work a supervisor may check
     (`tasks.verify`);
   - the member's own `notifications` (last 30 days) and open `conflicts`.

   The field worker entities stay as they were. The phone asks for
   everything and the server drops what the member may not see. So the
   phone never decides access, and the tabs it shows are a convenience.
2. **New mutations reuse the web's rules.** Crop operations and
   observations, health / weight / production records, animal edits and
   task reviews go through the same use-case services and the same
   validation rules as the web (the controllers' rules are shared). Records
   are append-only, so they cannot conflict; `mutation_id` stops
   duplicates.
3. **Animal edits merge per field.** The phone sends the changed fields,
   their values at the last sync (`base`) and the version it saw
   (`base_version`). The server compares each field with its current value:
   - unchanged on the server since `base`: the phone's value is applied;
   - already equal: nothing to do;
   - changed on both sides to different values: a conflict.

   Fields changed on one side are merged even when others conflict. Only
   an allow-list of detail fields can be edited this way. Group moves,
   status and parentage keep their own records.
4. **Conflicts live on the server.** A conflict is a `sync_conflicts` row
   (farm-scoped, RLS). It holds base / mine / server per field, belongs to
   the member who made the change, and sends them a `sync_conflict`
   notification. The phone drops the outbox entry: the conflict reaches
   the phone through the feed, and the member resolves it field by field
   (keep mine / keep the server's). The resolution is itself a mutation
   (`sync_conflicts.resolve`), so it works offline. Only the owner of the
   conflict can resolve it, and only once. Keeping "mine" writes the value
   through the normal service, with audit and a new version.
5. **Late state transitions stay conflicts.** A manager's check of work
   that was sent back or checked elsewhere in the meantime is a
   `conflict` with the server's record. A worker's refused step also
   notifies whoever may verify tasks.
6. **Notifications are an inbox first.** `member_notifications` (RLS) is
   the source of truth: it can be read on the web, is synced to the phone,
   and supports marking as read. Pushes are a hint to fetch it, sent after
   the transaction commits by a queued job through a `PushSender`:
   - Firebase Cloud Messaging HTTP v1, when `SFMTP_FCM_CREDENTIALS` is
     set. It signs a service-account JWT itself, with no SDK.
   - Otherwise a sender that only logs.

   Tokens FCM reports as invalid are forgotten. The phone registers its
   token per device (`PUT /me/devices/current/push-token`). Firebase
   settings come from build-time defines, so no Firebase config file is
   committed. Without push, the phone shows new inbox items after each
   sync (every 15 minutes in the background). A push the system already
   showed is marked, so the next sync does not show it again.
7. **Encryption with SQLite3MultipleCiphers.** `sqlcipher_flutter_libs`
   has reached end of life, so the database is encrypted by the `sqlite3`
   package's `sqlite3mc` build (a build hook, no extra plugin). The
   256-bit key is random, made once, and kept in the Keystore / Keychain.
   Opening checks the cipher is active, and a wrong key fails. A v0 plain
   file is re-keyed in place on first start, so unsent work survives the
   upgrade. The build is the same in host tests, so the tests check the
   encryption itself.
8. **Background sync.** WorkManager runs a periodic task (every 15 minutes,
   with network, exponential back-off). iOS uses BGTaskScheduler with the
   same identifier. The task opens the same database, syncs, wipes on
   `device_revoked`, and shows new notifications. A push from the app and
   one from the background task at the same time are harmless: the second
   is answered `duplicate`.
9. **Store builds.**
   - CI builds release APKs split per ABI and keeps armeabi-v7a under
     30 MB.
   - A manual workflow signs with the upload key from secrets and sends
     the bundle to the Play internal track, and the IPA to TestFlight.
   - Build numbers come from the run number.

## Consequences
- Adding a mobile entity takes three steps:
  - a query and a presenter in `SyncEntities`;
  - a feed hook on the model;
  - a mirror on the phone.

  Adding a mobile action is a handler that calls the existing service.
- Conflicts and merges are only for animals for now. Other editable
  records (plots, worker profiles) can reuse `FieldMerge` when they get
  offline editing.
- The phone learns about a conflict one pull lag (2 s) after the push that
  caused it. In practice that is the next sync.
- Without Firebase, a notification can take up to 15 minutes to reach a
  closed app (sooner when the phone reconnects or the app opens).
- Losing the Keychain / Keystore entry (for example, a restore to a new
  phone) makes the database unreadable. The app starts empty and the
  member signs in again. Unsent work on that phone is lost, as it would be
  with the phone itself.
