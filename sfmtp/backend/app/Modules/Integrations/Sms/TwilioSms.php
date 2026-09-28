<?php

namespace App\Modules\Integrations\Sms;

use App\Modules\Integrations\Application\ProviderConfig;
use App\Modules\Integrations\Application\ProviderFailure;
use Illuminate\Support\Facades\Http;

/**
 * Twilio Programmable Messaging (`POST /2010-04-01/Accounts/{sid}/Messages.json`).
 * Settings: `account_sid`, `auth_token`, and `from` (a number or sender id)
 * or `messaging_service_sid`.
 */
class TwilioSms implements SmsAdapter
{
    /** Twilio error codes about the recipient or content, not the account. */
    private const REFUSED = [21211, 21214, 21217, 21408, 21610, 21612, 21614, 21617];

    public function send(ProviderConfig $config, string $to, string $message): string
    {
        $sid = $config->require('account_sid');
        $from = $config->get('messaging_service_sid') ? ['MessagingServiceSid' => $config->get('messaging_service_sid')] : ['From' => $config->require('from')];
        $res = Http::asForm()->acceptJson()->timeout(10)
            ->withBasicAuth($sid, $config->require('auth_token'))
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", ['To' => $to, 'Body' => $message] + $from);
        if ($res->successful()) {
            return (string) $res->json('sid');
        }
        $code = (int) $res->json('code');
        if ($res->status() === 400 && in_array($code, self::REFUSED, true)) {
            throw new ProviderFailure('Twilio: '.($res->json('message') ?? "error {$code}"), retryable: false, status: $code);
        }

        throw new ProviderFailure("Twilio answered HTTP {$res->status()}".($code ? " (error {$code})" : ''), retryable: true, status: $res->status());
    }
}
