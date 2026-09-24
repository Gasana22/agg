<?php

namespace App\Modules\Finance\Application;

/** One side of a posting: an account code, an amount and an optional cost centre. */
final readonly class JournalLine
{
    public function __construct(
        public string $account,
        public string $debit = '0',
        public string $credit = '0',
        public ?string $costCenterType = null,
        public ?string $costCenterId = null,
        public ?string $memo = null,
    ) {}

    public static function debit(string $account, string|float $amount, ?string $costCenterType = null, ?string $costCenterId = null, ?string $memo = null): self
    {
        return new self($account, Money::of($amount), '0', $costCenterType, $costCenterId, $memo);
    }

    public static function credit(string $account, string|float $amount, ?string $costCenterType = null, ?string $costCenterId = null, ?string $memo = null): self
    {
        return new self($account, '0', Money::of($amount), $costCenterType, $costCenterId, $memo);
    }
}
