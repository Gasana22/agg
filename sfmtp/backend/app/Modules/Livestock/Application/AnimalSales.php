<?php

namespace App\Modules\Livestock\Application;

use App\Modules\Access\Application\ScopedAccess;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Livestock\Domain\Enums\AnimalStatus;
use App\Modules\Livestock\Domain\Enums\SaleStatus;
use App\Modules\Livestock\Domain\Models\Animal;
use App\Modules\Livestock\Domain\Models\SaleRequest;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Livestock sales (docs/04 §3): the manager or livestock manager requests,
 * the owner (or whoever holds `livestock.sales.approve`) approves and
 * completes. Nobody approves their own request unless they are the owner.
 * An animal inside a meat withdrawal period is only sold with a recorded
 * override reason. Customer orders and invoices arrive in Phases 8 and 12.
 */
class AnimalSales
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ScopedAccess $access,
        private readonly TenantContext $context,
        private readonly Herd $herd,
    ) {}

    public function request(array $data): SaleRequest
    {
        $this->access->assertNoMoneyUnlessAllowed($data, ['expected_price']);
        $animal = Animal::find($data['animal_id']) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['animal_id' => ['The selected animal does not exist in this farm.']]);
        if (! $animal->isActive()) {
            throw ApiException::conflict('animal_inactive', "{$animal->animal_code} has left the herd.");
        }
        if (SaleRequest::where('animal_id', $animal->id)->whereIn('status', [SaleStatus::Requested->value, SaleStatus::Approved->value])->exists()) {
            throw ApiException::conflict('duplicate', "{$animal->animal_code} already has an open sale request.");
        }

        $n = SaleRequest::count() + 1;
        do {
            $code = 'SR-'.str_pad((string) $n++, 3, '0', STR_PAD_LEFT);
        } while (SaleRequest::where('code', $code)->exists());

        $sale = SaleRequest::create($data + ['code' => $code, 'requested_by' => Auth::id()]);
        $this->audit->record('livestock.sale.requested', $sale, null, ['animal' => $animal->animal_code] + array_intersect_key($data, ['reason' => 1, 'buyer' => 1]));

        return $sale->refresh();
    }

    public function decide(SaleRequest $sale, bool $approve, ?string $note): SaleRequest
    {
        if ($sale->status !== SaleStatus::Requested) {
            throw ApiException::conflict('invalid_state_transition', "The request is {$sale->status->value}.");
        }
        if ($sale->requested_by === Auth::id() && ! $this->context->membership()?->is_owner) {
            throw ApiException::forbidden('four_eyes', 'Someone other than the requester must decide.');
        }

        $status = $approve ? SaleStatus::Approved : SaleStatus::Rejected;
        $sale->forceFill(['status' => $status, 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_note' => $note])->save();
        $this->audit->record('livestock.sale.'.($approve ? 'approved' : 'rejected'), $sale, ['status' => 'requested'], ['status' => $status->value, 'note' => $note]);

        return $sale;
    }

    public function complete(SaleRequest $sale, array $data): SaleRequest
    {
        $this->access->assertNoMoneyUnlessAllowed($data, ['sale_price']);
        if ($sale->status !== SaleStatus::Approved) {
            throw ApiException::conflict('invalid_state_transition', 'Only approved sale requests can be completed.');
        }
        $animal = $sale->animal;
        $soldOn = CarbonImmutable::parse($data['sold_on']);
        $override = $data['withdrawal_override_reason'] ?? null;
        if ($animal->meat_withdrawal_until && $soldOn->lessThan($animal->meat_withdrawal_until)) {
            if (! $override) {
                throw new ApiException(422, 'withdrawal_period', "{$animal->animal_code} is under a meat withdrawal period until {$animal->meat_withdrawal_until->toDateString()}. Sell after it, or record an override reason (for example, sold for breeding, not slaughter).", [
                    'withdrawal_until' => $animal->meat_withdrawal_until->toDateString(),
                ]);
            }
        } else {
            $override = null;
        }

        return DB::transaction(function () use ($sale, $animal, $data, $soldOn, $override) {
            $sale->forceFill([
                'status' => SaleStatus::Completed,
                'sold_on' => $soldOn->toDateString(),
                'sale_price' => $data['sale_price'] ?? null,
                'buyer' => $data['buyer'] ?? $sale->buyer,
                'withdrawal_override_reason' => $override,
            ])->save();
            $animal->forceFill(['status' => AnimalStatus::Sold, 'exited_on' => $soldOn->toDateString(), 'exit_reason' => "Sold ({$sale->code})"])->save();
            // No price in the trace history: it may become public (docs/07 §5).
            $this->herd->closeBatch($animal, 'sold', $soldOn->toDateString(), ['sale' => $sale->code, 'buyer' => $sale->buyer, 'withdrawal_override' => $override]);
            $this->audit->record('livestock.sale.completed', $sale, ['status' => 'approved'], ['status' => 'completed', 'sold_on' => $soldOn->toDateString()] + ($override ? ['withdrawal_override' => $override] : []));

            return $sale;
        });
    }
}
