<?php

namespace App\Modules\Traceability\Application;

use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\BatchStatus;
use App\Modules\Traceability\Domain\Enums\LinkType;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceBatchLink;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Split, merge, process and package batches (docs/07 §1), and recall.
 *
 * Quantity accounting: what a batch has left is its quantity minus what
 * its consuming links (split, merge, process, package, ship) took. The
 * input batches are row-locked while that is checked, so two people cannot
 * use the same kilograms twice. A batch that is used up is closed.
 */
class BatchOperations
{
    /** Links that take quantity out of their parent. `derived` does not. */
    public const CONSUMING = ['split', 'merge', 'process', 'package', 'ship'];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly JourneyService $journeys,
    ) {}

    /** What is left of a batch, as a decimal string; null when it has no quantity. */
    public function available(TraceBatch $batch): ?string
    {
        $left = $this->availableMilli($batch);

        return $left === null ? null : self::decimal($left);
    }

    public function availableMilli(TraceBatch $batch): ?int
    {
        if ($batch->quantity === null) {
            return null;
        }

        return self::milli($batch->quantity) - $this->usedMilli($batch->id);
    }

    /**
     * Take part of a batch into new batches of the same kind.
     *
     * @param  array<int, array{quantity: string|float, name?: ?string}>  $parts
     * @return array<int, TraceBatch> the new batches
     */
    public function split(TraceBatch $batch, array $parts, array $event = [], ?string $notes = null): array
    {
        return DB::transaction(function () use ($batch, $parts, $event, $notes) {
            $total = array_sum(array_map(fn ($p) => self::milli($p['quantity']), $parts));
            [[$batch]] = $this->take([['batch' => $batch, 'quantity' => self::decimal($total)]], 'split');

            $children = [];
            foreach (array_values($parts) as $i => $part) {
                $quantity = self::decimal(self::milli($part['quantity']));
                $child = $this->recorder->createBatch($batch->kind, [
                    'name' => mb_substr($part['name'] ?? trim(($batch->name ?? $batch->batch_code).' · part '.($i + 1)), 0, 150),
                    'quantity' => $quantity,
                    'unit' => $batch->unit,
                    'product_id' => $batch->product_id,
                    'origin_plot_id' => $batch->origin_plot_id,
                ], $event + ['payload' => array_filter([
                    'kind' => $batch->kind->value,
                    'operation' => 'split',
                    'from' => $batch->batch_code,
                    'quantity' => $quantity,
                    'unit' => $batch->unit,
                    'notes' => $notes,
                ])]);
                $this->recorder->link($batch, $child, LinkType::Split, $quantity, $batch->unit, $event);
                $children[] = $child;
            }
            $this->closeIfUsedUp([$batch], 'Fully split', $event);

            return $children;
        });
    }

    /**
     * Combine batches of one kind and unit into a new batch.
     *
     * @param  array<int, array{batch: TraceBatch, quantity?: string|float|null}>  $inputs
     */
    public function merge(array $inputs, ?string $name, array $event = [], ?string $notes = null): TraceBatch
    {
        $kinds = array_unique(array_map(fn ($i) => $i['batch']->kind->value, $inputs));
        if (count($kinds) > 1) {
            throw ApiException::unprocessable('trace_merge_mixed', 'Only batches of the same kind can be merged. Process them into a new product instead.');
        }
        $units = array_unique(array_map(fn ($i) => $i['batch']->unit, $inputs));
        if (count($units) > 1) {
            throw ApiException::unprocessable('trace_unit_mismatch', 'Batches measured in different units cannot be merged.');
        }

        return DB::transaction(function () use ($inputs, $name, $event, $notes) {
            $taken = $this->take($inputs, 'merge');
            $first = $taken[0][0];
            $plots = array_unique(array_map(fn ($t) => $t[0]->origin_plot_id, $taken));
            $total = self::decimal(array_sum(array_column($taken, 1)));

            return $this->produce($first->kind, LinkType::Merge, $taken, [
                'name' => $name ?? 'Merged '.str_replace('_', ' ', $first->kind->value),
                'quantity' => $total,
                'unit' => $first->unit,
                'origin_plot_id' => count($plots) === 1 ? $plots[0] : null,
                'product_id' => $first->product_id,
            ], $event, ['operation' => 'merge', 'notes' => $notes]);
        });
    }

    /**
     * Turn batches into a processed product (dried, graded, milled …). The
     * output quantity may differ from the inputs (drying loses weight).
     *
     * @param  array<int, array{batch: TraceBatch, quantity?: string|float|null}>  $inputs
     * @param  array{name: string, quantity?: string|float|null, unit?: ?string, method?: ?string}  $output
     */
    public function process(array $inputs, array $output, array $event = [], ?string $notes = null): TraceBatch
    {
        return $this->transform(BatchKind::Processed, LinkType::Process, 'process', $inputs, $output, $event, $notes);
    }

    /**
     * Pack batches into a packaged product.
     *
     * @param  array<int, array{batch: TraceBatch, quantity?: string|float|null}>  $inputs
     * @param  array{name: string, quantity?: string|float|null, unit?: ?string, package_count?: ?int, package_size?: ?string}  $output
     */
    public function package(array $inputs, array $output, array $event = [], ?string $notes = null): TraceBatch
    {
        return $this->transform(BatchKind::Packaged, LinkType::Package, 'package', $inputs, $output, $event, $notes);
    }

    /**
     * Take quantity from batches into a shipment batch that already exists
     * (the Sales module creates it).
     *
     * @param  array<int, array{batch: TraceBatch, quantity?: string|float|null}>  $inputs
     */
    public function ship(array $inputs, TraceBatch $shipment, array $event = []): void
    {
        DB::transaction(function () use ($inputs, $shipment, $event) {
            $taken = $this->take($inputs, 'ship');
            foreach ($taken as [$batch, $milli]) {
                $this->recorder->link($batch, $shipment, LinkType::Ship, $milli === null ? null : self::decimal($milli), $batch->unit, $event);
            }
            $this->closeIfUsedUp(array_column($taken, 0), 'Fully shipped', $event);
        });
    }

    /**
     * Check a manual link that takes quantity (docs/06 `POST …/links`).
     */
    public function assertCanTake(TraceBatch $parent, LinkType $type, ?string $quantity, ?string $unit): void
    {
        if (! in_array($type->value, self::CONSUMING, true) || $quantity === null) {
            return;
        }
        if ($parent->unit !== null && $unit !== $parent->unit) {
            throw ApiException::unprocessable('trace_unit_mismatch', "{$parent->batch_code} is measured in {$parent->unit}.");
        }
        $this->take([['batch' => $parent, 'quantity' => $quantity]], $type->value);
    }

    /**
     * Recall a batch and everything made from it. Returns every batch whose
     * status changed, shipments included, so the farm knows whom to call.
     *
     * @return array<int, TraceBatch>
     */
    public function recall(TraceBatch $batch, string $reason): array
    {
        if ($batch->status === BatchStatus::Recalled) {
            throw ApiException::conflict('invalid_state_transition', 'The batch is already recalled.');
        }

        return DB::transaction(function () use ($batch, $reason) {
            $ids = array_merge([$batch->id], $this->journeys->reachableIds($batch->id, 'forward'));
            $batches = TraceBatch::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $changed = [];
            foreach ($ids as $id) {
                $b = $batches[$id];
                if ($b->status === BatchStatus::Recalled) {
                    continue;
                }
                $from = $b->status;
                $b->status = BatchStatus::Recalled;
                $b->save();
                $this->recorder->record($b, 'status_changed', ['payload' => array_filter([
                    'from' => $from->value,
                    'to' => BatchStatus::Recalled->value,
                    'reason' => $reason,
                    'recall_of' => $b->id === $batch->id ? null : $batch->batch_code,
                ])]);
                $changed[] = $b;
            }
            // Public pages of recalled batches show the recall; their codes stop confirming the product.
            app(Publishing::class)->revokeForBatches(array_map(fn (TraceBatch $b) => $b->id, $changed), 'Recalled: '.$reason);

            return $changed;
        });
    }

    /**
     * @param  array<int, array{batch: TraceBatch, quantity?: string|float|null}>  $inputs
     * @param  array<string, mixed>  $output
     */
    private function transform(BatchKind $kind, LinkType $type, string $operation, array $inputs, array $output, array $event, ?string $notes): TraceBatch
    {
        return DB::transaction(function () use ($kind, $type, $operation, $inputs, $output, $event, $notes) {
            $taken = $this->take($inputs, $operation);
            $plots = array_unique(array_map(fn ($t) => $t[0]->origin_plot_id, $taken));
            $units = array_unique(array_map(fn ($t) => $t[0]->unit, $taken));
            $quantity = $output['quantity'] ?? null;
            $unit = $output['unit'] ?? null;
            if ($quantity === null && count($units) === 1 && $units[0] !== null) {
                // Same unit in and out, nothing lost: the output weighs what went in.
                $quantity = self::decimal(array_sum(array_column($taken, 1)));
                $unit ??= $units[0];
            }

            return $this->produce($kind, $type, $taken, [
                'name' => $output['name'],
                'quantity' => $quantity === null ? null : self::decimal(self::milli($quantity)),
                'unit' => $quantity === null ? null : $unit,
                'origin_plot_id' => count($plots) === 1 ? $plots[0] : null,
            ], $event, [
                'operation' => $operation,
                'method' => $output['method'] ?? null,
                'package_count' => $output['package_count'] ?? null,
                'package_size' => $output['package_size'] ?? null,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * @param  array<int, array{0: TraceBatch, 1: ?int}>  $taken
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $details
     */
    private function produce(BatchKind $kind, LinkType $type, array $taken, array $attributes, array $event, array $details): TraceBatch
    {
        $output = $this->recorder->createBatch($kind, $attributes, $event + ['payload' => array_filter([
            'kind' => $kind->value,
            'name' => $attributes['name'] ?? null,
            'quantity' => $attributes['quantity'] ?? null,
            'unit' => $attributes['unit'] ?? null,
            'from' => implode(', ', array_map(fn ($t) => $t[0]->batch_code, $taken)),
        ] + $details, fn ($v) => $v !== null && $v !== '')]);

        foreach ($taken as [$batch, $milli]) {
            $this->recorder->link($batch, $output, $type, $milli === null ? null : self::decimal($milli), $milli === null ? null : $batch->unit, $event);
        }
        $this->closeIfUsedUp(array_column($taken, 0), match ($type) {
            LinkType::Merge => 'Fully merged',
            LinkType::Process => 'Fully processed',
            LinkType::Package => 'Fully packed',
            default => 'Fully used',
        }, $event);

        return $output;
    }

    /**
     * Lock the input batches, check they are open and hold enough, and work
     * out how much each gives (all that is left when no quantity is given).
     *
     * @param  array<int, array{batch: TraceBatch, quantity?: string|float|null}>  $inputs
     * @return array<int, array{0: TraceBatch, 1: ?int}> [locked batch, thousandths taken]
     */
    private function take(array $inputs, string $operation): array
    {
        $ids = array_map(fn ($i) => $i['batch']->id, $inputs);
        if (count($ids) !== count(array_unique($ids))) {
            throw ApiException::unprocessable('trace_duplicate_input', 'A batch is listed more than once.');
        }
        $locked = TraceBatch::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $taken = [];
        foreach ($inputs as $input) {
            $batch = $locked[$input['batch']->id] ?? throw ApiException::notFound();
            if ($batch->status !== BatchStatus::Open) {
                throw ApiException::conflict('trace_batch_not_open', "{$batch->batch_code} is {$batch->status->value} and cannot be used.");
            }
            if ($batch->kind === BatchKind::Shipment) {
                throw ApiException::unprocessable('trace_shipment_input', 'A shipment has left the farm and cannot be used again.');
            }
            $left = $this->availableMilli($batch);
            $want = isset($input['quantity']) && $input['quantity'] !== null ? self::milli($input['quantity']) : null;

            if ($left === null) {
                if ($operation === 'split') {
                    throw ApiException::unprocessable('trace_no_quantity', "{$batch->batch_code} has no quantity recorded, so it cannot be split.");
                }
                if ($want !== null) {
                    throw ApiException::unprocessable('trace_no_quantity', "{$batch->batch_code} has no quantity recorded. Leave the quantity empty.");
                }
                $taken[] = [$batch, null];

                continue;
            }
            $want ??= $left;
            if ($want <= 0) {
                throw ApiException::unprocessable('trace_quantity_exhausted', "Nothing is left of {$batch->batch_code}.");
            }
            if ($want > $left) {
                throw new ApiException(422, 'trace_quantity_exceeded',
                    "Only {$this->decimalTrim($left)} {$batch->unit} of {$batch->batch_code} is left.", ['available' => self::decimal($left), 'unit' => $batch->unit]);
            }
            $taken[] = [$batch, $want];
        }

        return $taken;
    }

    /** @param  array<int, TraceBatch>  $batches */
    private function closeIfUsedUp(array $batches, string $reason, array $event = []): void
    {
        foreach ($batches as $batch) {
            if ($batch->status === BatchStatus::Open && $this->availableMilli($batch) === 0) {
                $this->recorder->changeStatus($batch, BatchStatus::Closed, $reason, array_intersect_key($event, ['occurred_at' => true]));
            }
        }
    }

    private function usedMilli(string $batchId): int
    {
        return (int) TraceBatchLink::where('parent_batch_id', $batchId)
            ->whereIn('link_type', self::CONSUMING)
            ->get(['quantity'])
            ->sum(fn ($l) => self::milli($l->quantity));
    }

    public static function milli(string|int|float|null $q): int
    {
        return (int) round(((float) ($q ?? 0)) * 1000);
    }

    public static function decimal(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $milli = abs($milli);

        return $sign.intdiv($milli, 1000).'.'.str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function decimalTrim(int $milli): string
    {
        return rtrim(rtrim(self::decimal($milli), '0'), '.');
    }
}
