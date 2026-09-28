<?php

namespace App\Modules\Integrations\Contracts;

use App\Modules\Integrations\Domain\Models\OnlinePayment;

/**
 * What an online payment pays for. The owning module records the money in
 * its own books once the gateway confirms it, so Integrations depends on
 * neither Billing nor Sales. Implementations are tagged
 * `sfmtp.payment-purposes`.
 */
interface PaymentPurpose
{
    public function key(): string;

    /**
     * Record a confirmed payment. Called once per payment (under a lock), but
     * must still be safe to repeat: the gateway reference identifies it.
     */
    public function fulfil(OnlinePayment $payment): void;
}
