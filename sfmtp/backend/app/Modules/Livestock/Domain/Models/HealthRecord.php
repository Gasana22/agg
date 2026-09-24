<?php

namespace App\Modules\Livestock\Domain\Models;

use App\Modules\Livestock\Domain\Enums\HealthKind;

class HealthRecord extends AnimalRecord
{
    protected $table = 'animal_health_records';

    protected $fillable = ['farm_id', 'animal_id', 'group_id', 'kind', 'given_on', 'diagnosis', 'product_name', 'dose', 'dose_unit', 'input_batch_id', 'meat_withdrawal_days', 'milk_withdrawal_days', 'next_due_on', 'given_by', 'notes', 'worker_id', 'recorded_by'];

    protected function casts(): array
    {
        return ['kind' => HealthKind::class, 'given_on' => 'date', 'next_due_on' => 'date', 'dose' => 'decimal:3', 'meat_withdrawal_days' => 'integer', 'milk_withdrawal_days' => 'integer'];
    }

    public static function recordType(): string
    {
        return 'health';
    }
}
