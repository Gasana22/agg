<?php

namespace App\Modules\Finance;

use App\Modules\Finance\Domain\Models\LedgerEntry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FinanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::model('entry', LedgerEntry::class);
    }
}
