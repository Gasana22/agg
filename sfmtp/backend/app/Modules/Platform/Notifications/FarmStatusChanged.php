<?php

namespace App\Modules\Platform\Notifications;

use App\Modules\Tenancy\Domain\Models\Farm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the farm owner that SFMTP approved, suspended or reinstated their farm. */
class FarmStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Farm $farm, public string $status, public ?string $note = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('sfmtp.web_url')."/farms/{$this->farm->id}";

        return match ($this->status) {
            'active' => (new MailMessage)
                ->subject("{$this->farm->name} is approved")
                ->line("Your farm {$this->farm->name} ({$this->farm->code}) is now active on SFMTP.")
                ->action('Open your farm', $url),
            'suspended' => (new MailMessage)
                ->subject("{$this->farm->name} has been suspended")
                ->line("Access to {$this->farm->name} ({$this->farm->code}) has been suspended.")
                ->when($this->note, fn ($m) => $m->line("Reason: {$this->note}"))
                ->line('Your data is kept safe. Contact SFMTP support to resolve this.'),
            default => (new MailMessage)
                ->subject("{$this->farm->name} status update")
                ->line("The status of {$this->farm->name} is now {$this->status}.")
                ->action('Open your farm', $url),
        };
    }
}
