<?php

namespace App\Modules\Livestock;

use App\Modules\Livestock\Domain\Models\Animal;
use App\Modules\Livestock\Domain\Models\AnimalGroup;
use App\Modules\Livestock\Domain\Models\Breeding;
use App\Modules\Livestock\Domain\Models\Feeding;
use App\Modules\Livestock\Domain\Models\HealthRecord;
use App\Modules\Livestock\Domain\Models\ProductionRecord;
use App\Modules\Livestock\Domain\Models\SaleRequest;
use App\Modules\Livestock\Domain\Models\Weight;
use App\Modules\Workforce\Application\WorkSubjects;
use App\Modules\Workforce\Contracts\WorkSubject;
use App\Modules\Workforce\Domain\Enums\SubjectType;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LivestockServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Bound through the models' farm scope: another farm's id is a 404.
        Route::model('group', AnimalGroup::class);
        Route::model('animal', Animal::class);
        Route::model('health_record', HealthRecord::class);
        Route::model('feeding', Feeding::class);
        Route::model('weight', Weight::class);
        Route::model('production', ProductionRecord::class);
        Route::model('breeding', Breeding::class);
        Route::model('sale', SaleRequest::class);

        // Work can be planned on an animal or a group; verified work lands on the animal's history.
        $subjects = $this->app->make(WorkSubjects::class);
        $subjects->register(SubjectType::Animal, function (string $id): ?WorkSubject {
            $animal = Animal::find($id);

            return $animal ? new WorkSubject('animal', $animal->id, $animal->label(), 'livestock',
                locationId: $animal->location_id, traceBatchId: $animal->trace_batch_id, active: $animal->isActive()) : null;
        });
        $subjects->register(SubjectType::AnimalGroup, function (string $id): ?WorkSubject {
            $group = AnimalGroup::find($id);

            return $group ? new WorkSubject('animal_group', $group->id, trim("{$group->code} {$group->name}"), 'livestock',
                locationId: $group->location_id, active: $group->is_active) : null;
        });
    }
}
