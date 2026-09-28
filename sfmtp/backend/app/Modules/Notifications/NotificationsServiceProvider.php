<?php

namespace App\Modules\Notifications;

use App\Modules\Integrations\Contracts\ProviderDirectory;
use App\Modules\Notifications\Application\FcmPushSender;
use App\Modules\Notifications\Application\LogPushSender;
use App\Modules\Notifications\Contracts\PushSender;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // FCM from the admin portal's push provider, else from a credentials file, else the log
        // (phones still see the inbox on sync). Resolved per use, so a changed provider applies at once.
        $this->app->bind(PushSender::class, function ($app) {
            foreach ($app->make(ProviderDirectory::class)->candidates('push') as $provider) {
                if ($provider->provider === 'fcm' && ($sender = FcmPushSender::fromJson($provider->get('service_account')))) {
                    return $sender;
                }
            }

            return FcmPushSender::fromConfig() ?? new LogPushSender;
        });
    }
}
