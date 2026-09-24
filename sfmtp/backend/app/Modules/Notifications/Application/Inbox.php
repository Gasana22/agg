<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Access\Application\FarmPermissions;
use App\Modules\Notifications\Domain\Models\MemberNotification;
use App\Modules\Notifications\Jobs\SendPush;
use App\Modules\Tenancy\Domain\Enums\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;

/**
 * Tells farm members about things that need them: an assigned or rejected
 * task, a change of theirs the server could not apply, a sync conflict to
 * resolve. Notifications land in the member's inbox (web and phone) and are
 * pushed to their phones after commit.
 */
class Inbox
{
    public function __construct(private readonly TenantContext $context, private readonly FarmPermissions $permissions) {}

    /**
     * @param  array<int, string>  $userIds
     * @param  array<string, string|int|null>  $data
     */
    public function notify(array $userIds, string $kind, string $title, ?string $body = null, ?string $link = null, array $data = []): void
    {
        $ids = [];
        foreach (array_unique(array_filter($userIds)) as $userId) {
            $ids[] = MemberNotification::create([
                'user_id' => $userId, 'kind' => $kind, 'title' => mb_substr($title, 0, 150), 'body' => $body ? mb_substr($body, 0, 500) : null,
                'link' => $link, 'data' => array_filter($data, fn ($v) => $v !== null) ?: null,
            ])->id;
        }
        if ($ids) {
            SendPush::dispatch($this->context->farmId(), $ids)->afterCommit();
        }
    }

    /** Everyone in the farm holding one of the permissions (`a|b`), except `$except`. */
    public function notifyHolders(string $permission, string $kind, string $title, ?string $body = null, ?string $link = null, array $data = [], ?string $except = null): void
    {
        $wanted = explode('|', $permission);
        $users = FarmUser::where('farm_id', $this->context->farmId())->where('status', MembershipStatus::Active->value)->get()
            ->filter(fn (FarmUser $m) => $m->user_id !== $except && array_intersect($wanted, array_keys($this->permissions->for($m))))
            ->pluck('user_id')->all();
        $this->notify($users, $kind, $title, $body, $link, $data);
    }
}
