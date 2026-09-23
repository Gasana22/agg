<?php

namespace App\Modules\Access\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The invitation email, carrying the one-time link. */
class FarmInvitationSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $farmName,
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
        return (new MailMessage)
            ->subject("{$this->inviterName} invited you to {$this->farmName} on SFMTP")
            ->line("{$this->inviterName} invited you to join {$this->farmName} on SFMTP.")
            ->when($this->note, fn (MailMessage $m) => $m->line('"'.$this->note.'"'))
            ->action('Accept the invitation', config('sfmtp.web_url').'/invite/'.$this->token)
            ->line("The link works until {$this->expiresOn}. If you were not expecting it, ignore this email.");
    }
}
