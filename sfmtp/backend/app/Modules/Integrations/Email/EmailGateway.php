<?php

namespace App\Modules\Integrations\Email;

use App\Modules\Integrations\Application\ProviderConfig;
use App\Modules\Integrations\Application\ProviderFailure;
use App\Modules\Integrations\Application\Router;
use Symfony\Component\Mime\Email;

/** Sends an email through the configured providers with failover (ADR-0018). */
class EmailGateway
{
    public const ADAPTERS = ['smtp' => SmtpEmail::class, 'sendgrid' => SendGridEmail::class];

    public function __construct(private readonly Router $router) {}

    /** @return array{provider:string, message_id:string} */
    public function send(Email $email, ?string $onlyProviderId = null): array
    {
        [$id, $provider] = $this->router->run('email', function (ProviderConfig $c) use ($email) {
            $class = self::ADAPTERS[$c->provider] ?? throw new ProviderFailure("No email adapter for {$c->provider}.");

            // Each attempt gets its own copy: an adapter may set the sender.
            return app($class)->send($c, clone $email);
        }, $onlyProviderId);

        return ['provider' => $provider->provider, 'message_id' => $id];
    }
}
