<?php

namespace App\Modules\Notifications\Contracts;

/** Delivers a push message to one device token. */
interface PushSender
{
    /**
     * @param  array<string, string>  $data
     * @return bool false when the token is no longer valid (it is then forgotten)
     */
    public function send(string $token, string $title, ?string $body, array $data): bool;
}
