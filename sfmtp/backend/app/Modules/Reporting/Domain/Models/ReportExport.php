<?php

namespace App\Modules\Reporting\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A queued export: a report file or a label sheet (ADR-0017).
 *
 * @property string $id
 * @property string $farm_id
 * @property string $kind
 * @property string|null $report
 * @property string $title
 * @property array $params
 * @property string $format
 * @property string $status
 * @property string $requested_by
 * @property int|null $row_count
 * @property string|null $file_path
 * @property string|null $file_name
 * @property int|null $file_size
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 */
class ReportExport extends Model
{
    use BelongsToFarm, HasUuids;

    public const OPEN = ['queued', 'running'];

    public const MIME = [
        'csv' => 'text/csv; charset=UTF-8',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf' => 'application/pdf',
    ];

    protected $fillable = ['farm_id', 'kind', 'report', 'title', 'params', 'format', 'status', 'requested_by'];

    protected function casts(): array
    {
        return ['params' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'expires_at' => 'datetime', 'row_count' => 'integer', 'file_size' => 'integer'];
    }

    /** Ready files past their 24 hours read as expired even before the sweep removes them. */
    public function currentStatus(): string
    {
        return $this->status === 'ready' && $this->expires_at?->isPast() ? 'expired' : $this->status;
    }

    public function toApi(): array
    {
        $status = $this->currentStatus();

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'report' => $this->report,
            'title' => $this->title,
            'params' => $this->params,
            'format' => $this->format,
            'status' => $status,
            'row_count' => $this->row_count,
            'file_name' => $status === 'ready' ? $this->file_name : null,
            'file_size' => $status === 'ready' ? $this->file_size : null,
            'error' => $this->error,
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'finished_at' => $this->finished_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'download_path' => $status === 'ready' ? "/farms/{$this->farm_id}/exports/{$this->id}/download" : null,
        ];
    }
}
