<?php

namespace App\Modules\Notifications;

use App\Modules\Notifications\Application\FcmPushSender;
use App\Modules\Notifications\Application\LogPushSender;
use App\Modules\Notifications\Contracts\PushSender;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // FCM when a service account is configured, otherwise the log (phones still see the inbox on sync).
        $this->app->singleton(PushSender::class, fn () => FcmPushSender::fromConfig() ?? new LogPushSender);
    }
}
