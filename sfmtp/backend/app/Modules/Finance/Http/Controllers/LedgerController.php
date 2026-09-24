<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\Accounts;
use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Finance\Application\CostCenters;
use App\Modules\Finance\Application\Ledger;
use App\Modules\Finance\Application\Money;
use App\Modules\Finance\Domain\Models\LedgerAccount;
use App\Modules\Finance\Domain\Models\LedgerEntry;
use App\Modules\Finance\Http\Resources\LedgerEntryResource;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LedgerController
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly ChartOfAccounts $chart,
        private readonly TenantContext $context,
        private readonly Accounts $accountsService,
        private readonly CostCenters $centers,
    ) {}

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
                'description' => $a->description,
                'is_system' => $a->is_system,
                'is_control' => $a->isControl(),
                'is_cash' => $a->is_cash,
                'is_active' => $a->is_active,
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

    public function storeAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[1-5][0-9]{3}$/'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'is_cash' => ['sometimes', 'boolean'],
        ]);
        $account = $this->accountsService->create($data);

        return response()->json(['data' => $account->only(['id', 'code', 'name', 'type', 'description', 'is_cash', 'is_system', 'is_active'])], 201);
    }

    public function updateAccount(Request $request, string $farm, LedgerAccount $ledgerAccount): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'is_cash' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $account = $this->accountsService->update($ledgerAccount, $data);

        return response()->json(['data' => $account->only(['id', 'code', 'name', 'type', 'description', 'is_cash', 'is_system', 'is_active'])]);
    }

    /** A manual journal entry; control accounts are refused (ADR-0012). */
    public function storeEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'posted_on' => ['required', 'date', 'before_or_equal:today'],
            'memo' => ['required', 'string', 'min:3', 'max:300'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.debit' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.credit' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.cost_center_type' => ['sometimes', 'nullable', Rule::in([...CostCenters::types(), 'general'])],
            'lines.*.cost_center_id' => ['sometimes', 'nullable', 'uuid'],
            'lines.*.memo' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        foreach ($data['lines'] as $i => &$line) {
            [$line['cost_center_type'], $line['cost_center_id']] = $this->centers->resolve($line['cost_center_type'] ?? null, $line['cost_center_id'] ?? null, "lines.{$i}.cost_center_id");
        }
        unset($line);
        $entry = DB::transaction(fn () => $this->ledger->postManual($data['memo'], $data['lines'], CarbonImmutable::parse($data['posted_on'])));

        return (new LedgerEntryResource($entry->load(['lines.account', 'poster'])))->response()->setStatusCode(201);
    }

    public function reverse(Request $request, string $farm, LedgerEntry $entry): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        $reversal = $this->ledger->reverse($entry, $data['reason']);

        return (new LedgerEntryResource($reversal->load(['lines.account', 'poster'])))->response()->setStatusCode(201);
    }
}
