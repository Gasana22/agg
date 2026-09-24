<?php

namespace App\Modules\Traceability\Jobs;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\JourneyProjector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Refreshes `product_journeys` for batches that changed (docs/07 §4). */
class RefreshJourneys implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @param  array<int, string>  $batchIds */
    public function __construct(public readonly string $farmId, public readonly array $batchIds) {}

    public function handle(TenantContext $context, JourneyProjector $projector): void
    {
        $farm = Farm::find($this->farmId);
        if ($farm === null) {
            return;
        }
        $context->run($farm, fn () => $projector->refresh($this->batchIds));
    }
}
