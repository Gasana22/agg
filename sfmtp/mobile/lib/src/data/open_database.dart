import 'dart:io';
import 'dart:math';

import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:sqlite3/sqlite3.dart';

import 'database.dart';

/// Where the database key lives: the Android Keystore / iOS Keychain.
abstract class KeyStore {
  Future<String?> read();
  Future<void> write(String key);
}

class SecureKeyStore implements KeyStore {
  // Readable by the background sync once the phone has been unlocked after
  // a restart, and never copied to another device by a backup.
  const SecureKeyStore([this._s = const FlutterSecureStorage(iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock_this_device))]);
  final FlutterSecureStorage _s;

  @override
  Future<String?> read() => _s.read(key: 'db_key');

  @override
  Future<void> write(String key) => _s.write(key: 'db_key', value: key);
}

/// The key, made once per install: 32 random bytes as hex.
Future<String> databaseKey(KeyStore store) async {
  final existing = await store.read();
  if (existing != null && existing.length == 64) return existing;
  final rnd = Random.secure();
  final key = List.generate(32, (_) => rnd.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
  await store.write(key);
  return key;
}

/// Opens the encrypted database file (SQLite3MultipleCiphers, docs/08 §5).
/// The same function serves the app and the background sync isolate.
QueryExecutor encryptedExecutor(File file, String hexKey) => NativeDatabase.createInBackground(
      file,
      setup: (db) {
        db.execute("PRAGMA hexkey = '$hexKey'");
        // Fails here, not later, if the key is wrong or the library cannot encrypt.
        final cipher = db.select('PRAGMA cipher');
        if (cipher.isEmpty) throw StateError('This build cannot encrypt its database.');
        db.select('SELECT count(*) FROM sqlite_master');
      },
    );

/// Encrypts a v0 database (plain SQLite from Phase 6) in place, so work
/// recorded before the upgrade is kept.
void encryptPlainDatabase(File plain, File target, String hexKey) {
  final db = sqlite3.open(plain.path);
  try {
    db.execute('PRAGMA journal_mode = DELETE'); // rekey needs a rollback journal
    db.execute("PRAGMA hexrekey = '$hexKey'");
  } finally {
    db.close();
  }
  plain.renameSync(target.path);
  for (final suffix in ['-wal', '-shm', '-journal']) {
    final f = File('${plain.path}$suffix');
    if (f.existsSync()) f.deleteSync();
  }
}

/// The database cannot be opened from the background (no key readable yet,
/// e.g. the phone has not been unlocked since it restarted). The app itself
/// opens it later.
class DatabaseUnavailable implements Exception {
  const DatabaseUnavailable();
}

/// Whether [file] opens with [hexKey]. False only when SQLite reads it as
/// "not a database", i.e. the key is not the one it was written with.
bool opensWithKey(File file, String hexKey) {
  final db = sqlite3.open(file.path);
  try {
    db.execute("PRAGMA hexkey = '$hexKey'");
    db.select('SELECT count(*) FROM sqlite_master');
    return true;
  } on SqliteException catch (e) {
    if (e.resultCode == 26) return false; // SQLITE_NOTADB
    rethrow;
  } finally {
    db.close();
  }
}

/// Opens the app's database. Only the app makes a key; the background sync
/// never does, so a key it cannot read yet is not replaced. When the key
/// is gone (e.g. the Keychain was not restored to a new phone), the app
/// starts empty and the member signs in again.
Future<AppDatabase> openAppDatabase({KeyStore keys = const SecureKeyStore(), bool background = false, Directory? directory}) async {
  final dir = directory ?? await getApplicationDocumentsDirectory();
  final file = File(p.join(dir.path, 'sfmtp_secure.sqlite'));
  final legacy = File(p.join(dir.path, 'sfmtp.sqlite'));
  var key = await keys.read();
  if (key == null || key.length != 64) {
    if (background) throw const DatabaseUnavailable();
    key = await databaseKey(keys);
  }
  if (!file.existsSync() && legacy.existsSync()) encryptPlainDatabase(legacy, file, key);
  if (file.existsSync() && !opensWithKey(file, key)) {
    if (background) throw const DatabaseUnavailable();
    for (final f in [file, File('${file.path}-wal'), File('${file.path}-shm'), File('${file.path}-journal')]) {
      if (f.existsSync()) f.deleteSync();
    }
  }
  return AppDatabase(encryptedExecutor(file, key));
}
