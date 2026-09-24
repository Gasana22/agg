<?php

use App\Modules\Finance\Http\Controllers\BudgetController;
use App\Modules\Finance\Http\Controllers\ExpenseController;
use App\Modules\Finance\Http\Controllers\IncomeController;
use App\Modules\Finance\Http\Controllers\LedgerController;
use App\Modules\Finance\Http\Controllers\PaymentController;
use App\Modules\Finance\Http\Controllers\PayrollController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'mfa.compliant', 'throttle:api', 'farm'])
    ->prefix('farms/{farm}')
    ->name('farms.')
    ->whereUuid(['entry', 'ledgerAccount', 'expense', 'income', 'payment', 'payrollRun', 'payrollLine', 'budget'])
    ->group(function () {
        Route::prefix('ledger')->name('ledger.')->group(function () {
            Route::middleware('farm.can:finance.view')->group(function () {
                Route::get('accounts', [LedgerController::class, 'accounts'])->name('accounts');
                Route::get('entries', [LedgerController::class, 'entries'])->name('entries.index');
                Route::get('entries/{entry}', [LedgerController::class, 'show'])->name('entries.show');
            });
            Route::middleware('farm.can:finance.manage')->group(function () {
                Route::post('accounts', [LedgerController::class, 'storeAccount'])->name('accounts.store');
                Route::patch('accounts/{ledgerAccount}', [LedgerController::class, 'updateAccount'])->name('accounts.update');
                Route::post('entries', [LedgerController::class, 'storeEntry'])->name('entries.store');
                Route::post('entries/{entry}/reverse', [LedgerController::class, 'reverse'])->name('entries.reverse');
            });
        });

        Route::name('expenses.')->prefix('expenses')->group(function () {
            Route::middleware('farm.can:finance.view|finance.expenses.request')->group(function () {
                Route::get('/', [ExpenseController::class, 'index'])->name('index');
                Route::get('{expense}', [ExpenseController::class, 'show'])->name('show');
            });
            Route::middleware('farm.can:finance.expenses.request|finance.manage')->group(function () {
                Route::post('/', [ExpenseController::class, 'store'])->name('store');
                Route::post('{expense}/cancel', [ExpenseController::class, 'cancel'])->name('cancel');
            });
            Route::middleware('farm.can:finance.manage|finance.approve')->group(function () {
                Route::post('{expense}/approve', [ExpenseController::class, 'approve'])->name('approve');
                Route::post('{expense}/reject', [ExpenseController::class, 'reject'])->name('reject');
            });
            Route::post('{expense}/void', [ExpenseController::class, 'void'])->middleware('farm.can:finance.manage')->name('void');
        });

        Route::name('income.')->prefix('income')->group(function () {
            Route::get('/', [IncomeController::class, 'index'])->middleware('farm.can:finance.view')->name('index');
            Route::middleware('farm.can:finance.manage')->group(function () {
                Route::post('/', [IncomeController::class, 'store'])->name('store');
                Route::post('{income}/void', [IncomeController::class, 'void'])->name('void');
            });
        });

        Route::name('payments.')->prefix('payments')->group(function () {
            Route::get('/', [PaymentController::class, 'index'])->middleware('farm.can:finance.view|sales.invoice')->name('index');
            // The document type decides which of the two is needed (Payable::permission).
            Route::middleware('farm.can:finance.manage|finance.payroll.manage|sales.invoice')->group(function () {
                Route::post('/', [PaymentController::class, 'store'])->name('store');
                Route::post('{payment}/void', [PaymentController::class, 'void'])->name('void');
            });
        });

        Route::name('payroll.')->prefix('payroll-runs')->group(function () {
            Route::middleware('farm.can:finance.payroll.manage|finance.payroll.approve|finance.payroll.view_hours|finance.view')->group(function () {
                Route::get('/', [PayrollController::class, 'index'])->name('index');
                Route::get('{payrollRun}', [PayrollController::class, 'show'])->name('show');
            });
            Route::middleware('farm.can:finance.payroll.manage')->group(function () {
                Route::post('/', [PayrollController::class, 'store'])->name('store');
                Route::post('{payrollRun}/recalculate', [PayrollController::class, 'recalculate'])->name('recalculate');
                Route::patch('{payrollRun}/lines/{payrollLine}', [PayrollController::class, 'updateLine'])->name('lines.update');
                Route::post('{payrollRun}/cancel', [PayrollController::class, 'cancel'])->name('cancel');
            });
            Route::post('{payrollRun}/approve', [PayrollController::class, 'approve'])->middleware('farm.can:finance.payroll.approve')->name('approve');
        });

        Route::name('budgets.')->prefix('budgets')->group(function () {
            Route::middleware('farm.can:finance.view|finance.budgets.manage')->group(function () {
                Route::get('/', [BudgetController::class, 'index'])->name('index');
                Route::get('{budget}', [BudgetController::class, 'show'])->name('show');
            });
            Route::middleware('farm.can:finance.budgets.manage')->group(function () {
                Route::post('/', [BudgetController::class, 'store'])->name('store');
                Route::patch('{budget}', [BudgetController::class, 'update'])->name('update');
            });
        });
    });
