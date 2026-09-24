<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Finance\Application\Ledger;
use App\Modules\Finance\Application\Money;
use App\Modules\Finance\Domain\Models\LedgerAccount;
use App\Modules\Finance\Domain\Models\LedgerEntry;
use App\Modules\Finance\Http\Resources\LedgerEntryResource;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class LedgerController
{
    public function __construct(private readonly Ledger $ledger, private readonly ChartOfAccounts $chart, private readonly TenantContext $context) {}

    /** The chart of accounts with balances: a trial balance, optionally as of a date. */
    public function accounts(Request $request): JsonResponse
    {
        $data = $request->validate(['as_of' => ['sometimes', 'date']]);
        $this->chart->ensure();
        $totals = DB::table('ledger_lines as l')
            ->join('ledger_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('l.farm_id', $this->context->farmId())
            ->when($data['as_of'] ?? null, fn ($q, $d) => $q->whereDate('e.posted_on', '<=', $d))
            ->groupBy('l.account_id')
            ->select('l.account_id', DB::raw('SUM(l.debit) AS debit'), DB::raw('SUM(l.credit) AS credit'))
            ->get()->keyBy('account_id');

        $rows = LedgerAccount::orderBy('code')->get()->map(function (LedgerAccount $a) use ($totals) {
            $t = $totals->get($a->id);
            $debit = Money::cents($t->debit ?? 0);
            $credit = Money::cents($t->credit ?? 0);

            return [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'is_system' => $a->is_system,
                'debit' => (float) Money::fromCents($debit),
                'credit' => (float) Money::fromCents($credit),
                // Positive in the account's normal direction.
                'balance' => (float) Money::fromCents($a->isDebitNormal() ? $debit - $credit : $credit - $debit),
            ];
        });

        return response()->json([
            'data' => $rows->values(),
            'meta' => [
                'total_debit' => (float) Money::fromCents($rows->sum(fn ($r) => Money::cents($r['debit']))),
                'total_credit' => (float) Money::fromCents($rows->sum(fn ($r) => Money::cents($r['credit']))),
                'as_of' => $data['as_of'] ?? null,
            ],
        ]);
    }

    public function entries(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter.source_type' => ['sometimes', 'string', 'max:40'],
            'filter.source_id' => ['sometimes', 'uuid'],
            'filter.account_id' => ['sometimes', 'uuid'],
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $f = $data['filter'] ?? [];

        return LedgerEntryResource::collection(LedgerEntry::with(['lines.account', 'reversal', 'poster'])
            ->when($f['source_type'] ?? null, fn ($q, $v) => $q->where('source_type', $v))
            ->when($f['source_id'] ?? null, fn ($q, $v) => $q->where('source_id', $v))
            ->when($f['account_id'] ?? null, fn ($q, $v) => $q->whereHas('lines', fn ($q) => $q->where('account_id', $v)))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('posted_on', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('posted_on', '<=', $v))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50)));
    }

    public function show(string $farm, LedgerEntry $entry): LedgerEntryResource
    {
        return new LedgerEntryResource($entry->load(['lines.account', 'reversal', 'poster']));
    }

    public function reverse(Request $request, string $farm, LedgerEntry $entry): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        $reversal = $this->ledger->reverse($entry, $data['reason']);

        return (new LedgerEntryResource($reversal->load(['lines.account', 'poster'])))->response()->setStatusCode(201);
    }
}
