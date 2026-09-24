<?php

namespace App\Modules\Finance\Http\Resources;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Finance\Domain\Models\PayrollRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payroll run. Members who may see hours but not wages
 * (finance.payroll.view_hours without finance.values.view) get days,
 * minutes and tasks only.
 *
 * @mixin PayrollRun
 */
class PayrollRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $money = app(FinanceAccess::class)->seesValues();

        return [
            'id' => $this->id,
            'type' => 'payroll_run',
            'code' => $this->code,
            'status' => $this->status,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'workers' => $this->lines_count ?? $this->whenLoaded('lines', fn () => $this->lines->count()),
            'notes' => $this->notes,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->sortBy(fn ($l) => $l->worker?->worker_code)->map(fn ($l) => [
                'id' => $l->id,
                'worker' => $l->worker ? ['id' => $l->worker->id, 'worker_code' => $l->worker->worker_code, 'full_name' => $l->worker->full_name] : null,
                'days_worked' => $l->days_worked,
                'minutes_worked' => $l->minutes_worked,
                'tasks_verified' => $l->tasks_verified,
                'note' => $l->note,
            ] + ($money ? [
                'daily_rate' => (float) $l->daily_rate, 'bonus' => (float) $l->bonus, 'gross' => (float) $l->gross,
                'deductions' => (float) $l->deductions, 'net' => (float) $l->net,
                'allocation' => collect($l->allocation ?? [])->map(fn ($a) => ['type' => $a['type'], 'id' => $a['id'], 'label' => $a['label'], 'amount' => $a['amount'] / 100])->values(),
            ] : []))->values()),
            'ledger_entry_id' => $this->when($money, $this->ledger_entry_id),
            'prepared_by' => Refs::user($this->preparer),
            'approved_by' => Refs::user($this->approver),
            'approved_at' => $this->approved_at?->toIso8601ZuluString(),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ] + ($money ? [
            'total_gross' => (float) $this->total_gross, 'total_deductions' => (float) $this->total_deductions,
            'total_net' => (float) $this->total_net, 'paid_amount' => (float) $this->paid_amount,
        ] : []);
    }
}
