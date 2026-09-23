<?php

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Application\SubscriptionService;
use App\Modules\Tenancy\Domain\Events\OrganizationCreated;

class StartTrial
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function handle(OrganizationCreated $event): void
    {
        $this->subscriptions->startTrial($event->organization);
    }
}
