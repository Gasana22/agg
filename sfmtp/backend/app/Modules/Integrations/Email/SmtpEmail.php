<?php

namespace App\Modules\Integrations\Email;

use App\Modules\Integrations\Application\ProviderConfig;
use App\Modules\Integrations\Application\ProviderFailure;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * Any SMTP server. Settings: `host`, `port` (587), `username`, `password`,
 * `encryption` (`tls` for implicit TLS on 465; otherwise STARTTLS when
 * offered), optional `from_address` / `from_name`.
 */
class SmtpEmail implements EmailAdapter
{
    public function send(ProviderConfig $config, Email $email): string
    {
        $port = (int) ($config->get('port') ?? 587);
        $transport = new EsmtpTransport($config->require('host'), $port, $config->get('encryption') === 'tls' || $port === 465 ? true : null);
        if ($config->get('username')) {
            $transport->setUsername($config->require('username'));
            $transport->setPassword($config->get('password') ?? '');
        }
        Sender::apply($config, $email);
        try {
            return (string) $transport->send($email)?->getMessageId();
        } catch (TransportExceptionInterface $e) {
            throw new ProviderFailure('SMTP: '.mb_substr($e->getMessage(), 0, 200), retryable: true);
        }
    }
}
