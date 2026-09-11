<?php

namespace App\Models;

use App\Enums\MediaCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A general-purpose file attachment (photo, PDF, certificate, invoice, ...)
 * on any of a curated set of entities (see DocumentController::TYPES).
 * Distinct from the one-off, single-purpose photo fields already on
 * Attendance and DailyTask — those record one specific required moment
 * (a check-in, a completed task); this is for everything else a farm
 * needs to keep on file.
 */
class Document extends Model
{
    protected $fillable = [
        'farm_id',
        'documentable_type',
        'documentable_id',
        'category',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'description',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => MediaCategory::class,
            'file_size' => 'integer',
        ];
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function documentable()
    {
        return $this->morphTo();
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }
}
