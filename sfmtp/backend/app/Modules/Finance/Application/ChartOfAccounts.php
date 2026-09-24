<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Models\LedgerAccount;
use App\Support\Http\ApiException;

/**
 * The starter chart of accounts, created for a farm the first time it posts.
 * System accounts are the ones automatic postings use; their code and type
 * never change. Control accounts carry a sub-ledger (stock, receivables,
 * payables, wages): only their documents post to them, never a manual
 * entry, so the account always equals its documents (ADR-0011, ADR-0012).
 * Accountants add their own accounts beside these.
 */
final class ChartOfAccounts
{
    public const CASH = '1000';

    public const MOBILE_MONEY = '1010';

    public const RECEIVABLES = '1200';

    public const INVENTORY = '1300';

    public const PAYABLES = '2000';

    public const GOODS_RECEIVED_NOT_INVOICED = '2100';

    public const EQUITY = '3000';

    public const OPENING_BALANCES = '3100';

    public const SALES = '4000';

    public const INPUTS_USED = '5000';

    public const STOCK_ADJUSTMENTS = '5100';

    public const PRICE_VARIANCE = '5200';

    public const WAGES_PAYABLE = '2200';

    public const DEDUCTIONS_PAYABLE = '2210';

    public const OTHER_INCOME = '4100';

    public const WAGES = '5300';

    /** Accounts only their documents post to. */
    public const CONTROL = [self::RECEIVABLES, self::INVENTORY, self::PAYABLES, self::GOODS_RECEIVED_NOT_INVOICED, self::WAGES_PAYABLE, self::DEDUCTIONS_PAYABLE];

    /** Money accounts in the starter chart. */
    public const CASH_ACCOUNTS = [self::CASH, self::MOBILE_MONEY];

    /** code => [name, type] */
    public const ACCOUNTS = [
        self::CASH => ['Cash', 'asset'],
        self::MOBILE_MONEY => ['Mobile money and bank', 'asset'],
        self::RECEIVABLES => ['Accounts receivable', 'asset'],
        self::INVENTORY => ['Inventory', 'asset'],
        self::PAYABLES => ['Accounts payable', 'liability'],
        self::GOODS_RECEIVED_NOT_INVOICED => ['Goods received, not invoiced', 'liability'],
        self::EQUITY => ["Owner's equity", 'equity'],
        self::OPENING_BALANCES => ['Opening balances', 'equity'],
        self::SALES => ['Sales', 'income'],
        self::INPUTS_USED => ['Inputs used (feed, seed, fertilizer, drugs …)', 'expense'],
        self::STOCK_ADJUSTMENTS => ['Stock adjustments and losses', 'expense'],
        self::PRICE_VARIANCE => ['Purchase price variance', 'expense'],
        self::WAGES_PAYABLE => ['Wages payable', 'liability'],
        self::DEDUCTIONS_PAYABLE => ['Payroll deductions payable', 'liability'],
        self::OTHER_INCOME => ['Other income', 'income'],
        self::WAGES => ['Wages and casual labour', 'expense'],
    ];

    /** Common accounts a farm starts with; editable, and not used by automatic postings. code => [name, type] */
    public const STARTER = [
        '4200' => ['Livestock and produce sales', 'income'],
        '5400' => ['Fuel and transport', 'expense'],
        '5500' => ['Veterinary and agronomy services', 'expense'],
        '5600' => ['Repairs and maintenance', 'expense'],
        '5700' => ['Utilities and rent', 'expense'],
        '5900' => ['Other expenses', 'expense'],
    ];

    /** Create any missing starter accounts for the current farm. */
    public function ensure(): void
    {
        $existing = LedgerAccount::pluck('code')->all();
        foreach ([[self::ACCOUNTS, true], [self::STARTER, false]] as [$accounts, $system]) {
            foreach ($accounts as $code => [$name, $type]) {
                if (! in_array((string) $code, $existing, true)) {
                    LedgerAccount::create(['code' => (string) $code, 'name' => $name, 'type' => $type, 'is_system' => $system,
                        'is_cash' => in_array((string) $code, self::CASH_ACCOUNTS, true)]);
                }
            }
        }
    }

    public function account(string $code): LedgerAccount
    {
        $account = LedgerAccount::where('code', $code)->first();
        if (! $account) {
            $this->ensure();
            $account = LedgerAccount::where('code', $code)->first() ?? throw new ApiException(500, 'ledger_account_missing', "Ledger account {$code} is missing.");
        }

        return $account;
    }
}
