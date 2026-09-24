<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Finance\Application\JournalLine;
use App\Modules\Finance\Application\Ledger;
use App\Modules\Finance\Application\Money;
use App\Modules\Inventory\Domain\Models\InventoryItem;
use App\Modules\Inventory\Domain\Models\InventoryRequest;
use App\Modules\Inventory\Domain\Models\StockAdjustment;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockLot;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use App\Modules\Tenancy\Application\FarmSettings;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Workforce\Application\WorkSubjects;
use App\Modules\Workforce\Domain\Enums\SubjectType;
use App\Modules\Workforce\Domain\Models\Task;
use App\Support\Database\Sequence;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Store work: opening stock, direct issues, transfers, counts (adjustments
 * with approval) and requests for stock (approve, then issue).
 */
class StockDesk
{
    public function __construct(
        private readonly StockService $stock,
        private readonly Ledger $ledger,
        private readonly AuditLogger $audit,
        private readonly InventoryAccess $access,
        private readonly WorkSubjects $subjects,
        private readonly FarmSettings $settings,
        private readonly TenantContext $context,
    ) {}

    // Stock in and out

    /** Opening stock or stock found outside a purchase; the value goes to opening balances. */
    public function stockIn(array $data): StockMovement
    {
        $item = $this->item($data['item_id']);
        $this->store($data['location_id']);
        if (isset($data['unit_cost']) && ! $this->access->seesValues()) {
            throw ApiException::forbidden('money_field_forbidden', 'You cannot record stock values.');
        }

        return $this->stock->receive($item, $data['location_id'], $data['quantity'], $data['unit_cost'] ?? null, 'opening', 'opening_stock', null,
            ChartOfAccounts::OPENING_BALANCES, array_intersect_key($data, array_flip(['lot_number', 'expires_on'])), $this->at($data), $data['note'] ?? null);
    }

    /** @return array<int, StockMovement> */
    public function issue(array $data): array
    {
        $item = $this->item($data['item_id']);
        $this->store($data['location_id']);
        $subject = $this->subject($data['subject_type'] ?? 'general', $data['subject_id'] ?? null);

        return $this->stock->issue($item, $data['location_id'], $data['quantity'], $data['lot_id'] ?? null, $subject, 'direct_issue', null, $this->at($data), $data['note'] ?? null);
    }

    public function transfer(array $data): StockTransfer
    {
        if ($data['from_location_id'] === $data['to_location_id']) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['to_location_id' => ['Choose a different store.']]);
        }
        $this->store($data['from_location_id']);
        $this->store($data['to_location_id']);
        $at = $this->at($data);

        return DB::transaction(function () use ($data, $at) {
            $transfer = StockTransfer::create([
                'code' => Sequence::code('stock_transfer', 'TRF'),
                'from_location_id' => $data['from_location_id'],
                'to_location_id' => $data['to_location_id'],
                'note' => $data['note'] ?? null,
                'transferred_by' => Auth::id(),
                'occurred_at' => $at,
            ]);
            foreach ($data['lines'] as $i => $line) {
                $this->stock->transfer($this->item($line['item_id'], "lines.{$i}.item_id"), $data['from_location_id'], $data['to_location_id'], $line['quantity'], $line['lot_id'] ?? null, $transfer->id, $at, $data['note'] ?? null);
            }
            $this->audit->record('inventory.transfer', $transfer, null, ['code' => $transfer->code, 'lines' => count($data['lines'])]);

            return $transfer;
        });
    }

    // Counts

    /** Propose counted quantities for a store; the books are kept until someone approves. */
    public function proposeAdjustment(array $data): StockAdjustment
    {
        $this->store($data['location_id']);

        return DB::transaction(function () use ($data) {
            $adjustment = StockAdjustment::create([
                'code' => Sequence::code('stock_adjustment', 'ADJ'),
                'location_id' => $data['location_id'],
                'reason' => $data['reason'],
                'proposed_by' => Auth::id(),
            ]);
            foreach ($data['lines'] as $i => $line) {
                $item = $this->item($line['item_id'], "lines.{$i}.item_id");
                if ($item->tracks_lots && empty($line['lot_id'])) {
                    throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.lot_id" => ["Count {$item->name} by lot."]]);
                }
                if (! empty($line['lot_id']) && ! StockLot::where('item_id', $item->id)->whereKey($line['lot_id'])->exists()) {
                    throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.lot_id" => ['Unknown lot for this item.']]);
                }
                $adjustment->lines()->create([
                    'item_id' => $item->id,
                    'lot_id' => $item->tracks_lots ? $line['lot_id'] : null,
                    'expected_quantity' => $this->stock->onHand($item, $data['location_id'], $item->tracks_lots ? $line['lot_id'] : null),
                    'counted_quantity' => $line['counted_quantity'],
                ]);
            }
            $this->audit->record('inventory.adjustment.proposed', $adjustment, null, ['code' => $adjustment->code, 'reason' => $data['reason']]);

            return $adjustment->refresh();
        });
    }

    /**
     * Apply the counts. Nobody approves their own count unless they are the
     * owner; a change above the farm's stock adjustment threshold (percent of
     * the store's affected value) needs the owner.
     */
    public function decideAdjustment(StockAdjustment $adjustment, bool $approve, ?string $note): StockAdjustment
    {
        if ($adjustment->status !== 'proposed') {
            throw ApiException::conflict('invalid_state_transition', "{$adjustment->code} is {$adjustment->status}.");
        }
        if ($adjustment->proposed_by === Auth::id() && ! $this->access->isOwner()) {
            throw ApiException::forbidden('four_eyes', 'Someone other than the person who counted must approve.');
        }
        if (! $approve) {
            $adjustment->forceFill(['status' => 'rejected', 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->audit->record('inventory.adjustment.rejected', $adjustment, ['status' => 'proposed'], ['status' => 'rejected', 'note' => $note]);

            return $adjustment;
        }

        return DB::transaction(function () use ($adjustment, $note) {
            $at = CarbonImmutable::now();
            $lines = $adjustment->lines()->with('item')->get();
            $threshold = $this->settings->get($this->context->farm())['approval_thresholds']['stock_adjustment_pct'] ?? null;

            // Value the change first (under the balance locks), check the threshold, then post and apply.
            $booked = 0;
            $change = 0;
            foreach ($lines as $line) {
                $booked += Money::cents(StockBalance::where('item_id', $line->item_id)->where('location_id', $adjustment->location_id)->where('lot_key', $line->lot_id ?? '')->lockForUpdate()->value('value') ?? 0);
            }
            $values = [];
            foreach ($lines as $line) {
                $values[] = $v = $this->stock->adjustTo($line->item, $adjustment->location_id, $line->lot_id, $line->counted_quantity, $adjustment->id, $at, "Count {$adjustment->code}: {$adjustment->reason}");
                $change += $v;
            }
            if ($threshold !== null && ! $this->access->isOwner()) {
                $pct = $booked > 0 ? abs($change) * 100 / $booked : ($change !== 0 ? INF : 0);
                if ($pct > (float) $threshold) {
                    throw new ApiException(403, 'approval_required', "This count changes stock value by more than {$threshold}%; the owner must approve it.", ['threshold_pct' => (float) $threshold]);
                }
            }
            $entry = $this->ledger->post('stock_adjustment', $adjustment->id, "Stock count {$adjustment->code}: {$adjustment->reason}", [
                JournalLine::debit(ChartOfAccounts::INVENTORY, Money::fromCents($change)),
                JournalLine::credit(ChartOfAccounts::STOCK_ADJUSTMENTS, Money::fromCents($change)),
            ], $at);
            $adjustment->forceFill([
                'status' => 'approved', 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_note' => $note,
                'value_change' => Money::fromCents($change), 'ledger_entry_id' => $entry?->id,
            ])->save();
            $this->audit->record('inventory.adjustment.approved', $adjustment, ['status' => 'proposed'], ['status' => 'approved', 'lines' => count($values)]);

            return $adjustment;
        });
    }

    // Requests

    public function request(array $data): InventoryRequest
    {
        $task = null;
        if (! empty($data['task_id'])) {
            $task = Task::with('activity')->find($data['task_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['task_id' => ['Unknown task.']]);
            $data['subject_type'] = $task->activity->subject_type->value;
            $data['subject_id'] = $task->activity->subject_id;
        }
        $subject = $this->subject($data['subject_type'] ?? 'general', $data['subject_id'] ?? null);
        if (! empty($data['location_id'])) {
            $this->store($data['location_id']);
        }

        return DB::transaction(function () use ($data, $subject, $task) {
            $request = InventoryRequest::create([
                'code' => Sequence::code('inventory_request', 'REQ'),
                'requested_by' => Auth::id(),
                'task_id' => $task?->id,
                'subject_type' => $subject['type'],
                'subject_id' => $subject['id'],
                'subject_label' => $subject['label'],
                'location_id' => $data['location_id'] ?? null,
                'needed_on' => $data['needed_on'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
            foreach ($data['lines'] as $i => $line) {
                $request->lines()->create(['item_id' => $this->item($line['item_id'], "lines.{$i}.item_id")->id, 'quantity' => $line['quantity']]);
            }
            $this->audit->record('inventory.request.created', $request, null, ['code' => $request->code, 'for' => $subject['label']]);

            return $request->refresh();
        });
    }

    public function decideRequest(InventoryRequest $request, bool $approve, ?string $note): InventoryRequest
    {
        if ($request->status !== 'requested') {
            throw ApiException::conflict('invalid_state_transition', "{$request->code} is {$request->status}.");
        }
        if ($request->requested_by === Auth::id() && ! $this->access->isOwner()) {
            throw ApiException::forbidden('four_eyes', 'Someone else must approve your own request.');
        }
        $status = $approve ? 'approved' : 'rejected';
        $request->forceFill(['status' => $status, 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_note' => $note])->save();
        $this->audit->record("inventory.request.{$status}", $request, ['status' => 'requested'], ['status' => $status, 'note' => $note]);

        return $request;
    }

    /**
     * Issue approved lines (all, or some quantities). Each line is issued to
     * the request's subject from the chosen store.
     *
     * @param  array<int, array{line_id:string, quantity:string|float, lot_id?:?string}>  $lines
     */
    public function issueRequest(InventoryRequest $request, string $locationId, array $lines, ?string $note): InventoryRequest
    {
        if (! in_array($request->status, ['approved', 'partially_issued'], true)) {
            throw ApiException::conflict('invalid_state_transition', "{$request->code} is {$request->status}; only approved requests are issued.");
        }
        $this->store($locationId);
        $subject = ['type' => $request->subject_type, 'id' => $request->subject_id, 'label' => $request->subject_label ?? 'General work'];

        return DB::transaction(function () use ($request, $locationId, $lines, $note, $subject) {
            $at = CarbonImmutable::now();
            $all = $request->lines()->with('item')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $i => $l) {
                $line = $all->get($l['line_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.line_id" => ['Not a line of this request.']]);
                $left = Qty::milli($line->quantity) - Qty::milli($line->issued_quantity);
                if (Qty::milli($l['quantity']) > $left) {
                    throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["lines.{$i}.quantity" => ['More than was requested: '.Qty::of($left).' left.']]);
                }
                $this->stock->issue($line->item, $locationId, $l['quantity'], $l['lot_id'] ?? null, $subject, 'inventory_request', $request->id, $at, $note ?? $request->code);
                $line->forceFill(['issued_quantity' => Qty::of(Qty::milli($line->issued_quantity) + Qty::milli($l['quantity']))])->save();
            }
            $complete = $request->lines()->get()->every(fn ($line) => Qty::milli($line->issued_quantity) >= Qty::milli($line->quantity));
            $request->forceFill(['status' => $complete ? 'issued' : 'partially_issued', 'location_id' => $locationId])->save();
            $this->audit->record('inventory.request.issued', $request, null, ['status' => $request->status, 'lines' => count($lines)]);

            return $request;
        });
    }

    public function cancelRequest(InventoryRequest $request): InventoryRequest
    {
        if (! in_array($request->status, ['requested', 'approved'], true)) {
            throw ApiException::conflict('invalid_state_transition', "{$request->code} is {$request->status}.");
        }
        if ($request->requested_by !== Auth::id() && ! $this->access->can('inventory.stock.approve')) {
            throw ApiException::forbidden();
        }
        $request->forceFill(['status' => 'cancelled'])->save();

        return $request;
    }

    // Helpers

    /** @return array{type:string, id:?string, label:string} */
    private function subject(string $type, ?string $id): array
    {
        $resolved = $this->subjects->resolve(SubjectType::tryFrom($type) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['subject_type' => ['Unknown subject type.']]), $id);

        return ['type' => $resolved->type, 'id' => $resolved->id, 'label' => $resolved->label];
    }

    private function item(string $id, string $field = 'item_id'): InventoryItem
    {
        $item = InventoryItem::find($id) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => ['The selected item does not exist in this farm.']]);
        if (! $item->is_active) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => ["{$item->name} is archived."]]);
        }

        return $item;
    }

    private function store(string $locationId): Location
    {
        return Location::find($locationId) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['location_id' => ['The selected store does not exist in this farm.']]);
    }

    private function at(array $data): CarbonImmutable
    {
        $at = isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at'])->utc() : CarbonImmutable::now();
        if ($at->isAfter(now()->addMinutes(5))) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['occurred_at' => ['The time cannot be in the future.']]);
        }

        return $at;
    }
}
