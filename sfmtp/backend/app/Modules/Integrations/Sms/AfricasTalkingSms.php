<?php

namespace App\Modules\Integrations\Sms;

use App\Modules\Integrations\Application\ProviderConfig;
use App\Modules\Integrations\Application\ProviderFailure;
use Illuminate\Support\Facades\Http;

/**
 * Africa's Talking bulk SMS (`POST /version1/messaging`). Settings:
 * `username`, `api_key`, optional `sender_id`. The username `sandbox` uses
 * the sandbox host.
 */
class AfricasTalkingSms implements SmsAdapter
{
    /** Per-recipient codes that another provider would not fix. */
    private const REFUSED = [403 => 'invalid phone number', 406 => 'recipient has blocked messages'];

    public function send(ProviderConfig $config, string $to, string $message): string
    {
        $username = $config->require('username');
        $host = $username === 'sandbox' ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com';
        $res = Http::asForm()->acceptJson()->timeout(10)
            ->withHeaders(['apiKey' => $config->require('api_key')])
            ->post("{$host}/version1/messaging", array_filter([
                'username' => $username, 'to' => $to, 'message' => $message, 'from' => $config->get('sender_id'),
            ]));
        if (! $res->successful()) {
            throw new ProviderFailure("Africa's Talking answered HTTP {$res->status()}", retryable: true, status: $res->status());
        }
        $recipient = $res->json('SMSMessageData.Recipients.0');
        if (! is_array($recipient)) {
            throw new ProviderFailure("Africa's Talking: ".($res->json('SMSMessageData.Message') ?? 'no recipient accepted'), retryable: true);
        }
        $code = (int) ($recipient['statusCode'] ?? 0);
        if (in_array($code, [100, 101, 102], true)) {
            return (string) $recipient['messageId'];
        }
        if (isset(self::REFUSED[$code])) {
            throw new ProviderFailure("Africa's Talking: ".self::REFUSED[$code], retryable: false, status: $code);
        }

        // Insufficient balance, could not route, provider errors: another provider may deliver.
        throw new ProviderFailure("Africa's Talking: ".($recipient['status'] ?? "status {$code}"), retryable: true, status: $code);
    }
}
