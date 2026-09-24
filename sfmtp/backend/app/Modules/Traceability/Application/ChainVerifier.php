<?php

namespace App\Modules\Traceability\Application;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Re-computes a farm's event hash chain (docs/07 §3, tamper evidence) and
 * records the result in trace_audits.
 */
class ChainVerifier
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{result:'pass'|'fail', events:int, first_bad_seq:?int, reason:?string}
     */
    public function verify(): array
    {
        $farmId = $this->context->farmId();
        $expectedPrev = EventHasher::GENESIS;
        $expectedSeq = 1;
        $count = 0;
        $failure = null;

        DB::table('trace_events')
            ->where('farm_id', $farmId)
            ->orderBy('farm_seq')
            ->lazy(500)
            ->each(function ($row) use (&$expectedPrev, &$expectedSeq, &$count, &$failure) {
                $count++;
                $row = (array) $row;

                $reason = match (true) {
                    (int) $row['farm_seq'] !== $expectedSeq => 'sequence_gap',
                    $row['prev_hash'] !== $expectedPrev => 'broken_link',
                    EventHasher::hash($row['prev_hash'], $row) !== $row['hash'] => 'hash_mismatch',
                    default => null,
                };

                if ($reason !== null) {
                    $failure = ['seq' => (int) $row['farm_seq'], 'reason' => $reason];

                    return false;
                }

                $expectedPrev = $row['hash'];
                $expectedSeq++;
            });

        $head = DB::table('trace_sequences')->where('farm_id', $farmId)->first();
        if ($failure === null && $head !== null && ((int) $head->last_seq !== $count || $head->last_hash !== $expectedPrev)) {
            $failure = ['seq' => (int) $head->last_seq, 'reason' => 'head_mismatch'];
        }

        $result = [
            'result' => $failure === null ? 'pass' : 'fail',
            'events' => $count,
            'first_bad_seq' => $failure['seq'] ?? null,
            'reason' => $failure['reason'] ?? null,
        ];

        DB::table('trace_audits')->insert([
            'id' => (string) Str::uuid7(),
            'farm_id' => $farmId,
            'batch_id' => null,
            'check_type' => 'hash_chain',
            'result' => $result['result'],
            'details' => json_encode($result),
            // With microseconds, so two checks in one second keep their order.
            'created_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        return $result;
    }
}
