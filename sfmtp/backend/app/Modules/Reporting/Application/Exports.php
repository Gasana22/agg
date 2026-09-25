<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Reporting\Domain\Models\ReportExport;
use App\Modules\Reporting\Jobs\GenerateExport;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\QrLabels;
use App\Modules\Traceability\Domain\Models\TraceQrCode;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Queued exports (ADR-0017). A request is checked at once (permissions,
 * parameters, the farm's limit on exports in progress) and then built by a
 * queued job as the member who asked, so the file holds exactly what they
 * may see. Files live for 24 hours and only their requester may download them.
 */
class Exports
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FarmPermissions $permissions,
        private readonly StandardReports $reports,
    ) {}

    public function request(array $input): ReportExport
    {
        $data = Validator::make($input, [
            'kind' => ['sometimes', Rule::in(['report', 'labels'])],
        ])->validate();
        $kind = $data['kind'] ?? 'report';

        $attributes = $kind === 'report' ? $this->reportRequest($input) : $this->labelsRequest($input);

        return DB::transaction(function () use ($attributes) {
            // Serialise requests per farm so the limit holds under concurrency.
            DB::table('farms')->where('id', $this->context->farmId())->lockForUpdate()->first();
            $open = ReportExport::whereIn('status', ReportExport::OPEN)->count();
            if ($open >= config('sfmtp.exports.max_open_per_farm')) {
                throw new ApiException(429, 'export_limit', 'This farm already has exports in progress. Try again when they finish.');
            }
            $recent = ReportExport::where('requested_by', Auth::id())->where('created_at', '>=', now()->subHour())->count();
            if ($recent >= config('sfmtp.exports.max_per_user_per_hour')) {
                throw new ApiException(429, 'export_limit', 'You have asked for many exports in the last hour. Try again later.');
            }

            $export = ReportExport::create($attributes + ['farm_id' => $this->context->farmId(), 'status' => 'queued', 'requested_by' => Auth::id()]);
            GenerateExport::dispatch($export->farm_id, $export->id)->afterCommit();

            return $export;
        });
    }

    /** @return array<string,mixed> */
    private function reportRequest(array $input): array
    {
        $data = Validator::make($input, [
            'report' => ['required', 'string', 'max:60'],
            'format' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])],
            'params' => ['sometimes', 'array'],
        ])->validate();

        if (! $this->reports->exists($data['report'])) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['report' => ['Unknown report.']]);
        }
        if (! $this->permissions->allows('reports.export') || ! $this->reports->allowedKey($data['report'])) {
            throw ApiException::forbidden('export_not_allowed', 'Your role cannot export this report.');
        }
        $def = $this->reports->definition($data['report']);
        $params = $this->reports->params($def['params'], $data['params'] ?? []);

        return ['kind' => 'report', 'report' => $data['report'], 'title' => $def['title'], 'params' => $params, 'format' => $data['format']];
    }

    /** @return array<string,mixed> */
    private function labelsRequest(array $input): array
    {
        if (! $this->permissions->allows('trace.batches.view')) {
            throw ApiException::forbidden();
        }
        $data = Validator::make($input, [
            'format' => ['sometimes', Rule::in(['pdf'])],
            'template' => ['sometimes', Rule::in(array_keys(QrLabels::TEMPLATES))],
            'labels' => ['required', 'array', 'min:1', 'max:200'],
            'labels.*.qr_code_id' => ['required', 'uuid', 'distinct'],
            'labels.*.copies' => ['sometimes', 'integer', 'min:1', 'max:240'],
        ])->validate();

        $ids = array_column($data['labels'], 'qr_code_id');
        $codes = TraceQrCode::whereIn('id', $ids)->where('status', 'active')->pluck('code', 'id');
        $missing = array_values(array_diff($ids, $codes->keys()->all()));
        if ($missing !== []) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['labels' => ['These QR codes are not active codes of this farm: '.implode(', ', $missing)]]);
        }
        $labels = array_map(fn ($l) => ['qr_code_id' => $l['qr_code_id'], 'copies' => (int) ($l['copies'] ?? 1)], $data['labels']);
        $total = array_sum(array_column($labels, 'copies'));
        if ($total > QrLabels::MAX_LABELS) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['labels' => ['A print run holds at most '.QrLabels::MAX_LABELS.' labels.']]);
        }
        $template = $data['template'] ?? 'a4_3x8';

        return ['kind' => 'labels', 'report' => null, 'title' => count($labels) === 1 ? "QR labels {$codes[$labels[0]['qr_code_id']]}" : 'QR labels ('.count($labels).' codes, '.$total.' labels)',
            'params' => ['template' => $template, 'labels' => $labels], 'format' => 'pdf'];
    }

    /** The member's own exports, newest first. */
    public function mine(int $limit = 50)
    {
        return ReportExport::where('requested_by', Auth::id())->latest()->orderByDesc('id')->limit($limit)->get();
    }

    /** One of the member's own exports; anyone else's is a 404. */
    public function find(string $id): ReportExport
    {
        if (! Str::isUuid($id)) {
            throw ApiException::notFound();
        }

        return ReportExport::where('requested_by', Auth::id())->find($id) ?? throw ApiException::notFound();
    }

    /** @return array{0:string, 1:string} path and file name of a ready export */
    public function file(ReportExport $export): array
    {
        $status = $export->currentStatus();
        if ($status === 'expired') {
            throw new ApiException(410, 'export_expired', 'This export has expired. Run it again.');
        }
        if ($status !== 'ready') {
            throw ApiException::conflict('export_not_ready', 'This export is not ready yet.');
        }
        if (! Storage::disk(config('sfmtp.exports.disk'))->exists($export->file_path)) {
            throw new ApiException(410, 'export_expired', 'This export has expired. Run it again.');
        }

        return [$export->file_path, $export->file_name];
    }
}
