<?php

namespace App\Modules\Traceability\Console;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\JourneyProjector;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use Illuminate\Console\Command;

/** Rebuild the `product_journeys` read model from the batch graph and events. */
class RefreshJourneysCommand extends Command
{
    protected $signature = 'trace:refresh-journeys {--farm= : Only this farm id}';

    protected $description = 'Rebuild every batch\'s product journey projection.';

    public function handle(TenantContext $context, JourneyProjector $projector): int
    {
        $farms = Farm::query()->when($this->option('farm'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->cursor();
        foreach ($farms as $farm) {
            $count = $context->run($farm, function () use ($projector) {
                $n = 0;
                TraceBatch::query()->orderBy('id')->each(function (TraceBatch $b) use ($projector, &$n) {
                    $projector->project($b);
                    $n++;
                });

                return $n;
            });
            $this->line("{$farm->code}: {$count} journeys");
        }

        return self::SUCCESS;
    }
}
