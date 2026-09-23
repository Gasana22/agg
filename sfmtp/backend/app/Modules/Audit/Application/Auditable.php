<?php

namespace App\Modules\Audit\Application;

use Illuminate\Support\Str;

/**
 * Model trait: records created / updated / deleted with old and new values.
 * Models can list attributes to leave out in $auditExclude.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            app(AuditLogger::class)->record($model->auditAction('created'), $model, null, $model->auditValues($model->getAttributes()));
        });

        static::updated(function ($model) {
            $changes = $model->auditValues($model->getChanges());
            unset($changes['updated_at']);
            if ($changes === []) {
                return;
            }
            $old = array_intersect_key($model->auditValues($model->getOriginal()), $changes);
            app(AuditLogger::class)->record($model->auditAction('updated'), $model, $old, $changes);
        });

        static::deleted(function ($model) {
            app(AuditLogger::class)->record($model->auditAction('deleted'), $model, $model->auditValues($model->getOriginal()), null);
        });
    }

    protected function auditAction(string $event): string
    {
        return Str::snake(class_basename($this)).'.'.$event;
    }

    protected function auditValues(array $attributes): array
    {
        $values = array_diff_key($attributes, array_flip(array_merge(
            $this->auditExclude ?? [],
            $this->getHidden(),
            ['created_at'],
        )));

        return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : ($v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v), $values);
    }
}
