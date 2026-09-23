<?php

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Domain\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Emails the organization owner about subscription changes that affect access. */
class SubscriptionNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Subscription $subscription, public string $event) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->subscription;
        $url = config('sfmtp.web_url').'/billing';

        return match ($this->event) {
            'grace_started' => (new MailMessage)
                ->subject('Your SFMTP subscription needs renewal')
                ->line("Your {$s->plan->name} subscription ended on {$s->current_period_end->toFormattedDateString()}.")
                ->line("Your farms stay open until {$s->grace_until?->toFormattedDateString()}. Renew before then to avoid interruption.")
                ->action('Renew', $url),
            'suspended' => (new MailMessage)
                ->subject('Your SFMTP farms are paused')
                ->line('Your subscription was not renewed, so access to your farms is paused. Your data is kept safe.')
                ->action('Renew to restore access', $url),
            'payment_received' => (new MailMessage)
                ->subject('Payment received — thank you')
                ->line("Your {$s->plan->name} subscription is active until {$s->current_period_end->toFormattedDateString()}."),
            default => (new MailMessage)
                ->subject('Your SFMTP subscription changed')
                ->line("Status: {$s->status->value}. Plan: {$s->plan->name}.")
                ->action('View subscription', $url),
        };
    }
}
