<?php

namespace App\Modules\Integrations\Email;

use App\Modules\Integrations\Application\ProviderConfig;
use App\Modules\Integrations\Application\ProviderFailure;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * SendGrid Web API v3 (`POST /v3/mail/send`). Settings: `api_key`, and
 * `from_address` / `from_name` for a verified sender.
 */
class SendGridEmail implements EmailAdapter
{
    public function send(ProviderConfig $config, Email $email): string
    {
        Sender::apply($config, $email);
        $address = fn (Address $a) => array_filter(['email' => $a->getAddress(), 'name' => $a->getName() ?: null]);
        $content = array_values(array_filter([
            $email->getTextBody() !== null ? ['type' => 'text/plain', 'value' => (string) $email->getTextBody()] : null,
            $email->getHtmlBody() !== null ? ['type' => 'text/html', 'value' => (string) $email->getHtmlBody()] : null,
        ]));
        $payload = array_filter([
            'personalizations' => [array_filter([
                'to' => array_map($address, $email->getTo()),
                'cc' => array_map($address, $email->getCc()) ?: null,
                'bcc' => array_map($address, $email->getBcc()) ?: null,
            ])],
            'from' => $address($email->getFrom()[0]),
            'reply_to' => isset($email->getReplyTo()[0]) ? $address($email->getReplyTo()[0]) : null,
            'subject' => (string) $email->getSubject(),
            'content' => $content ?: [['type' => 'text/plain', 'value' => ' ']],
            'attachments' => array_map(fn ($part) => [
                'content' => base64_encode($part->getBody()),
                'filename' => $part->getFilename() ?? 'attachment',
                'type' => $part->getMediaType().'/'.$part->getMediaSubtype(),
            ], $email->getAttachments()) ?: null,
        ]);

        $res = Http::withToken($config->require('api_key'))->acceptJson()->timeout(15)->post('https://api.sendgrid.com/v3/mail/send', $payload);
        if ($res->status() === 202) {
            return (string) $res->header('X-Message-Id');
        }

        throw new ProviderFailure("SendGrid answered HTTP {$res->status()}: ".mb_substr((string) $res->json('errors.0.message'), 0, 200), retryable: true, status: $res->status());
    }
}
