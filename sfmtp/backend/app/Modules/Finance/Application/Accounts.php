<?php

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Finance\Domain\Models\LedgerAccount;
use App\Support\Http\ApiException;

/**
 * The chart of accounts: the starter accounts plus the farm's own. Codes
 * are four digits and start with the type's digit (1 assets … 5 expenses);
 * the code and type of a system account never change.
 */
class Accounts
{
    private const TYPE_DIGIT = ['asset' => '1', 'liability' => '2', 'equity' => '3', 'income' => '4', 'expense' => '5'];

    public function __construct(private readonly AuditLogger $audit, private readonly ChartOfAccounts $chart) {}

    public function create(array $data): LedgerAccount
    {
        $this->chart->ensure();
        if (! str_starts_with($data['code'], self::TYPE_DIGIT[$data['type']])) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['code' => [ucfirst($data['type']).' account codes start with '.self::TYPE_DIGIT[$data['type']].'.']]);
        }
        if (LedgerAccount::where('code', $data['code'])->exists()) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['code' => ["Account {$data['code']} already exists."]]);
        }
        if (! empty($data['is_cash']) && $data['type'] !== 'asset') {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['is_cash' => ['Only asset accounts hold money.']]);
        }
        $account = LedgerAccount::create($data + ['is_system' => false]);
        $this->audit->record('finance.account.created', $account, null, $account->only(['code', 'name', 'type', 'is_cash']));

        return $account->refresh();
    }

    public function update(LedgerAccount $account, array $data): LedgerAccount
    {
        if ($account->is_system && isset($data['is_cash']) && $data['is_cash'] !== $account->is_cash) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['is_cash' => ['System accounts keep their setting.']]);
        }
        if (isset($data['is_cash']) && $data['is_cash'] && $account->type !== 'asset') {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['is_cash' => ['Only asset accounts hold money.']]);
        }
        if (isset($data['is_active']) && ! $data['is_active'] && $account->is_system) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['is_active' => ['System accounts stay active.']]);
        }
        $old = $account->only(array_keys($data));
        $account->fill($data)->save();
        $this->audit->record('finance.account.updated', $account, $old, $data);

        return $account;
    }

    /** An active account of one of the types, for a document field. */
    public function ofType(?string $id, array $types, string $field, bool $allowControl = false): LedgerAccount
    {
        $this->chart->ensure();
        $account = $id ? LedgerAccount::find($id) : null;
        $label = implode(' or ', $types);
        if (! $account || ! in_array($account->type, $types, true)) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => ["Choose an {$label} account of this farm."]]);
        }
        if (! $account->is_active) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => ["{$account->code} {$account->name} is inactive."]]);
        }
        if (! $allowControl && $account->isControl()) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => ["{$account->code} {$account->name} is kept by its documents."]]);
        }

        return $account;
    }

    /** An active money account (cash, mobile money, bank). */
    public function money(?string $id, string $field): LedgerAccount
    {
        $account = $this->ofType($id, ['asset'], $field);
        if (! $account->is_cash) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => ['Choose a cash, mobile money or bank account.']]);
        }

        return $account;
    }
}
