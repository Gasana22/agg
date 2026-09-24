<?php

namespace App\Modules\Crops;

use App\Modules\Crops\Domain\Models\CropCycle;
use App\Modules\Workforce\Application\WorkSubjects;
use App\Modules\Workforce\Contracts\WorkSubject;
use App\Modules\Workforce\Domain\Enums\SubjectType;
use Illuminate\Support\ServiceProvider;

class CropsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Work can be planned on a crop cycle; verified work lands on its crop lot.
        $this->app->make(WorkSubjects::class)->register(SubjectType::CropCycle, function (string $id): ?WorkSubject {
            $cycle = CropCycle::with(['crop', 'plot'])->find($id);

            return $cycle ? new WorkSubject(
                type: 'crop_cycle',
                id: $cycle->id,
                label: trim("{$cycle->code} {$cycle->crop?->label()} · {$cycle->plot?->code}"),
                module: 'crops',
                plotId: $cycle->plot_id,
                traceBatchId: $cycle->crop_lot_batch_id ?? $cycle->nursery_batch_id,
                active: $cycle->isOpen(),
            ) : null;
        });
    }
}
