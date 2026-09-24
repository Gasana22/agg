<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Contracts\PushSender;
use Illuminate\Support\Facades\Log;

/** Without push credentials: record what would be sent. Phones still get it on their next sync. */
class LogPushSender implements PushSender
{
    public function send(string $token, string $title, ?string $body, array $data): bool
    {
        Log::info('push (not configured)', ['token' => substr($token, 0, 12).'…', 'title' => $title, 'data' => $data]);

        return true;
    }
}
