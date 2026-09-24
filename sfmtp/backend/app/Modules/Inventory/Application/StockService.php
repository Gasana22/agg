<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Finance\Application\JournalLine;
use App\Modules\Finance\Application\Ledger;
use App\Modules\Finance\Application\Money;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockLot;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Tenancy\Application\FarmSettings;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Support\Database\Sequence;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every change to stock goes through here (docs/09: StockService::receive /
 * issue / transfer / adjust). Each call runs in one transaction: it locks
 * the balance rows it touches (in a fixed order, so parallel calls cannot
 * deadlock), writes stock movements, updates the balances, and posts the
 * money side to the ledger.
 *
 * Valuation: weighted average cost per item, store and lot. A lot keeps the
 * price it was received at; untracked stock averages its receipts.
 * Lots are issued earliest expiry first (FEFO), then oldest first.
 */
class StockService
{
    /** A deadlock restarts the (outermost) transaction up to this many times. */
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Recorder $recorder,
        private readonly TenantContext $context,
        private readonly FarmSettings $settings,
    ) {}

    /**
     * Stock in. A tracked item gets a new lot, which is also an `input_lot`
     * trace batch; `$counterAccount` is the ledger account credited for the
     * value (goods received not invoiced for deliveries, opening balances …).
     *
     * @param  array{lot_number?:?string, expires_on?:?string, supplier_id?:?string, supplier?:?string, order?:?string}  $lot
     */
    public function receive(InventoryItem $item, string $locationId, string|float $quantity, string|float|null $unitCost, string $type, string $sourceType, ?string $sourceId,
        string $counterAccount, array $lot = [], ?CarbonImmutable $at = null, ?string $note = null): StockMovement
    {
        $qty = Qty::milli($quantity);
        if ($qty <= 0) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['quantity' => ['The quantity must be more than zero.']]);
        }
        $at ??= CarbonImmutable::now();
        $valueCents = $unitCost === null ? 0 : (int) round($qty * (float) $unitCost / 10);   // qty/1000 × cost × 100

        return DB::transaction(function () use ($item, $locationId, $qty, $unitCost, $type, $sourceType, $sourceId, $counterAccount, $lot, $at, $note, $valueCents) {
            $stockLot = null;
            if ($item->tracks_lots) {
                if ($item->tracks_expiry && empty($lot['expires_on'])) {
                    throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['expires_on' => ["{$item->name} needs an expiry date."]]);
                }
                $stockLot = StockLot::create([
                    'item_id' => $item->id,
                    'code' => $this->lotCode(),
                    'lot_number' => $lot['lot_number'] ?? null,
                    'expires_on' => $lot['expires_on'] ?? null,
                    'received_on' => $at->toDateString(),
                    'unit_cost' => $unitCost,
                    'supplier_id' => $lot['supplier_id'] ?? null,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                ]);
                // No prices in trace history: it may become public (docs/07 §5).
                $batch = $this->recorder->createBatch(BatchKind::InputLot, [
                    'name' => trim($item->name.' '.($stockLot->lot_number ?? $stockLot->code)),
                    'quantity' => Qty::of($qty),
                    'unit' => $item->unit,
                    'source_type' => 'stock_lot',
                    'source_id' => $stockLot->id,
                ], ['occurred_at' => $at, 'payload' => array_filter([
                    'item' => $item->code,
                    'lot' => $stockLot->code,
                    'lot_number' => $stockLot->lot_number,
                    'expires_on' => $stockLot->expires_on?->toDateString(),
                    'supplier' => $lot['supplier'] ?? null,
                    'order' => $lot['order'] ?? null,
                ])]);
                $stockLot->forceFill(['trace_batch_id' => $batch->id])->save();
            }

            $balance = $this->lock($item, $locationId, $stockLot?->id);
            $entry = $this->ledger->post($sourceType, $sourceId, "Stock in: {$item->name}".($stockLot ? " ({$stockLot->code})" : ''), [
                JournalLine::debit(ChartOfAccounts::INVENTORY, Money::fromCents($valueCents)),
                JournalLine::credit($counterAccount, Money::fromCents($valueCents)),
            ], $at);

            return $this->move($balance, $type, $qty, $valueCents, $unitCost, $sourceType, $sourceId, null, $at, $note, $entry?->id);
        }, self::ATTEMPTS);
    }

    /**
     * Stock out to a piece of work (a crop cycle, an animal group …): the
     * value goes to "inputs used" with that cost centre. Without a lot, the
     * quantity is taken from the earliest-expiring lots.
     *
     * @param  array{type:string, id?:?string, label?:?string}  $subject
     * @return array<int, StockMovement>
     */
    public function issue(InventoryItem $item, string $locationId, string|float $quantity, ?string $lotId, array $subject, string $sourceType, ?string $sourceId,
        ?CarbonImmutable $at = null, ?string $note = null): array
    {
        $qty = Qty::milli($quantity);
        if ($qty <= 0) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['quantity' => ['The quantity must be more than zero.']]);
        }
        $at ??= CarbonImmutable::now();

        return DB::transaction(function () use ($item, $locationId, $qty, $lotId, $subject, $sourceType, $sourceId, $at, $note) {
            $plan = [];
            foreach ($this->allocate($item, $locationId, $qty, $lotId) as [$balance, $take]) {
                $plan[] = [$balance, $take, $this->valueOf($balance, $take)];
            }
            $valueCents = array_sum(array_column($plan, 2));
            $center = in_array($subject['type'], ['crop_cycle', 'animal_group', 'animal', 'plot', 'location'], true) ? [$subject['type'], $subject['id'] ?? null] : [null, null];
            $entry = $this->ledger->post($sourceType, $sourceId, "Issued {$item->name} to ".($subject['label'] ?? $subject['type']), [
                JournalLine::debit(ChartOfAccounts::INPUTS_USED, Money::fromCents($valueCents), $center[0], $center[1]),
                JournalLine::credit(ChartOfAccounts::INVENTORY, Money::fromCents($valueCents)),
            ], $at);

            $movements = [];
            foreach ($plan as [$balance, $take, $value]) {
                $movements[] = $this->move($balance, 'issue', -$take, -$value, null, $sourceType, $sourceId, $subject, $at, $note, $entry?->id);
                if ($balance->lot_id && ($batch = TraceBatch::find($balance->lot?->trace_batch_id))) {
                    $this->recorder->record($batch, 'issued', [
                        'occurred_at' => $at,
                        'subject_type' => $subject['type'],
                        'subject_id' => $subject['id'] ?? null,
                        'payload' => array_filter(['quantity' => Qty::of($take), 'unit' => $item->unit, 'to' => $subject['label'] ?? null, 'source' => $sourceType]),
                    ]);
                }
            }

            return $movements;
        }, self::ATTEMPTS);
    }

    /**
     * Move stock between stores, keeping lots and their cost.
     *
     * @return array<int, StockMovement>
     */
    public function transfer(InventoryItem $item, string $from, string $to, string|float $quantity, ?string $lotId, string $sourceId, CarbonImmutable $at, ?string $note = null): array
    {
        $qty = Qty::milli($quantity);

        return DB::transaction(function () use ($item, $from, $to, $qty, $lotId, $sourceId, $at, $note) {
            $movements = [];
            foreach ($this->allocate($item, $from, $qty, $lotId) as [$balance, $take]) {
                $value = $this->valueOf($balance, $take);
                $movements[] = $this->move($balance, 'transfer_out', -$take, -$value, null, 'stock_transfer', $sourceId, null, $at, $note);
                $target = $this->lock($item, $to, $balance->lot_id);
                $movements[] = $this->move($target, 'transfer_in', $take, $value, null, 'stock_transfer', $sourceId, null, $at, $note);
            }

            return $movements;
        });
    }

    /** Bring one balance to a counted quantity; returns the value change (cents) for the adjustment's entry. */
    public function adjustTo(InventoryItem $item, string $locationId, ?string $lotId, string|float $counted, string $sourceId, CarbonImmutable $at, string $note, ?string $entryId = null): int
    {
        $balance = $this->lock($item, $locationId, $lotId);
        $delta = Qty::milli($counted) - Qty::milli($balance->quantity);
        if ($delta === 0) {
            return 0;
        }
        if ($delta < 0) {
            $value = -$this->valueOf($balance, -$delta);
        } else {
            // Found stock is valued at the lot's price, or the average of what is on hand.
            $unit = $balance->lot?->unit_cost ?? (Qty::milli($balance->quantity) > 0 ? Money::cents($balance->value) / (Qty::milli($balance->quantity) / 1000) / 100 : 0);
            $value = (int) round($delta * (float) $unit / 10);
        }
        $this->move($balance, 'adjustment', $delta, $value, null, 'stock_adjustment', $sourceId, null, $at, $note, $entryId);

        return $value;
    }

    /** What is on hand for a balance line (for counts). */
    public function onHand(InventoryItem $item, string $locationId, ?string $lotId): string
    {
        return (string) (StockBalance::where('item_id', $item->id)->where('location_id', $locationId)->where('lot_key', $lotId ?? '')->value('quantity') ?? '0');
    }

    /**
     * Lock and pick the balance rows to take `$qty` from: one lot, the
     * untracked row, or FEFO across lots.
     *
     * @return array<int, array{0: StockBalance, 1: int}>
     */
    private function allocate(InventoryItem $item, string $locationId, int $qty, ?string $lotId): array
    {
        if (! $item->tracks_lots || $lotId) {
            $balance = $this->lock($item, $locationId, $item->tracks_lots ? $lotId : null);
            if (! $balance->allow_negative && Qty::milli($balance->quantity) < $qty) {
                throw $this->insufficient($item, Qty::milli($balance->quantity), $qty);
            }

            return [[$balance, $qty]];
        }

        // Lock every lot row of the item in this store, in id order, then choose FEFO.
        $rows = StockBalance::with('lot')->where('item_id', $item->id)->where('location_id', $locationId)->where('lot_key', '!=', '')
            ->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (StockBalance $b) => Qty::milli($b->quantity) > 0)
            ->sortBy(fn (StockBalance $b) => [$b->lot?->expires_on?->toDateString() ?? '9999-12-31', $b->lot?->received_on?->toDateString() ?? '', $b->lot?->code])
            ->values();
        $available = $rows->sum(fn (StockBalance $b) => Qty::milli($b->quantity));
        if ($available < $qty) {
            throw $this->insufficient($item, $available, $qty);
        }
        $plan = [];
        $left = $qty;
        foreach ($rows as $b) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, Qty::milli($b->quantity));
            $plan[] = [$b, $take];
            $left -= $take;
        }

        return $plan;
    }

    /** The value (cents) of `$take` from a balance, at its average; all that is left when taking everything. */
    private function valueOf(StockBalance $b, int $take): int
    {
        $onHand = Qty::milli($b->quantity);
        $value = Money::cents($b->value);
        if ($onHand <= 0) {
            return 0;
        }

        return $take >= $onHand ? $value : (int) round($value * $take / $onHand);
    }

    private function move(StockBalance $balance, string $type, int $qty, int $valueCents, string|float|null $unitCost, string $sourceType, ?string $sourceId,
        ?array $subject, CarbonImmutable $at, ?string $note, ?string $entryId = null): StockMovement
    {
        $after = Qty::milli($balance->quantity) + $qty;
        if ($after < 0 && ! $balance->allow_negative) {
            throw $this->insufficient($balance->item, Qty::milli($balance->quantity), -$qty);
        }
        $balance->forceFill(['quantity' => Qty::of($after), 'value' => Money::fromCents(Money::cents($balance->value) + $valueCents)])->save();

        return StockMovement::create([
            'item_id' => $balance->item_id,
            'lot_id' => $balance->lot_id,
            'location_id' => $balance->location_id,
            'type' => $type,
            'quantity' => Qty::of($qty),
            'unit_cost' => $unitCost ?? ($qty !== 0 ? round(abs($valueCents) / 100 / (abs($qty) / 1000), 4) : null),
            'value' => Money::fromCents($valueCents),
            'balance_after' => Qty::of($after),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'subject_type' => $subject['type'] ?? null,
            'subject_id' => $subject['id'] ?? null,
            'ledger_entry_id' => $entryId,
            'note' => $note,
            'occurred_at' => $at,
            'recorded_by' => Auth::id(),
        ]);
    }

    /**
     * The balance row for an item, store and lot, locked. An existing row is
     * locked directly; only a missing one is inserted first (an INSERT IGNORE
     * on an existing key takes a shared lock on MySQL and deadlocks with the
     * FOR UPDATE that follows).
     */
    private function lock(InventoryItem $item, string $locationId, ?string $lotId): StockBalance
    {
        $find = fn () => StockBalance::with(['item', 'lot'])->where('item_id', $item->id)->where('location_id', $locationId)->where('lot_key', $lotId ?? '')->lockForUpdate()->first();
        if ($balance = $find()) {
            return $balance;
        }
        DB::table('stock_balances')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'farm_id' => $this->context->farmId(),
            'item_id' => $item->id,
            'location_id' => $locationId,
            'lot_id' => $lotId,
            'lot_key' => $lotId ?? '',
            'quantity' => 0,
            'value' => 0,
            'allow_negative' => ! $item->tracks_lots && (bool) ($this->settings->get($this->context->farm())['allow_negative_stock'] ?? false),
            'updated_at' => now(),
        ]);

        return $find() ?? throw new \RuntimeException('Stock balance row could not be created.');
    }

    private function insufficient(InventoryItem $item, int $available, int $wanted): ApiException
    {
        return new ApiException(422, 'insufficient_stock', "Not enough {$item->name} in this store: ".Qty::of($available)." {$item->unit} on hand, ".Qty::of($wanted).' needed.', [
            'available' => (float) Qty::of($available),
            'requested' => (float) Qty::of($wanted),
        ]);
    }

    private function lotCode(): string
    {
        return 'LOT-'.str_pad((string) Sequence::next('stock_lot'), 4, '0', STR_PAD_LEFT);
    }
}
