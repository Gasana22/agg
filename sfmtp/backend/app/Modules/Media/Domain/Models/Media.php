<?php

namespace App\Modules\Media\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stored file, identified within the farm by its SHA-256.
 *
 * @property string $id
 * @property string $sha256
 * @property string $mime
 * @property int $size_bytes
 * @property string $disk
 * @property string $path
 * @property string|null $uploaded_by
 */
class Media extends Model
{
    use BelongsToFarm, HasUuids, Immutable;

    public const UPDATED_AT = null;

    protected $table = 'media';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['farm_id', 'sha256', 'mime', 'size_bytes', 'disk', 'path', 'original_name', 'uploaded_by'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
