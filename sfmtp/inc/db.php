<?php
/*
 * Database access: one PDO connection, prepared statements only.
 * MySQL has no row-level security, so every query on farm data must filter
 * by farm_id = farm_id() (see farm.php).
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', config('db.host'), (int) config('db.port', 3306), config('db.name'));
        $pdo = new PDO($dsn, config('db.user'), config('db.password'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

/** Run a statement with bound parameters. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** All rows. */
function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** First row or null. */
function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

/** First column of the first row, or null. */
function val(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Insert an associative array; returns nothing (ids are UUIDs made by the caller). */
function insert(string $table, array $data): void
{
    $cols = array_keys($data);
    $sql = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $table, implode(',', array_map(fn ($c) => "`$c`", $cols)), implode(',', array_fill(0, count($cols), '?')));
    q($sql, array_values($data));
}

/** Update rows of a farm table; always scoped by farm_id and id. */
function update_farm_row(string $table, string $id, array $data): void
{
    $sets = implode(',', array_map(fn ($c) => "`$c` = ?", array_keys($data)));
    q("UPDATE `$table` SET $sets WHERE id = ? AND farm_id = ?", [...array_values($data), $id, farm_id()]);
}

/** Run $fn in a transaction. */
function tx(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Time-ordered UUID (version 7), the same kind of id the data already uses. */
function uuid(): string
{
    $ms = (int) floor(microtime(true) * 1000);
    $bytes = random_bytes(16);
    $time = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);
    $bytes[0] = chr(hexdec(substr($time, 0, 2)));
    $bytes[1] = chr(hexdec(substr($time, 2, 2)));
    $bytes[2] = chr(hexdec(substr($time, 4, 2)));
    $bytes[3] = chr(hexdec(substr($time, 6, 2)));
    $bytes[4] = chr(hexdec(substr($time, 8, 2)));
    $bytes[5] = chr(hexdec(substr($time, 10, 2)));
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/**
 * Next farm-unique code such as TSK-008 or EXP-004 (the format the data
 * already uses). $width is the number of digits.
 */
function next_code(string $table, string $prefix, int $width = 3, string $column = 'code'): string
{
    $n = (int) val("SELECT COUNT(*) FROM `$table` WHERE farm_id = ? AND `$column` LIKE ?", [farm_id(), $prefix . '-%']) + 1;
    do {
        $code = $prefix . '-' . str_pad((string) $n++, $width, '0', STR_PAD_LEFT);
    } while (val("SELECT 1 FROM `$table` WHERE farm_id = ? AND `$column` = ?", [farm_id(), $code]));
    return $code;
}
