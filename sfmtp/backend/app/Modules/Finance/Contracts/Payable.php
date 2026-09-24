<?php

namespace App\Modules\Finance\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A kind of document a payment can settle: a customer invoice (money in),
 * a supplier invoice, an approved expense or a payroll run (money out).
 * Modules register their documents with Payables, so Finance needs no
 * knowledge of Sales or Procurement (docs/09).
 */
interface Payable
{
    /** 'in' or 'out'. */
    public function direction(): string;

    /** The control account the payment clears (receivables, payables, wages payable). */
    public function settlementAccount(): string;

    /** The permission (or `a|b` alternatives) needed to record a payment for it. */
    public function permission(): string;

    /** The document, locked for update; null when it does not exist in this farm. */
    public function lock(string $id): ?Model;

    /** What is still to pay, in cents; zero or less when nothing can be paid (e.g. not issued yet). */
    public function outstanding(Model $document): int;

    public function code(Model $document): string;

    public function party(Model $document): ?string;

    /** Add (or, when voiding a payment, take back) a paid amount in cents and update the status. */
    public function apply(Model $document, int $cents): void;
}
