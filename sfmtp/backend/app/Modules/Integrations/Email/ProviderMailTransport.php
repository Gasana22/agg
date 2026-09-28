<?php

namespace App\Modules\Integrations\Email;

use App\Modules\Integrations\Application\IntegrationUnavailable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

/**
 * The `providers` mailer: Laravel mail and notifications go out through the
 * email providers set in the admin portal. With none configured, messages are
 * written to the log (a development default) instead of being lost silently.
 */
class ProviderMailTransport extends AbstractTransport
{
    public function __construct(private readonly EmailGateway $gateway)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        try {
            $this->gateway->send($email);
        } catch (IntegrationUnavailable $e) {
            if ($e->errors === []) {
                Log::info('Email (no email provider configured)', ['to' => array_map(fn ($a) => $a->getAddress(), $email->getTo()), 'subject' => $email->getSubject()]);

                return;
            }
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    public function __toString(): string
    {
        return 'providers';
    }
}
