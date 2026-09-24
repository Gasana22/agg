<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Models\LedgerAccount;
use App\Support\Http\ApiException;

/**
 * The starter chart of accounts, created for a farm the first time it posts.
 * System accounts are the ones automatic postings use; owners and
 * accountants add their own accounts in Phase 8.
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
    ];

    /** Create any missing starter accounts for the current farm. */
    public function ensure(): void
    {
        $existing = LedgerAccount::pluck('code')->all();
        foreach (self::ACCOUNTS as $code => [$name, $type]) {
            if (! in_array((string) $code, $existing, true)) {
                LedgerAccount::create(['code' => (string) $code, 'name' => $name, 'type' => $type, 'is_system' => true]);
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
