<?php

namespace App\Modules\Finance\Http\Resources;

use App\Modules\Finance\Application\Budgets;
use App\Modules\Finance\Domain\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Budget */
class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lines = app(Budgets::class)->versusActual($this->resource);
        $sum = fn (string $type, string $key) => round(array_sum(array_column(array_filter($lines, fn ($l) => $l['type'] === $type), $key)), 2);

        return [
            'id' => $this->id,
            'type' => 'budget',
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'scope' => Refs::center($this->scope_type, $this->scope_id, $this->scope_label),
            'notes' => $this->notes,
            'lines' => $lines,
            'totals' => [
                'expense_budget' => $sum('expense', 'budget'), 'expense_actual' => $sum('expense', 'actual'),
                'income_budget' => $sum('income', 'budget'), 'income_actual' => $sum('income', 'actual'),
            ],
            'created_by' => Refs::user($this->creator),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
