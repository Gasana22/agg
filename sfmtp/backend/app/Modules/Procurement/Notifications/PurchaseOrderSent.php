<?php

namespace App\Modules\Procurement\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a supplier's portal users that a farm sent them an order. */
class PurchaseOrderSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $farmName,
        public string $orderCode,
        public string $partyId,
        public string $farmId,
        public string $orderId,
        public ?string $expectedOn,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New purchase order {$this->orderCode} from {$this->farmName}")
            ->line("{$this->farmName} sent you purchase order {$this->orderCode}".($this->expectedOn ? ", wanted by {$this->expectedOn}." : '.'))
            ->action('Open the order', config('sfmtp.web_url')."/supplier/{$this->partyId}/orders/{$this->farmId}/{$this->orderId}")
            ->line('Accept it with the quantities you can supply, or tell the farm you cannot.');
    }
}
