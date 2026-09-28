<?php
/*
 * Traceability: batches, links between them, and hash-chained events.
 *
 * Every event is chained per farm: hash = SHA-256(prev_hash || canonical JSON),
 * computed exactly as the earlier version did, so the chain of the existing
 * data and of new events verifies as one (see trace_verify()).
 */

const TRACE_GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
const CODE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

const BATCH_KINDS = ['seed_lot', 'input_lot', 'nursery', 'crop_lot', 'harvest', 'animal', 'animal_product', 'processed', 'packaged', 'shipment'];

function random_code(int $length): string
{
    $s = '';
    for ($i = 0; $i < $length; $i++) {
        $s .= CODE_ALPHABET[random_int(0, 31)];
    }
    return $s;
}

/** Create a batch and its "created" event. Returns the batch row. */
function trace_create_batch(string $kind, array $attrs = [], array $event = []): array
{
    return tx(function () use ($kind, $attrs, $event) {
        do {
            $raw = random_code(8);
            $code = 'SFM-' . substr($raw, 0, 4) . '-' . substr($raw, 4);
        } while (val('SELECT 1 FROM trace_batches WHERE batch_code = ?', [$code]));

        $batch = [
            'id' => uuid(), 'farm_id' => farm_id(), 'batch_code' => $code, 'kind' => $kind,
            'name' => $attrs['name'] ?? null, 'quantity' => $attrs['quantity'] ?? null, 'unit' => $attrs['unit'] ?? null,
            'status' => 'open', 'product_id' => null, 'origin_plot_id' => $attrs['origin_plot_id'] ?? null,
            'source_type' => $attrs['source_type'] ?? null, 'source_id' => $attrs['source_id'] ?? null,
            'created_by' => $_SESSION['uid'] ?? null, 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc(),
        ];
        insert('trace_batches', $batch);
        trace_record($batch['id'], 'created', $event + ['payload' => array_filter([
            'kind' => $kind, 'name' => $batch['name'],
            'quantity' => $batch['quantity'] === null ? null : number_format((float) $batch['quantity'], 3, '.', ''),
            'unit' => $batch['unit'],
        ], fn ($v) => $v !== null)]);
        return $batch;
    });
}

/** Link parent → child (both directions get an event). */
function trace_link(array $parent, array $child, string $type, ?float $quantity = null, ?string $unit = null, array $event = []): void
{
    if ($parent['id'] === $child['id']) {
        fail('A batch cannot be linked to itself.');
    }
    tx(function () use ($parent, $child, $type, $quantity, $unit, $event) {
        if (in_array($parent['id'], trace_related($child['id'], 'down'), true)) {
            fail('This link would make a batch its own ancestor.');
        }
        $id = uuid();
        $q = $quantity === null ? null : number_format($quantity, 3, '.', '');
        insert('trace_batch_links', ['id' => $id, 'farm_id' => farm_id(), 'parent_batch_id' => $parent['id'], 'child_batch_id' => $child['id'],
            'link_type' => $type, 'quantity' => $q, 'unit' => $unit, 'created_by' => $_SESSION['uid'] ?? null, 'created_at' => gmdate('Y-m-d H:i:s.u')]);
        $details = array_filter(['link_id' => $id, 'link_type' => $type, 'quantity' => $q, 'unit' => $unit]);
        trace_record($child['id'], 'linked_from', $event + ['payload' => ['parent_batch_id' => $parent['id'], 'parent_batch_code' => $parent['batch_code']] + $details]);
        trace_record($parent['id'], 'linked_to', $event + ['payload' => ['child_batch_id' => $child['id'], 'child_batch_code' => $child['batch_code']] + $details]);
    });
}

/** Append an event to a batch's history. */
function trace_record(string $batchId, string $type, array $data = []): array
{
    return tx(function () use ($batchId, $type, $data) {
        $farmId = farm_id();
        if (!val('SELECT 1 FROM trace_batches WHERE id = ? AND farm_id = ?', [$batchId, $farmId])) {
            fail('Unknown batch.');
        }
        q('INSERT IGNORE INTO trace_sequences (farm_id, last_seq, last_hash, updated_at) VALUES (?, 0, ?, ?)', [$farmId, TRACE_GENESIS, now_utc()]);
        $head = row('SELECT * FROM trace_sequences WHERE farm_id = ? FOR UPDATE', [$farmId]);

        $occurred = isset($data['occurred_at']) ? gmdate('Y-m-d H:i:s', strtotime($data['occurred_at'] . (strlen($data['occurred_at']) === 10 ? ' 12:00:00' : '') . ' UTC')) . '.000000' : gmdate('Y-m-d H:i:s') . '.' . sprintf('%06d', (int) (fmod(microtime(true), 1) * 1e6));
        $payload = json_decode(json_encode($data['payload'] ?? [], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true);
        $round = fn ($v, $p) => $v === null ? null : number_format((float) $v, $p, '.', '');

        $event = [
            'id' => uuid(), 'farm_id' => $farmId, 'batch_id' => $batchId, 'event_type' => $type,
            'occurred_at' => $occurred, 'recorded_at' => gmdate('Y-m-d H:i:s.u'),
            'actor_user_id' => $_SESSION['uid'] ?? null, 'worker_id' => $data['worker_id'] ?? null, 'plot_id' => $data['plot_id'] ?? null,
            'latitude' => $round($data['latitude'] ?? null, 7), 'longitude' => $round($data['longitude'] ?? null, 7),
            'gps_accuracy_m' => $round($data['gps_accuracy_m'] ?? null, 2),
            'subject_type' => $data['subject_type'] ?? null, 'subject_id' => $data['subject_id'] ?? null,
            'payload' => $payload, 'corrects_event_id' => $data['corrects_event_id'] ?? null,
            'farm_seq' => (int) $head['last_seq'] + 1, 'prev_hash' => $head['last_hash'],
        ];
        $event['hash'] = hash('sha256', $event['prev_hash'] . trace_canonical($event));
        $stored = $event;
        $stored['payload'] = json_encode($payload ?: new stdClass(), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
        insert('trace_events', $stored);
        q('UPDATE trace_sequences SET last_seq = ?, last_hash = ?, updated_at = ? WHERE farm_id = ?', [$event['farm_seq'], $event['hash'], now_utc(), $farmId]);
        return $event;
    });
}

function trace_canonical(array $e): string
{
    $payload = $e['payload'] ?? [];
    if (is_string($payload)) {
        $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }
    $str = fn ($v) => $v === null ? null : (string) $v;
    $dec = fn ($v, $p) => $v === null ? null : number_format((float) $v, $p, '.', '');
    $t = new DateTime((string) $e['occurred_at'], new DateTimeZone('UTC'));
    $fields = [
        'id' => (string) $e['id'], 'farm_id' => (string) $e['farm_id'], 'farm_seq' => (int) $e['farm_seq'],
        'batch_id' => (string) $e['batch_id'], 'event_type' => (string) $e['event_type'],
        'occurred_at' => $t->format('Y-m-d\TH:i:s.u\Z'),
        'actor_user_id' => $str($e['actor_user_id'] ?? null), 'worker_id' => $str($e['worker_id'] ?? null), 'plot_id' => $str($e['plot_id'] ?? null),
        'latitude' => $dec($e['latitude'] ?? null, 7), 'longitude' => $dec($e['longitude'] ?? null, 7), 'gps_accuracy_m' => $dec($e['gps_accuracy_m'] ?? null, 2),
        'subject_type' => $str($e['subject_type'] ?? null), 'subject_id' => $str($e['subject_id'] ?? null),
        'corrects_event_id' => $str($e['corrects_event_id'] ?? null),
        'payload' => trace_sort_keys($payload),
    ];
    return json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
}

function trace_sort_keys(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    if (!array_is_list($v)) {
        ksort($v, SORT_STRING);
    }
    return array_map('trace_sort_keys', $v);
}

/** Re-hash the farm's whole chain. Returns [ok, events checked, first bad seq]. */
function trace_verify(): array
{
    $prev = TRACE_GENESIS;
    $n = 0;
    foreach (q('SELECT * FROM trace_events WHERE farm_id = ? ORDER BY farm_seq', [farm_id()]) as $e) {
        $n++;
        if ($e['prev_hash'] !== $prev || !hash_equals($e['hash'], hash('sha256', $prev . trace_canonical($e)))) {
            return [false, $n, (int) $e['farm_seq']];
        }
        $prev = $e['hash'];
    }
    return [true, $n, null];
}

/** Ids of every batch upstream ('up') or downstream ('down') of a batch. */
function trace_related(string $batchId, string $direction): array
{
    [$from, $to] = $direction === 'up' ? ['child_batch_id', 'parent_batch_id'] : ['parent_batch_id', 'child_batch_id'];
    $seen = [];
    $queue = [$batchId];
    while ($queue) {
        $placeholders = implode(',', array_fill(0, count($queue), '?'));
        $next = array_column(rows("SELECT DISTINCT $to AS id FROM trace_batch_links WHERE farm_id = ? AND $from IN ($placeholders)", [farm_id(), ...$queue]), 'id');
        $queue = array_values(array_diff($next, $seen, [$batchId]));
        $seen = array_merge($seen, $queue);
    }
    return $seen;
}

function batch_label(array $b): string
{
    return $b['batch_code'] . ' · ' . ($b['name'] ?: label($b['kind']));
}
