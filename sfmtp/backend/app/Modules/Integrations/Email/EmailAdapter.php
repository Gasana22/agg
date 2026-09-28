<?php

namespace App\Modules\Integrations\Email;

use App\Modules\Integrations\Application\ProviderConfig;
use Symfony\Component\Mime\Email;

interface EmailAdapter
{
    /** @return string the provider's message id, or '' when it gives none */
    public function send(ProviderConfig $config, Email $email): string;
}
