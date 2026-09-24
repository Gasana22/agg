<?php

namespace App\Modules\Traceability\Application;

use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\BatchStatus;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Things in the farm's traceability that need someone's attention
 * (docs/05 "traceability alerts"). Worked out on each read.
 */
class TraceAlerts
{
    public const LIMIT = 20;

    public const UNDELIVERED_AFTER_DAYS = 7;

    public const UNVERIFIED_AFTER_HOURS = 48;

    /**
     * @return array<int, array{code:string, severity:'critical'|'warning'|'info', title:string, count:int, items:array<int,array>}>
     */
    public function all(): array
    {
        return array_values(array_filter([
            $this->chain(),
            $this->recalledShipments(),
            $this->undelivered(),
            $this->missingOrigin(),
            $this->unknownSeed(),
        ]));
    }

    /** @return array{critical:int, warning:int, info:int} */
    public function counts(): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($this->all() as $alert) {
            $counts[$alert['severity']] += $alert['count'];
        }

        return $counts;
    }

    private function chain(): ?array
    {
        $latest = DB::table('trace_audits')->where('check_type', 'hash_chain')->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($latest?->result === 'fail') {
            $d = json_decode($latest->details, true) ?? [];

            return $this->alert('chain_failed', 'critical', 'The traceability history failed its integrity check',
                [['checked_at' => $this->iso($latest->created_at), 'reason' => $d['reason'] ?? null, 'first_bad_seq' => $d['first_bad_seq'] ?? null]]);
        }
        $hasEvents = TraceEvent::query()->exists();
        if ($hasEvents && ($latest === null || now()->diffInHours($latest->created_at, true) > self::UNVERIFIED_AFTER_HOURS)) {
            return $this->alert('chain_unverified', 'warning', 'The history has not been verified in the last two days',
                [['checked_at' => $latest ? $this->iso($latest->created_at) : null]]);
        }

        return null;
    }

    private function recalledShipments(): ?array
    {
        $q = TraceBatch::where('kind', BatchKind::Shipment->value)->where('status', BatchStatus::Recalled->value);

        return $this->fromBatches('recalled_shipped', 'critical', 'Recalled product has reached customers', $q, function (TraceBatch $b) {
            $p = TraceEvent::where('batch_id', $b->id)->where('event_type', 'dispatched')->value('payload');
            $p = is_string($p) ? json_decode($p, true) : ($p ?? []);

            return ['customer' => $p['customer'] ?? null, 'shipment' => $p['shipment'] ?? null];
        });
    }

    private function undelivered(): ?array
    {
        $before = now()->subDays(self::UNDELIVERED_AFTER_DAYS);
        $q = TraceBatch::where('kind', BatchKind::Shipment->value)->where('status', BatchStatus::Open->value)
            ->whereHas('events', fn ($e) => $e->where('event_type', 'dispatched')->where('occurred_at', '<', $before))
            ->whereDoesntHave('events', fn ($e) => $e->whereIn('event_type', ['delivered', 'delivery_failed']));

        return $this->fromBatches('shipment_undelivered', 'warning', 'Shipments dispatched over a week ago are not confirmed delivered', $q);
    }

    private function missingOrigin(): ?array
    {
        $kinds = [BatchKind::Harvest->value, BatchKind::Processed->value, BatchKind::Packaged->value, BatchKind::AnimalProduct->value, BatchKind::Shipment->value];
        $q = TraceBatch::whereIn('kind', $kinds)->where('status', '<>', BatchStatus::Recalled->value)
            ->whereNotExists(fn ($l) => $l->from('trace_batch_links')->whereColumn('trace_batch_links.child_batch_id', 'trace_batches.id'));

        return $this->fromBatches('missing_origin', 'warning', 'Products with no recorded source', $q);
    }

    private function unknownSeed(): ?array
    {
        $q = TraceBatch::where('kind', BatchKind::CropLot->value)->where('status', BatchStatus::Open->value)
            ->whereNotExists(fn ($l) => $l->from('trace_batch_links')->whereColumn('trace_batch_links.child_batch_id', 'trace_batches.id'));

        return $this->fromBatches('unknown_seed_source', 'info', 'Crops planted without a seed lot or nursery batch', $q);
    }

    private function fromBatches(string $code, string $severity, string $title, $query, ?callable $extra = null): ?array
    {
        $count = (clone $query)->count();
        if ($count === 0) {
            return null;
        }
        $items = $query->orderByDesc('created_at')->limit(self::LIMIT)->get()->map(fn (TraceBatch $b) => [
            'batch' => ['id' => $b->id, 'batch_code' => $b->batch_code, 'kind' => $b->kind->value, 'name' => $b->name, 'status' => $b->status->value],
        ] + ($extra ? $extra($b) : []))->all();

        return $this->alert($code, $severity, $title, $items, $count);
    }

    private function alert(string $code, string $severity, string $title, array $items, ?int $count = null): array
    {
        return ['code' => $code, 'severity' => $severity, 'title' => $title, 'count' => $count ?? count($items), 'items' => $items];
    }

    private function iso(mixed $at): string
    {
        return CarbonImmutable::parse($at)->toIso8601ZuluString();
    }
}
