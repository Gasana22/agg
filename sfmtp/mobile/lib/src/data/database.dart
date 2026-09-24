import 'dart:convert';

import 'package:drift/drift.dart';

part 'database.g.dart';

/// The phone's copy of what the member may see (docs/08 §2), stored as the
/// server's JSON: a worker's tasks, attendance, leave and profile; plots and
/// crop cycles; animal groups and animals; tasks to verify; notifications
/// and open sync conflicts.
@DataClassName('MirrorRecord')
class MirrorRecords extends Table {
  TextColumn get entity => text()(); // tasks | attendance | leave | workers | plots | crop_cycles | animals …
  TextColumn get id => text()();
  IntColumn get version => integer().nullable()();
  TextColumn get data => text()(); // JSON
  /// Changed on the phone and not yet confirmed by the server.
  BoolColumn get pending => boolean().withDefault(const Constant(false))();

  @override
  Set<Column> get primaryKey => {entity, id};
}

/// Changes made on the phone, pushed in the order they were made.
@DataClassName('OutboxEntry')
class Outbox extends Table {
  IntColumn get seq => integer().autoIncrement()();
  TextColumn get mutationId => text().unique()();
  TextColumn get entity => text()();
  TextColumn get op => text()();
  TextColumn get recordId => text().nullable()(); // the client id sent as `id`
  /// The mirrored record the change applies to, e.g. `tasks:<id>`.
  TextColumn get target => text().nullable()();
  TextColumn get occurredAt => text()(); // ISO-8601 UTC, the phone's time
  /// For edits: the record version the phone saw (field-level merge, docs/08 §4).
  IntColumn get baseVersion => integer().nullable()();
  TextColumn get data => text()(); // JSON
  /// pending → (pushed) removed; or rejected / conflict, kept for the sync screen.
  TextColumn get status => text().withDefault(const Constant('pending'))();
  IntColumn get attempts => integer().withDefault(const Constant(0))();
  TextColumn get lastError => text().nullable()();
}

/// Photos waiting to be uploaded; mutations point at them until they have a media id.
@DataClassName('QueuedMedia')
class MediaQueue extends Table {
  TextColumn get localId => text()();
  TextColumn get path => text()();
  TextColumn get sha256 => text()();
  TextColumn get mediaId => text().nullable()();
  IntColumn get attempts => integer().withDefault(const Constant(0))();
  TextColumn get lastError => text().nullable()();

  @override
  Set<Column> get primaryKey => {localId};
}

/// Small key/value settings: farm, pull cursor, last sync.
class SyncState extends Table {
  TextColumn get key => text()();
  TextColumn get value => text()();

  @override
  Set<Column> get primaryKey => {key};
}

@DriftDatabase(tables: [MirrorRecords, Outbox, MediaQueue, SyncState])
class AppDatabase extends _$AppDatabase {
  AppDatabase(super.e);

  @override
  int get schemaVersion => 2;

  @override
  MigrationStrategy get migration => MigrationStrategy(
        onUpgrade: (m, from, to) async {
          if (from < 2) await m.addColumn(outbox, outbox.baseVersion);
        },
      );

  // Mirror

  Future<void> upsertRecord(String entity, String id, int? version, Map<String, dynamic> data, {bool pending = false}) =>
      into(mirrorRecords).insertOnConflictUpdate(MirrorRecordsCompanion.insert(
        entity: entity,
        id: id,
        version: Value(version),
        data: jsonEncode(data),
        pending: Value(pending),
      ));

  Future<void> removeRecord(String entity, String id) =>
      (delete(mirrorRecords)..where((r) => r.entity.equals(entity) & r.id.equals(id))).go();

  Future<Map<String, dynamic>?> record(String entity, String id) async {
    final row = await (select(mirrorRecords)..where((r) => r.entity.equals(entity) & r.id.equals(id))).getSingleOrNull();
    return row == null ? null : jsonDecode(row.data) as Map<String, dynamic>;
  }

  Stream<List<Map<String, dynamic>>> watchRecords(String entity) =>
      (select(mirrorRecords)..where((r) => r.entity.equals(entity))).watch().map((rows) => [for (final r in rows) jsonDecode(r.data) as Map<String, dynamic>]);

  /// One record, without decoding the rest of the entity (large herds on slow phones).
  Stream<Map<String, dynamic>?> watchRecord(String entity, String id) =>
      (select(mirrorRecords)..where((r) => r.entity.equals(entity) & r.id.equals(id))).watchSingleOrNull().map((r) => r == null ? null : jsonDecode(r.data) as Map<String, dynamic>);

  Future<List<Map<String, dynamic>>> records(String entity) async =>
      [for (final r in await (select(mirrorRecords)..where((r) => r.entity.equals(entity))).get()) jsonDecode(r.data) as Map<String, dynamic>];

  // Outbox

  Future<List<OutboxEntry>> pendingMutations() =>
      (select(outbox)..where((o) => o.status.equals('pending'))..orderBy([(o) => OrderingTerm.asc(o.seq)])).get();

  Stream<List<OutboxEntry>> watchOutbox() => (select(outbox)..orderBy([(o) => OrderingTerm.asc(o.seq)])).watch();

  /// Records with changes still waiting to be pushed keep their local state on pull.
  Future<Set<String>> pendingTargets() async => {
        for (final o in await (select(outbox)..where((o) => o.status.equals('pending') & o.target.isNotNull())).get()) o.target!,
      };

  // Settings

  Future<String?> setting(String key) async => (await (select(syncState)..where((s) => s.key.equals(key))).getSingleOrNull())?.value;

  Future<void> setSetting(String key, String? value) => value == null
      ? (delete(syncState)..where((s) => s.key.equals(key))).go()
      : into(syncState).insertOnConflictUpdate(SyncStateCompanion.insert(key: key, value: value));

  /// Everything, e.g. when the device was signed out remotely (docs/08 §5).
  Future<void> wipe() => transaction(() async {
        await delete(mirrorRecords).go();
        await delete(outbox).go();
        await delete(mediaQueue).go();
        await delete(syncState).go();
      });

  /// Everything but the settings, e.g. when switching farm or signing out.
  Future<void> clearFarmData() => transaction(() async {
        await delete(mirrorRecords).go();
        await delete(outbox).go();
        await delete(mediaQueue).go();
        await (delete(syncState)..where((s) => s.key.isNotIn(['device_id']))).go();
      });
}
