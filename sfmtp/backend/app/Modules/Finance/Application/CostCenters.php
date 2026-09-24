<?php

namespace App\Modules\Finance\Application;

use App\Modules\Workforce\Application\WorkSubjects;
use App\Modules\Workforce\Domain\Enums\SubjectType;
use App\Support\Http\ApiException;

/**
 * Cost centres are the farm's work subjects (a crop cycle, plot, location,
 * animal or group): P&L by crop and cost per acre read them from ledger
 * lines. General costs carry none.
 */
class CostCenters
{
    public function __construct(private readonly WorkSubjects $subjects) {}

    /** @return array{0: ?string, 1: ?string, 2: ?string} type, id and label; nulls for general */
    public function resolve(?string $type, ?string $id, string $field = 'cost_center_id'): array
    {
        if ($type === null || $type === '' || $type === SubjectType::General->value) {
            return [null, null, null];
        }
        $subjectType = SubjectType::tryFrom($type) ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [
            str_replace('_id', '_type', $field) => ['Unknown cost centre type.'],
        ]);
        try {
            $subject = $this->subjects->resolve($subjectType, $id);
        } catch (ApiException) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => ["The selected {$type} does not exist in this farm."]]);
        }

        return [$subject->type, $subject->id, $subject->label];
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_values(array_filter(SubjectType::values(), fn ($t) => $t !== SubjectType::General->value));
    }
}
