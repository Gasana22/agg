<?php

namespace App\Modules\Traceability\Application;

use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use Illuminate\Support\Facades\DB;

/**
 * Backward ("where did this come from?") and forward ("where did it go?")
 * walks of the batch graph with one recursive CTE each (docs/07 §1).
 * Works on PostgreSQL and MySQL 8.
 */
class JourneyService
{
    public const MAX_DEPTH = 50;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{nodes: array<int,array>, edges: array<int,array>}
     */
    public function walk(TraceBatch $batch, string $direction, int $depth = self::MAX_DEPTH): array
    {
        $edges = $this->edges($batch->id, $direction, min($depth, self::MAX_DEPTH));

        $ids = [];
        foreach ($edges as $edge) {
            $ids[$edge->parent_batch_id] = true;
            $ids[$edge->child_batch_id] = true;
        }
        unset($ids[$batch->id]);

        $nodes = TraceBatch::whereIn('id', array_keys($ids))->get()->keyBy('id');

        return [
            'nodes' => array_values($nodes->map(fn (TraceBatch $b) => [
                'id' => $b->id,
                'batch_code' => $b->batch_code,
                'kind' => $b->kind->value,
                'name' => $b->name,
                'status' => $b->status->value,
                'quantity' => $b->quantity === null ? null : ['value' => $b->quantity, 'unit' => $b->unit],
                'depth' => (int) collect($edges)->filter(fn ($e) => $e->{$direction === 'backward' ? 'parent_batch_id' : 'child_batch_id'} === $b->id)->min('depth'),
            ])->all()),
            'edges' => array_map(fn ($e) => [
                'id' => $e->id,
                'from' => $e->parent_batch_id,
                'to' => $e->child_batch_id,
                'link_type' => $e->link_type,
                'quantity' => $e->quantity,
                'unit' => $e->unit,
            ], $edges),
        ];
    }

    /** @return array<int,string> */
    public function reachableIds(string $batchId, string $direction): array
    {
        $key = $direction === 'backward' ? 'parent_batch_id' : 'child_batch_id';

        return array_values(array_unique(array_map(fn ($e) => $e->{$key}, $this->edges($batchId, $direction, self::MAX_DEPTH))));
    }

    /** @return array<int,object> */
    private function edges(string $batchId, string $direction, int $depth): array
    {
        [$anchor, $next] = $direction === 'backward'
            ? ['child_batch_id', 'l.child_batch_id = w.parent_batch_id']
            : ['parent_batch_id', 'l.parent_batch_id = w.child_batch_id'];

        $farmId = $this->context->farmId();

        $sql = <<<SQL
            WITH RECURSIVE walk (id, parent_batch_id, child_batch_id, link_type, quantity, unit, depth) AS (
                SELECT l.id, l.parent_batch_id, l.child_batch_id, l.link_type, l.quantity, l.unit, 1
                FROM trace_batch_links l
                WHERE l.farm_id = ? AND l.{$anchor} = ?
                UNION ALL
                SELECT l.id, l.parent_batch_id, l.child_batch_id, l.link_type, l.quantity, l.unit, w.depth + 1
                FROM trace_batch_links l
                JOIN walk w ON {$next}
                WHERE l.farm_id = ? AND w.depth < ?
            )
            SELECT id, parent_batch_id, child_batch_id, link_type, quantity, unit, MIN(depth) AS depth
            FROM walk
            GROUP BY id, parent_batch_id, child_batch_id, link_type, quantity, unit
            ORDER BY MIN(depth), id
        SQL;

        return DB::select($sql, [$farmId, $batchId, $farmId, $depth]);
    }
}
