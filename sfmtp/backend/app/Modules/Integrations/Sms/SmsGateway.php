<?php

namespace App\Modules\Integrations\Sms;

use App\Modules\Integrations\Application\ProviderConfig;
use App\Modules\Integrations\Application\ProviderFailure;
use App\Modules\Integrations\Application\Router;

/**
 * Sends a text message through the configured SMS providers, failing over
 * from one to the next (ADR-0018). Numbers are normalised to E.164; a local
 * number (leading 0) takes the default country code.
 */
class SmsGateway
{
    public const ADAPTERS = ['africas_talking' => AfricasTalkingSms::class, 'twilio' => TwilioSms::class];

    public function __construct(private readonly Router $router) {}

    /** @return array{provider:string, message_id:string} */
    public function send(string $to, string $message, ?string $onlyProviderId = null): array
    {
        $number = self::normalize($to) ?? throw new ProviderFailure("\"{$to}\" is not a phone number.", retryable: false);
        $text = mb_substr(trim($message), 0, 459);   // three SMS parts at most

        [$id, $provider] = $this->router->run('sms', fn (ProviderConfig $c) => $this->adapter($c)->send($c, $number, $text), $onlyProviderId);

        return ['provider' => $provider->provider, 'message_id' => $id];
    }

    public static function normalize(?string $phone, string $defaultCountry = '+256'): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', (string) $phone);
        if ($digits === '' || $digits === null) {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = $defaultCountry.substr($digits, 1);
        } elseif (! str_starts_with($digits, '+')) {
            $digits = '+'.$digits;
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $digits) ? $digits : null;
    }

    private function adapter(ProviderConfig $config): SmsAdapter
    {
        $class = self::ADAPTERS[$config->provider] ?? throw new ProviderFailure("No SMS adapter for {$config->provider}.");

        return app($class);
    }
}
