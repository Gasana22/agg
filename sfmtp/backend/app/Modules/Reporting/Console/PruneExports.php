<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Domain\Models\ReportExport;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes export files past their 24 hours and marks them expired; exports
 * stuck in the queue for a day are marked failed (scheduled hourly).
 */
class PruneExports extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Delete expired export files and close stuck exports.';

    public function handle(TenantContext $context): int
    {
        // Platform housekeeping across farms: files only, no farm data is read.
        [$expired, $stuck] = $context->bypass(function () {
            $disk = Storage::disk(config('sfmtp.exports.disk'));
            $expired = 0;
            ReportExport::withoutGlobalScopes()->where('status', 'ready')->where('expires_at', '<', now())->orderBy('id')
                ->chunkById(200, function ($exports) use ($disk, &$expired) {
                    foreach ($exports as $export) {
                        if ($export->file_path) {
                            $disk->delete($export->file_path);
                        }
                        $export->forceFill(['status' => 'expired', 'file_path' => null])->save();
                        $expired++;
                    }
                });
            $stuck = ReportExport::withoutGlobalScopes()->whereIn('status', ReportExport::OPEN)->where('created_at', '<', now()->subDay())
                ->update(['status' => 'failed', 'error' => 'The export did not finish. Run it again.', 'finished_at' => now(), 'updated_at' => now()]);

            return [$expired, $stuck];
        });

        $this->info("Expired {$expired} export(s); closed {$stuck} stuck export(s).");

        return self::SUCCESS;
    }
}
