<?php

namespace App\Modules\Notifications\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MemberNotification extends Model
{
    use BelongsToFarm, HasUuids;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['farm_id', 'user_id', 'kind', 'title', 'body', 'link', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime', 'pushed_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function toArrayForMember(): array
    {
        return [
            'id' => $this->id,
            'type' => 'notification',
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
            'data' => $this->data ?? (object) [],
            'read_at' => $this->read_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString('microsecond'),
        ];
    }
}
