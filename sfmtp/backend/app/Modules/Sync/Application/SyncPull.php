<?php

namespace App\Modules\Sync\Application;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /sync/pull (docs/08 §3).
 *
 * With no cursor the phone gets a snapshot of everything it mirrors and the
 * feed head as its cursor. With a cursor it gets what changed since: each
 * changed record once, in its current state, or `remove` when the member no
 * longer sees it (a task reassigned to someone else, or outside the window).
 * Feed rows younger than the lag are left for the next pull, so a
 * transaction that commits late cannot slip behind the cursor.
 */
class SyncPull
{
    public function __construct(
        private readonly SyncEntities $entities,
        private readonly ChangeFeed $feed,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<int,string>  $entities
     * @return array{changes: array<int,array<string,mixed>>, next_cursor: string, has_more: bool}
     */
    public function pull(?int $cursor, array $entities, int $limit, Request $request): array
    {
        $farmId = $this->context->farmId();

        if (! $cursor) {
            $head = $this->feed->head($farmId);
            $changes = [];
            foreach ($entities as $entity) {
                foreach ($this->entities->query($entity)?->limit(2000)->get() ?? [] as $model) {
                    $changes[] = ['entity' => $entity, 'op' => 'upsert'] + $this->entities->present($entity, $model, $request);
                }
            }

            return ['changes' => $changes, 'next_cursor' => (string) $head, 'has_more' => false];
        }

        $rows = DB::table('sync_changes')->where('farm_id', $farmId)->where('id', '>', $cursor)
            ->whereIn('entity', $entities)
            ->where('changed_at', '<=', now()->subSeconds(config('sfmtp.sync.pull_lag_seconds'))->format('Y-m-d H:i:s.u'))
            ->orderBy('id')->limit($limit + 1)->get(['id', 'entity', 'record_id']);
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        if ($rows->isEmpty()) {
            return ['changes' => [], 'next_cursor' => (string) $cursor, 'has_more' => false];
        }

        $changes = [];
        foreach ($rows->groupBy('entity') as $entity => $group) {
            $ids = $group->pluck('record_id')->unique()->values()->all();
            $visible = $this->entities->query($entity, $ids)?->get()->keyBy(fn ($m) => $m->getKey()) ?? collect();
            foreach ($ids as $id) {
                $model = $visible->get($id);
                $changes[] = $model
                    ? ['entity' => $entity, 'op' => 'upsert'] + $this->entities->present($entity, $model, $request)
                    : ['entity' => $entity, 'op' => 'remove', 'id' => $id, 'version' => null, 'data' => null];
            }
        }

        return ['changes' => $changes, 'next_cursor' => (string) $rows->last()->id, 'has_more' => $hasMore];
    }
}
