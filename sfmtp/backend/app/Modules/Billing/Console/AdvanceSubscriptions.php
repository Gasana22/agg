<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Application\SubscriptionService;
use Illuminate\Console\Command;

class AdvanceSubscriptions extends Command
{
    protected $signature = 'billing:advance-subscriptions';

    protected $description = 'Move ended subscriptions into grace, and expired grace periods into suspension (run daily).';

    public function handle(SubscriptionService $subscriptions): int
    {
        $counts = $subscriptions->advance();
        $this->info(sprintf('Grace started: %d · suspended: %d · cancelled: %d', $counts['grace'], $counts['suspended'], $counts['cancelled']));

        return self::SUCCESS;
    }
}
