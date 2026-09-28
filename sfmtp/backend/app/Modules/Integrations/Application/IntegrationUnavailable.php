<?php

namespace App\Modules\Integrations\Application;

use RuntimeException;

/** No provider of a kind could do the job: none configured, or all failed. */
class IntegrationUnavailable extends RuntimeException
{
    /** @param  array<string, string>  $errors provider name => last error */
    public function __construct(public readonly string $kind, public readonly array $errors = [])
    {
        parent::__construct($errors === [] ? "No {$kind} provider is configured." : "Every {$kind} provider failed: ".implode('; ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($errors), $errors)));
    }
}
