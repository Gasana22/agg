<?php

namespace App\Modules\Traceability\Http\Controllers;

use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\ChainVerifier;
use App\Modules\Traceability\Application\TraceAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Tamper evidence and alerts (docs/07 §3). */
class IntegrityController
{
    public function show(TenantContext $context): JsonResponse
    {
        $head = DB::table('trace_sequences')->where('farm_id', $context->farmId())->first();
        $audits = DB::table('trace_audits')->where('check_type', 'hash_chain')->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get();

        return new JsonResponse(['data' => [
            'events' => (int) ($head->last_seq ?? 0),
            'head_hash' => $head->last_hash ?? null,
            'latest' => $audits->isEmpty() ? null : $this->audit($audits->first()),
            'history' => $audits->map(fn ($a) => $this->audit($a))->all(),
        ]]);
    }

    public function verify(ChainVerifier $verifier): JsonResponse
    {
        $result = $verifier->verify();

        return new JsonResponse(['data' => $result + ['checked_at' => now()->toIso8601ZuluString()]], 201);
    }

    public function alerts(TraceAlerts $alerts): JsonResponse
    {
        $list = $alerts->all();
        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];
        foreach ($list as $a) {
            $counts[$a['severity']] += $a['count'];
        }

        return new JsonResponse(['data' => $list, 'meta' => ['counts' => $counts]]);
    }

    private function audit(object $a): array
    {
        $d = json_decode($a->details ?? '{}', true) ?? [];

        return [
            'id' => $a->id,
            'result' => $a->result,
            'events' => $d['events'] ?? null,
            'first_bad_seq' => $d['first_bad_seq'] ?? null,
            'reason' => $d['reason'] ?? null,
            'checked_at' => CarbonImmutable::parse($a->created_at)->toIso8601ZuluString(),
        ];
    }
}
