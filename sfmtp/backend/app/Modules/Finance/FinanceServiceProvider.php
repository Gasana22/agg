<?php

namespace App\Modules\Finance;

use App\Modules\Finance\Application\Expenses;
use App\Modules\Finance\Application\Payables;
use App\Modules\Finance\Application\Payroll;
use App\Modules\Finance\Domain\Models\Budget;
use App\Modules\Finance\Domain\Models\Expense;
use App\Modules\Finance\Domain\Models\IncomeRecord;
use App\Modules\Finance\Domain\Models\LedgerAccount;
use App\Modules\Finance\Domain\Models\LedgerEntry;
use App\Modules\Finance\Domain\Models\Payment;
use App\Modules\Finance\Domain\Models\PayrollLine;
use App\Modules\Finance\Domain\Models\PayrollRun;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Payables::class);
        // Registered lazily: resolving them needs the request's farm context.
        $this->app->resolving(Payables::class, function (Payables $payables, $app) {
            $payables->register('expense', $app->make(Expenses::class));
            $payables->register('payroll_run', $app->make(Payroll::class));
        });
    }

    public function boot(): void
    {
        Route::model('entry', LedgerEntry::class);
        Route::model('ledgerAccount', LedgerAccount::class);
        Route::model('expense', Expense::class);
        Route::model('income', IncomeRecord::class);
        Route::model('payment', Payment::class);
        Route::model('payrollRun', PayrollRun::class);
        Route::model('payrollLine', PayrollLine::class);
        Route::model('budget', Budget::class);
    }
}
