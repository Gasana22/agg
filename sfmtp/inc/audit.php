<?php
/* Append-only audit trail (the database refuses updates and deletes on audit_logs). */

const MASKED_KEYS = ['password', 'secret', 'token', 'code', 'recovery_code'];

function mask_values(?array $values): ?array
{
    if ($values === null) {
        return null;
    }
    foreach ($values as $k => $v) {
        if (is_string($k) && in_array(strtolower($k), MASKED_KEYS, true)) {
            $values[$k] = '***';
        } elseif (is_array($v)) {
            $values[$k] = mask_values($v);
        }
    }
    return $values;
}

/**
 * @param array{type:string,id:?string}|null $entity
 */
function audit(string $action, ?string $farmId = null, ?array $entity = null, ?array $old = null, ?array $new = null, ?string $userId = null): void
{
    if ($farmId === null && function_exists('current_farm') && current_user() && current_farm() && ($_SESSION['mfa_ok'] ?? false)) {
        $farmId = current_farm()['id'];
    }
    insert('audit_logs', [
        'id' => uuid(),
        'farm_id' => $farmId,
        'user_id' => $userId ?? ($_SESSION['uid'] ?? null),
        'action' => $action,
        'entity_type' => $entity['type'] ?? null,
        'entity_id' => $entity['id'] ?? null,
        'old_values' => $old === null ? null : json_encode(mask_values($old)),
        'new_values' => $new === null ? null : json_encode(mask_values($new)),
        'ip' => client_ip() ?: null,
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 250) : null,
        'created_at' => gmdate('Y-m-d H:i:s.u'),
    ]);
}
