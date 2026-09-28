<?php

namespace App\Modules\Integrations\Sms;

use App\Modules\Integrations\Application\ProviderConfig;

interface SmsAdapter
{
    /** @return string the provider's message id */
    public function send(ProviderConfig $config, string $to, string $message): string;
}
