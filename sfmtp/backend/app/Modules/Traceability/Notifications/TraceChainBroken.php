<?php

namespace App\Modules\Traceability\Notifications;

use App\Modules\Tenancy\Domain\Models\Farm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the farm owner that the nightly check found altered traceability data. */
class TraceChainBroken extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Farm $farm, public string $reason, public ?int $seq) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->farm->name}: traceability integrity check failed")
            ->line("The traceability history of {$this->farm->name} ({$this->farm->code}) no longer matches its hash chain ({$this->reason}".($this->seq ? " at event {$this->seq}" : '').').')
            ->line('Stored events were changed outside the application. Contact SFMTP support, and do not publish new QR codes until it is resolved.')
            ->action('Open traceability', config('sfmtp.web_url')."/farms/{$this->farm->id}/traceability");
    }
}
