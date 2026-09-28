<?php

namespace App\Modules\Integrations\Application;

use RuntimeException;

/**
 * A provider call that failed. Retryable failures (outages, timeouts, bad
 * credentials, 5xx) count against the provider's health and move on to the
 * next provider; others (the request itself was refused, e.g. an invalid
 * phone number) stop there, since another provider would refuse it too.
 */
class ProviderFailure extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = true, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
