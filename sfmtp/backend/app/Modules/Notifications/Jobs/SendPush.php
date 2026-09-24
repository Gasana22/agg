<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Identity\Domain\Models\UserDevice;
use App\Modules\Notifications\Contracts\PushSender;
use App\Modules\Notifications\Domain\Models\MemberNotification;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Pushes new notifications to the members' signed-in phones. */
class SendPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    /** @param  array<int, string>  $notificationIds */
    public function __construct(public readonly string $farmId, public readonly array $notificationIds) {}

    public function handle(TenantContext $context, PushSender $sender): void
    {
        $farm = Farm::find($this->farmId);
        if (! $farm) {
            return;
        }
        $context->run($farm, function () use ($sender, $farm) {
            foreach (MemberNotification::whereIn('id', $this->notificationIds)->whereNull('pushed_at')->get() as $n) {
                $devices = UserDevice::where('user_id', $n->user_id)->where('client', 'mobile')->whereNull('revoked_at')->whereNotNull('push_token')->get();
                foreach ($devices as $device) {
                    $ok = $sender->send($device->push_token, $n->title, $n->body, ['notification_id' => $n->id, 'farm_id' => $farm->id, 'kind' => $n->kind] + array_map('strval', $n->data ?? []));
                    if (! $ok) {
                        $device->forceFill(['push_token' => null, 'push_platform' => null])->save();
                    }
                }
                $n->forceFill(['pushed_at' => now()])->save();
            }
        });
    }
}
