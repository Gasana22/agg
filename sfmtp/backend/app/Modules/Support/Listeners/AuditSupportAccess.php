<?php

namespace App\Modules\Support\Listeners;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Domain\Events\SupportAccessUsed;

/** Every request made under a support grant lands in the farm's audit log. */
class AuditSupportAccess
{
    public function handle(SupportAccessUsed $event): void
    {
        app(AuditLogger::class)->record('support.request', ['type' => 'support_access_grant', 'id' => $event->grantId], null, [
            'method' => $event->method,
            'path' => $event->path,
        ], ['farm_id' => $event->farmId, 'user_id' => $event->userId]);
    }
}
