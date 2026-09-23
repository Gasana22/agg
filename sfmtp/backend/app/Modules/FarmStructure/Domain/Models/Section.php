<?php

namespace App\Modules\FarmStructure\Domain\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $block_id
 */
class Section extends StructureNode
{
    protected $table = 'farm_sections';

    protected $fillable = ['farm_id', 'block_id', 'code', 'name', 'description', 'declared_area_ha', 'created_by'];

    public function nodeType(): string
    {
        return 'section';
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function plots(): HasMany
    {
        return $this->hasMany(Plot::class);
    }
}
