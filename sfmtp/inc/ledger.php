<?php
/*
 * Double-entry ledger. Every money document posts one balanced entry;
 * posted lines are never changed (the database refuses updates and deletes),
 * mistakes are reversed by a new entry.
 */

function account_id(string $code): string
{
    $id = val('SELECT id FROM ledger_accounts WHERE farm_id = ? AND code = ?', [farm_id(), $code]);
    if (!$id) {
        fail("Account $code is missing from this farm's chart of accounts.");
    }
    return $id;
}

/**
 * Post an entry. $lines: [['account_id'=>…, 'debit'=>…, 'credit'=>…, 'memo'=>…], …]
 * Returns the entry id.
 */
function ledger_post(string $postedOn, string $sourceType, ?string $sourceId, string $memo, array $lines): string
{
    $debit = $credit = 0.0;
    foreach ($lines as $l) {
        $debit += round((float) ($l['debit'] ?? 0), 2);
        $credit += round((float) ($l['credit'] ?? 0), 2);
        if (!belongs('ledger_accounts', $l['account_id'] ?? null)) {
            fail('Unknown account.');
        }
    }
    if (abs($debit - $credit) > 0.004 || $debit <= 0) {
        fail('The entry does not balance.');
    }
    return tx(function () use ($postedOn, $sourceType, $sourceId, $memo, $lines) {
        q('INSERT IGNORE INTO ledger_sequences (farm_id, last_number) VALUES (?, 0)', [farm_id()]);
        $n = (int) val('SELECT last_number FROM ledger_sequences WHERE farm_id = ? FOR UPDATE', [farm_id()]) + 1;
        q('UPDATE ledger_sequences SET last_number = ? WHERE farm_id = ?', [$n, farm_id()]);
        $id = uuid();
        insert('ledger_entries', ['id' => $id, 'farm_id' => farm_id(), 'number' => 'JE-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'posted_on' => $postedOn, 'source_type' => $sourceType, 'source_id' => $sourceId, 'memo' => mb_substr($memo, 0, 300),
            'posted_by' => $_SESSION['uid'] ?? null, 'created_at' => gmdate('Y-m-d H:i:s.u')]);
        foreach ($lines as $l) {
            insert('ledger_lines', ['id' => uuid(), 'farm_id' => farm_id(), 'entry_id' => $id, 'account_id' => $l['account_id'],
                'debit' => number_format((float) ($l['debit'] ?? 0), 2, '.', ''), 'credit' => number_format((float) ($l['credit'] ?? 0), 2, '.', ''),
                'cost_center_type' => $l['cost_center_type'] ?? null, 'cost_center_id' => $l['cost_center_id'] ?? null,
                'memo' => isset($l['memo']) ? mb_substr($l['memo'], 0, 200) : null]);
        }
        return $id;
    });
}

/** Post the mirror image of an entry. */
function ledger_reverse(string $entryId, string $postedOn, string $memo): string
{
    $lines = rows('SELECT account_id, debit, credit, cost_center_type, cost_center_id FROM ledger_lines WHERE entry_id = ? AND farm_id = ?', [$entryId, farm_id()]);
    return ledger_post($postedOn, 'reversal', $entryId, $memo, array_map(fn ($l) => ['account_id' => $l['account_id'], 'debit' => $l['credit'], 'credit' => $l['debit'],
        'cost_center_type' => $l['cost_center_type'], 'cost_center_id' => $l['cost_center_id']], $lines));
}

/** Debit and credit totals of each account over a period (either end optional). */
function account_balances(?string $to = null, ?string $from = null): array
{
    $params = [];
    $where = '';
    if ($from) {
        $where .= ' AND e.posted_on >= ?';
        $params[] = $from;
    }
    if ($to) {
        $where .= ' AND e.posted_on <= ?';
        $params[] = $to;
    }
    return rows("SELECT a.id, a.code, a.name, a.type, a.is_cash, COALESCE(t.debit, 0) AS debit, COALESCE(t.credit, 0) AS credit
        FROM ledger_accounts a
        LEFT JOIN (SELECT l.account_id, SUM(l.debit) AS debit, SUM(l.credit) AS credit
                   FROM ledger_lines l JOIN ledger_entries e ON e.id = l.entry_id
                   WHERE l.farm_id = ? $where GROUP BY l.account_id) t ON t.account_id = a.id
        WHERE a.farm_id = ? ORDER BY a.code", [farm_id(), ...$params, farm_id()]);
}
