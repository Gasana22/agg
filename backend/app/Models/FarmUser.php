<?php

namespace App\Models;

use App\Enums\FarmRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

class FarmUser extends Pivot
{
    protected $table = 'farm_user';

    protected function casts(): array
    {
        return [
            'role_on_farm' => FarmRole::class,
        ];
    }
}
