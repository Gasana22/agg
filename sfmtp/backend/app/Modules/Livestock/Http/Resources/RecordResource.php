<?php

namespace App\Modules\Livestock\Http\Resources;

use App\Modules\Livestock\Domain\Models\AnimalRecord;
use App\Modules\Livestock\Domain\Models\Feeding;
use App\Modules\Livestock\Domain\Models\HealthRecord;
use App\Modules\Livestock\Domain\Models\Movement;
use App\Modules\Livestock\Domain\Models\ProductionRecord;
use App\Modules\Livestock\Domain\Models\Weight;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Any animal record; fields depend on its type. @mixin AnimalRecord */
class RecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $num = fn ($v) => $v === null ? null : (float) $v;
        $date = fn ($v) => $v?->toDateString();

        $fields = match (true) {
            $r instanceof HealthRecord => [
                'kind' => $r->kind->value, 'given_on' => $date($r->given_on), 'diagnosis' => $r->diagnosis, 'product_name' => $r->product_name,
                'dose' => $num($r->dose), 'dose_unit' => $r->dose_unit, 'input_batch_id' => $r->input_batch_id,
                'meat_withdrawal_days' => $r->meat_withdrawal_days, 'milk_withdrawal_days' => $r->milk_withdrawal_days,
                'next_due_on' => $date($r->next_due_on), 'given_by' => $r->given_by,
            ],
            $r instanceof Feeding => ['fed_on' => $date($r->fed_on), 'feed_name' => $r->feed_name, 'quantity' => $num($r->quantity), 'unit' => $r->unit, 'input_batch_id' => $r->input_batch_id],
            $r instanceof Weight => ['weighed_on' => $date($r->weighed_on), 'weight_kg' => $num($r->weight_kg), 'method' => $r->method->value],
            $r instanceof ProductionRecord => [
                'product' => $r->product->value, 'produced_on' => $date($r->produced_on), 'session' => $r->session, 'quantity' => $num($r->quantity),
                'unit' => $r->unit, 'discarded' => $r->discarded, 'lot' => $r->trace_batch_id ? ['id' => $r->trace_batch_id, 'batch_code' => $r->relationLoaded('lot') ? $r->lot?->batch_code : null] : null,
            ],
            $r instanceof Movement => ['moved_at' => $r->moved_at->toIso8601ZuluString(), 'from_location_id' => $r->from_location_id, 'to_location_id' => $r->to_location_id, 'reason' => $r->reason],
            default => [],
        };

        return [
            'id' => $r->id,
            'type' => 'animal_'.$r::recordType(),
            'animal' => $r->relationLoaded('animal') && $r->animal ? ['id' => $r->animal->id, 'animal_code' => $r->animal->animal_code, 'name' => $r->animal->name] : null,
            'group' => $r->relationLoaded('group') && $r->group ? ['id' => $r->group->id, 'code' => $r->group->code, 'name' => $r->group->name] : null,
        ] + $fields + [
            'notes' => $r->notes,
            'recorded_by' => $r->relationLoaded('recorder') && $r->recorder ? ['id' => $r->recorder->id, 'name' => $r->recorder->name] : null,
            'voided' => $r->relationLoaded('void') && $r->void ? ['reason' => $r->void->reason, 'at' => $r->void->created_at?->toIso8601ZuluString()] : null,
            'created_at' => $r->created_at?->toIso8601ZuluString(),
        ];
    }
}
