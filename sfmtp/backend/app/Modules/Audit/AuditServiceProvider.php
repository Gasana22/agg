<?php

namespace App\Modules\Audit;

use App\Modules\Audit\Listeners\AuditIdentityEvents;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::subscribe(AuditIdentityEvents::class);
    }
}
