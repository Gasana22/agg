<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Integrations\Application\IntegrationUnavailable;
use App\Modules\Integrations\Application\ProviderFailure;
use App\Modules\Integrations\Sms\SmsGateway;
use App\Modules\Notifications\Application\NoticeChannels;
use App\Modules\Notifications\Domain\Models\MemberNotification;
use App\Modules\Notifications\Mail\NoticeMail;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Email and SMS copies of important notices, on the channels each person chose. */
class SendNoticeCopies implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    /** @param  array<int, string>  $notificationIds */
    public function __construct(public readonly string $farmId, public readonly array $notificationIds) {}

    public function handle(TenantContext $context, SmsGateway $sms): void
    {
        $farm = Farm::find($this->farmId);
        if (! $farm) {
            return;
        }
        $context->run($farm, function () use ($farm, $sms) {
            $notices = MemberNotification::whereIn('id', $this->notificationIds)->whereNull('copied_at')->whereIn('kind', array_keys(NoticeChannels::COPIED))->get();
            $users = User::whereIn('id', $notices->pluck('user_id'))->get()->keyBy('id');
            foreach ($notices as $n) {
                $user = $users[$n->user_id] ?? null;
                foreach ($user ? NoticeChannels::for($user, $n->kind) : [] as $channel) {
                    try {
                        if ($channel === 'email') {
                            Mail::to($user->email)->send(new NoticeMail($n, $farm));
                        } else {
                            $link = $n->link ? ' '.rtrim(config('sfmtp.web_url'), '/').$n->link : '';
                            $sms->send($user->phone, "{$farm->name}: {$n->title}{$link}");
                        }
                    } catch (IntegrationUnavailable|ProviderFailure $e) {
                        // The inbox and push already carry the notice: a missed copy is logged, not retried forever.
                        Log::warning('Notice copy not sent', ['notification_id' => $n->id, 'channel' => $channel, 'error' => $e->getMessage()]);
                    } catch (Throwable $e) {
                        Log::warning('Notice copy failed', ['notification_id' => $n->id, 'channel' => $channel, 'error' => $e->getMessage()]);
                    }
                }
                $n->forceFill(['copied_at' => now()])->save();
            }
        });
    }
}
