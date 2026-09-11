<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
        'farm_id',
        'category',
        'description',
        'amount',
        'date',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'amount' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
