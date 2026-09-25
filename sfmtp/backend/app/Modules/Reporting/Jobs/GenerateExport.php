<?php

namespace App\Modules\Reporting\Jobs;

use App\Modules\Notifications\Application\Inbox;
use App\Modules\Reporting\Application\ReportFiles;
use App\Modules\Reporting\Application\StandardReports;
use App\Modules\Reporting\Domain\Models\ReportExport;
use App\Modules\Tenancy\Domain\Enums\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\QrLabels;
use App\Modules\Traceability\Domain\Models\TraceQrCode;
use App\Support\Http\ApiException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds one export (ADR-0017) inside the farm's context as the member who
 * asked for it: their permissions decide what the file holds, as they do
 * on screen. A member who has left the farm gets nothing.
 */
class GenerateExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $farmId, public readonly string $exportId) {}

    public function handle(TenantContext $context): void
    {
        $farm = Farm::find($this->farmId);
        if ($farm === null) {
            return;
        }

        $context->run($farm, function () use ($context, $farm) {
            $export = ReportExport::find($this->exportId);
            if ($export === null || $export->status !== 'queued') {
                return;
            }
            $membership = FarmUser::where('farm_id', $farm->id)->where('user_id', $export->requested_by)->first();
            if ($membership === null || $membership->status !== MembershipStatus::Active) {
                $this->fail($export, 'You are no longer a member of this farm.');

                return;
            }

            $export->forceFill(['status' => 'running', 'started_at' => now()])->save();
            $previousUser = Auth::user();

            try {
                // Build as the requester, with their permissions.
                Auth::setUser($membership->user);
                [$bytes, $rows] = $context->run($farm, fn () => $this->build($export, $farm), $membership);
                $path = "farms/{$farm->id}/exports/{$export->id}.{$export->format}";
                Storage::disk(config('sfmtp.exports.disk'))->put($path, $bytes);
                $export->forceFill([
                    'status' => 'ready', 'row_count' => $rows, 'file_path' => $path, 'file_size' => strlen($bytes),
                    'file_name' => Str::slug($export->title).'-'.now($farm->timezone)->format('Ymd-Hi').'.'.$export->format,
                    'finished_at' => now(), 'expires_at' => now()->addHours(config('sfmtp.exports.ttl_hours')),
                ])->save();
                app(Inbox::class)->notify([$export->requested_by], 'export_ready', "{$export->title} is ready",
                    'Download it within '.config('sfmtp.exports.ttl_hours').' hours.', "/farms/{$farm->id}/reports?tab=exports", ['export_id' => $export->id]);
            } catch (ApiException $e) {
                $this->fail($export, $e->getMessage());
            } catch (Throwable $e) {
                Log::error('Export failed', ['export_id' => $export->id, 'exception' => $e]);
                $this->fail($export, 'The export could not be built. Try again, or contact support if it keeps failing.');
            } finally {
                $previousUser === null ? Auth::forgetUser() : Auth::setUser($previousUser);
            }
        });
    }

    /** @return array{0:string, 1:int} file bytes and row count */
    private function build(ReportExport $export, Farm $farm): array
    {
        if ($export->kind === 'labels') {
            $ids = array_column($export->params['labels'], 'qr_code_id');
            $codes = TraceQrCode::with('batch')->whereIn('id', $ids)->get()->keyBy('id');
            $pairs = [];
            foreach ($export->params['labels'] as $l) {
                if (isset($codes[$l['qr_code_id']])) {
                    $pairs[] = [$codes[$l['qr_code_id']], (int) $l['copies']];
                }
            }

            return [app(QrLabels::class)->forCodes($pairs, $export->params['template']), array_sum(array_column($pairs, 1))];
        }

        $report = app(StandardReports::class)->run($export->report, $export->params, StandardReports::EXPORT_LIMIT);

        return [app(ReportFiles::class)->write($report, $export->format, $farm->name), count($report['rows'])];
    }

    private function fail(ReportExport $export, string $message): void
    {
        $export->forceFill(['status' => 'failed', 'error' => mb_substr($message, 0, 500), 'finished_at' => now()])->save();
    }
}
