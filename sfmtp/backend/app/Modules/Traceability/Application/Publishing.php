<?php

namespace App\Modules\Traceability\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\BatchStatus;
use App\Modules\Traceability\Domain\Models\TraceApproval;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceQrCode;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Publishing a batch (docs/07 §5): approve the public fields, issue QR
 * codes under the approval, revoke them, and count scans.
 */
class Publishing
{
    /** Crockford base32: no I, L, O or U, so codes read aloud and type cleanly. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const CODE_LENGTH = 10;

    public function __construct(
        private readonly PublicPayload $payloads,
        private readonly Recorder $recorder,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /** @param  array<int, string>  $fields */
    public function approve(TraceBatch $batch, array $fields, ?string $note = null): TraceApproval
    {
        $this->assertPublishable($batch);
        $unknown = array_diff($fields, array_keys(PublicPayload::FIELDS));
        if ($unknown) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['public_fields' => ['These fields cannot be published: '.implode(', ', $unknown).'.']]);
        }

        return DB::transaction(function () use ($batch, $fields, $note) {
            $fields = array_values(array_intersect(array_keys(PublicPayload::FIELDS), $fields));
            $approval = TraceApproval::create([
                'batch_id' => $batch->id,
                'public_fields' => $fields,
                'payload' => $this->payloads->build($batch, $fields),
                'note' => $note,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);
            $this->recorder->record($batch, 'public_fields_approved', ['subject_type' => 'trace_approval', 'subject_id' => $approval->id, 'payload' => ['fields' => implode(', ', $fields)]]);
            $this->audit->record('trace.publish.approved', $approval, null, ['batch' => $batch->batch_code, 'fields' => $fields]);

            return $approval;
        });
    }

    public function latestApproval(TraceBatch $batch): ?TraceApproval
    {
        return TraceApproval::where('batch_id', $batch->id)->orderByDesc('approved_at')->orderByDesc('id')->first();
    }

    public function issue(TraceBatch $batch, ?string $label = null): TraceQrCode
    {
        $this->assertPublishable($batch);
        $approval = $this->latestApproval($batch)
            ?? throw ApiException::unprocessable('not_approved', 'Approve the public fields of this batch before issuing a QR code.');

        return DB::transaction(function () use ($batch, $approval, $label) {
            for ($attempt = 0; ; $attempt++) {
                try {
                    // A savepoint, so a clash on the unique code does not abort the transaction.
                    $qr = DB::transaction(fn () => TraceQrCode::create([
                        'batch_id' => $batch->id,
                        'code' => self::newCode(),
                        'status' => 'active',
                        'approval_id' => $approval->id,
                        'label' => $label,
                        'issued_by' => Auth::id(),
                        'issued_at' => now(),
                    ]));
                    break;
                } catch (QueryException $e) {
                    if ($attempt >= 4) {
                        throw $e;
                    }
                }
            }
            $this->recorder->record($batch, 'qr_issued', ['subject_type' => 'trace_qr_code', 'subject_id' => $qr->id, 'payload' => array_filter(['code' => $qr->code, 'label' => $label])]);
            $this->audit->record('trace.qr.issued', $qr, null, ['batch' => $batch->batch_code, 'code' => $qr->code]);

            return $qr;
        });
    }

    public function revoke(TraceQrCode $qr, string $reason): TraceQrCode
    {
        if ($qr->status === 'revoked') {
            throw ApiException::conflict('invalid_state_transition', 'The QR code is already revoked.');
        }

        return DB::transaction(function () use ($qr, $reason) {
            $qr->forceFill(['status' => 'revoked', 'revoked_by' => Auth::id(), 'revoked_at' => now(), 'revoke_reason' => $reason])->save();
            $this->recorder->record($qr->batch, 'qr_revoked', ['subject_type' => 'trace_qr_code', 'subject_id' => $qr->id, 'payload' => ['code' => $qr->code, 'reason' => $reason]]);
            $this->audit->record('trace.qr.revoked', $qr, ['status' => 'active'], ['status' => 'revoked', 'reason' => $reason]);

            return $qr;
        });
    }

    /**
     * Revoke every active code of these batches (a recall).
     *
     * @param  array<int, string>  $batchIds
     */
    public function revokeForBatches(array $batchIds, string $reason): int
    {
        $count = 0;
        TraceQrCode::whereIn('batch_id', $batchIds)->where('status', 'active')->orderBy('id')->get()
            ->each(function (TraceQrCode $qr) use ($reason, &$count) {
                $this->revoke($qr, $reason);
                $count++;
            });

        return $count;
    }

    /**
     * What a scan of this code shows, in the code's farm context. A revoked
     * code or a recalled batch shows a notice and only the product, code and
     * farm (if approved).
     *
     * @return array<string, mixed>
     */
    public function publicView(TraceQrCode $qr): array
    {
        $batch = $qr->batch;
        $approval = $this->latestApproval($batch);
        // In catalogue order: JSON columns do not keep key order on every engine.
        $fields = array_intersect_key(array_replace(array_fill_keys(array_keys(PublicPayload::FIELDS), null), $approval?->payload ?? []), $approval?->payload ?? []);

        $notice = match (true) {
            $batch->status === BatchStatus::Recalled => ['type' => 'recalled', 'title' => 'This product has been recalled',
                'message' => 'The producer has recalled this batch. Do not eat or use it, and return it to where you bought it.'],
            $qr->status === 'revoked' => ['type' => 'withdrawn', 'title' => 'This code has been withdrawn',
                'message' => 'The producer no longer confirms the details of this product.'],
            default => null,
        };
        if ($notice !== null) {
            $fields = array_intersect_key($fields, array_flip(['product', 'batch_code', 'farm']));
        }

        return [
            'code' => $qr->code,
            'status' => $notice['type'] ?? 'active',
            'notice' => $notice,
            'approved_at' => $approval?->approved_at?->toIso8601ZuluString(),
            'fields' => (object) $fields,
            'generated_at' => now()->toIso8601ZuluString(),
        ];
    }

    /** Count a scan: the day and a two-letter country, nothing about the person. */
    public function countScan(TraceQrCode $qr, ?string $country): void
    {
        $country = is_string($country) && preg_match('/^[A-Za-z]{2}$/', $country) ? strtoupper($country) : 'ZZ';
        $day = CarbonImmutable::now($this->context->farm()->timezone)->toDateString();
        $key = ['qr_code_id' => $qr->id, 'day' => $day, 'country' => $country];

        $updated = DB::table('trace_qr_scans')->where($key)->increment('scans');
        if ($updated === 0) {
            try {
                DB::transaction(fn () => DB::table('trace_qr_scans')->insert($key + ['farm_id' => $qr->farm_id, 'scans' => 1]));
            } catch (QueryException) {
                DB::table('trace_qr_scans')->where($key)->increment('scans');   // a parallel scan inserted it first
            }
        }
        DB::table('trace_qr_codes')->where('id', $qr->id)->update(['scan_count' => DB::raw('scan_count + 1'), 'last_scanned_at' => now()]);
    }

    /**
     * Scans per day and per country over the last `$days` days.
     *
     * @return array{total:int, days: array<int, array{date:string, scans:int}>, countries: array<int, array{country:string, scans:int}>}
     */
    public function scanStats(int $days = 30, ?string $qrCodeId = null): array
    {
        $tz = $this->context->farm()->timezone;
        $from = CarbonImmutable::now($tz)->subDays($days - 1)->toDateString();
        $q = fn () => DB::table('trace_qr_scans')->where('farm_id', $this->context->farmId())->where('day', '>=', $from)
            ->when($qrCodeId, fn ($w) => $w->where('qr_code_id', $qrCodeId));
        $perDay = $q()->groupBy('day')->selectRaw('day, SUM(scans) AS scans')->pluck('scans', 'day');
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = CarbonImmutable::now($tz)->subDays($i)->toDateString();
            $series[] = ['date' => $date, 'scans' => (int) ($perDay[$date] ?? 0)];
        }

        return [
            'total' => array_sum(array_column($series, 'scans')),
            'days' => $series,
            'countries' => $q()->groupBy('country')->selectRaw('country, SUM(scans) AS scans')->orderByDesc('scans')->get()
                ->map(fn ($r) => ['country' => $r->country, 'scans' => (int) $r->scans])->all(),
        ];
    }

    public static function newCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, 31)];
        }

        return $code;
    }

    /** Codes are typed from labels: accept lower case and the letters people confuse. */
    public static function normalise(string $code): string
    {
        return strtr(strtoupper(trim($code)), ['O' => '0', 'I' => '1', 'L' => '1', '-' => '', ' ' => '']);
    }

    private function assertPublishable(TraceBatch $batch): void
    {
        if ($batch->status === BatchStatus::Recalled) {
            throw ApiException::conflict('trace_batch_recalled', 'A recalled batch cannot be published.');
        }
        if (in_array($batch->kind, [BatchKind::InputLot], true)) {
            throw ApiException::unprocessable('trace_not_publishable', 'Input lots are not sold, so they have no public page.');
        }
    }
}
