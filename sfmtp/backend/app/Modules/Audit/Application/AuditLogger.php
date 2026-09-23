<?php

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes the "what did users do" trail (docs/07 §7). Sensitive values are
 * masked before they are stored.
 */
class AuditLogger
{
    private const MASKED = ['password', 'secret', 'token', 'refresh_token', 'access_token', 'code', 'recovery_code', 'push_token', 'mfa_token'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly Request $request,
    ) {}

    /**
     * @param  Model|array{type:string,id:?string}|null  $entity
     * @param  array{farm_id?:?string, user_id?:?string, latitude?:float, longitude?:float}  $meta
     */
    public function record(string $action, Model|array|null $entity = null, ?array $old = null, ?array $new = null, array $meta = []): AuditLog
    {
        [$entityType, $entityId] = match (true) {
            $entity instanceof Model => [Str::snake(class_basename($entity)), (string) $entity->getKey()],
            is_array($entity) => [$entity['type'], $entity['id'] ?? null],
            default => [null, null],
        };

        $farmId = array_key_exists('farm_id', $meta) ? $meta['farm_id'] : $this->resolveFarmId($entity);
        $userId = array_key_exists('user_id', $meta) ? $meta['user_id'] : $this->request->user()?->getAuthIdentifier();

        return AuditLog::create([
            'farm_id' => $farmId,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $old === null ? null : $this->mask($old),
            'new_values' => $new === null ? null : $this->mask($new),
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent() ? Str::limit($this->request->userAgent(), 250, '') : null,
            'device_id' => $this->request->attributes->get('device_id'),
            'request_id' => $this->request->attributes->get('request_id'),
            'latitude' => $meta['latitude'] ?? null,
            'longitude' => $meta['longitude'] ?? null,
            'created_at' => now(),
        ]);
    }

    private function resolveFarmId(Model|array|null $entity): ?string
    {
        if ($entity instanceof Farm) {
            return $entity->id;
        }
        if ($entity instanceof Model && $entity->getAttribute('farm_id')) {
            return $entity->getAttribute('farm_id');
        }

        return $this->context->hasFarm() ? $this->context->farmId() : null;
    }

    private function mask(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::MASKED, true)) {
                $values[$key] = '***';
            } elseif (is_array($value)) {
                $values[$key] = $this->mask($value);
            }
        }

        return $values;
    }
}
