<?php

namespace App\Modules\Parties\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The portal invitation email, carrying the one-time link. */
class PortalInvitationSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $farmName,
        public string $kind,
        public string $recordName,
        public string $inviterName,
        public string $token,
        public string $expiresOn,
        public ?string $note = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $what = $this->kind === 'supplier'
            ? 'see their purchase orders, confirm deliveries and send invoices'
            : 'order from them, follow deliveries and see invoices';

        return (new MailMessage)
            ->subject("{$this->farmName} invited {$this->recordName} to the SFMTP {$this->kind} portal")
            ->line("{$this->inviterName} of {$this->farmName} invited {$this->recordName} to the SFMTP {$this->kind} portal, where you can {$what}.")
            ->when($this->note, fn (MailMessage $m) => $m->line('"'.$this->note.'"'))
            ->action('Open the portal', config('sfmtp.web_url').'/portal-invite/'.$this->token)
            ->line("The link works until {$this->expiresOn}. If you were not expecting it, ignore this email.");
    }
}
