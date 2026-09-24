<?php

namespace App\Modules\Workforce\Application;

use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\Workforce\Contracts\WorkSubject;
use App\Modules\Workforce\Domain\Enums\SubjectType;
use App\Support\Http\ApiException;
use Closure;

/**
 * Finds the subject of an activity. Plots, locations and general work are
 * built in; Crops registers crop cycles and Livestock animals and groups
 * (they depend on Workforce, not the other way round: docs/09).
 */
class WorkSubjects
{
    /** @var array<string, Closure(string): ?WorkSubject> */
    private array $resolvers = [];

    /** @param  Closure(string): ?WorkSubject  $resolver */
    public function register(SubjectType $type, Closure $resolver): void
    {
        $this->resolvers[$type->value] = $resolver;
    }

    public function resolve(SubjectType $type, ?string $id): WorkSubject
    {
        $subject = match ($type) {
            SubjectType::General => new WorkSubject('general', null, 'General work'),
            SubjectType::Plot => ($plot = $id ? Plot::find($id) : null)
                ? new WorkSubject('plot', $plot->id, trim("{$plot->code} {$plot->name}"), plotId: $plot->id) : null,
            SubjectType::Location => ($location = $id ? Location::find($id) : null)
                ? new WorkSubject('location', $location->id, trim("{$location->code} {$location->name}"), locationId: $location->id) : null,
            default => $id && isset($this->resolvers[$type->value]) ? ($this->resolvers[$type->value])($id) : null,
        };

        return $subject ?? throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [
            'subject_id' => ["The selected {$type->value} does not exist in this farm."],
        ]);
    }
}
