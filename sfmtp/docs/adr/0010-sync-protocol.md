# ADR-0010 — Offline sync as built: ordered mutations through the normal services, a change feed with a lag

**Status:** Accepted (2026-09-24, Phase 6)

## Context
Phase 6 starts the Flutter app for field workers, who often work without a
signal. docs/08 sets the principles: local first, client-generated ids,
idempotent push, the server is authoritative, append-only where possible.
Phase 6 has to choose how the server applies pushed changes, how the phone
learns what changed, and how the app talks to the API.

## Decision
1. **Push = ordered mutations applied by the same services.**
   `POST /farms/{farm}/sync/push` takes up to 200 mutations
   (`mutation_id`, `entity`, `op`, client `id`, `occurred_at`, `data`).
   Each one runs in its own transaction through the service behind the
   matching online endpoint (TaskFlow, AttendanceBook, FieldEvidence,
   LeaveDesk), with the same permission and validation rules. There is no
   second write path to keep in step.
2. **Exactly once.** Applied, conflicting and rejected results are stored in
   `sync_mutations`, keyed by farm, user and `mutation_id`, in the same
   transaction as the change. A repeat is answered `duplicate`, with the
   first result.
3. **Results.** `applied`; `conflict` (a state change that no longer fits,
   e.g. finishing a task cancelled meanwhile: the step is kept in the task
   log with `applied = false` as evidence, and the server's record is sent
   back); `rejected` (not allowed or invalid); `deferred` (the photo a record
   needs is not uploaded yet; not stored, so it is retried); `error`
   (unexpected; not stored). The phone never deletes a change before the
   server has answered for it.
4. **Pull = a change feed with a lag.** Workforce models write
   "entity + id changed" rows to `sync_changes` in the same transaction as
   the change. Its auto-increment id is the cursor. A pull returns each
   changed record once, in its current state, filtered by the same visibility
   rules and serialised by the same API resources as the web (so money and
   other workers' locations never reach a phone), or `remove` when the
   member no longer sees it. Rows younger than two seconds wait for the
   next pull, so a transaction that commits after a later one cannot slip
   behind the cursor. No cursor means a snapshot plus the feed head.
5. **The mirror.** A field worker's phone holds their own tasks for the
   coming 14 days plus recent ones, their recent attendance and leave, and
   their worker profile. Task data carries its activity's title,
   instructions and subject label, so the phone needs no plots or animals.
6. **Photos** upload first (`POST /media/uploads`, SHA-256 verified and
   deduplicated per farm) and the photo mutation then names the media id.
7. **Dart client.** The app uses a small hand-written client for the
   endpoints it needs (auth, workspaces, sync, media), tested against a fake
   server and, in CI, against the real API with the demo farm. Generating a
   full Dart client from the OpenAPI file is deferred until the app covers
   more of the API (Phase 11).

## Consequences
- Adding an offline action means adding one handler that validates like the
  endpoint and calls its service.
- Conflicts on editable master data (field-level merge, `sync/conflicts`)
  are not needed yet: Phase 6 mutations are append-only records and state
  machine steps. They come with offline editing in Phase 11.
- `sync_changes` grows with every change; trimming rows older than the
  longest offline period (with a snapshot fallback for older cursors)
  belongs to Phase 11 / 15.
- The local database is not encrypted yet (SQLCipher is Phase 11); tokens
  are already in the Keystore / Keychain.
