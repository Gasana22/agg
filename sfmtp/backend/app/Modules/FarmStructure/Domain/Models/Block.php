<?php

namespace App\Modules\FarmStructure\Domain\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Block extends StructureNode
{
    protected $table = 'farm_blocks';

    protected $fillable = ['farm_id', 'code', 'name', 'description', 'declared_area_ha', 'created_by'];

    public function nodeType(): string
    {
        return 'block';
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }
}
