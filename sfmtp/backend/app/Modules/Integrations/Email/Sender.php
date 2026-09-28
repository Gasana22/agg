<?php

namespace App\Modules\Integrations\Email;

use App\Modules\Integrations\Application\ProviderConfig;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** A provider's own sender address, when set, replaces the application default. */
final class Sender
{
    public static function apply(ProviderConfig $config, Email $email): void
    {
        if ($config->get('from_address')) {
            $email->from(new Address($config->require('from_address'), $config->get('from_name') ?? ($email->getFrom()[0] ?? null)?->getName() ?? ''));
        }
    }
}
