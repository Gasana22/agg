<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreExpenseRequest;
use App\Http\Requests\Finance\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Models\Farm;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ExpenseController extends Controller
{
    public function index(Farm $farm): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Expense::class, $farm]);

        return ExpenseResource::collection(
            $farm->expenses()->with('recorder')->latest('date')->get()
        );
    }

    public function store(StoreExpenseRequest $request, Farm $farm): ExpenseResource
    {
        $this->authorize('create', [Expense::class, $farm]);

        $expense = $farm->expenses()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        return new ExpenseResource($expense->load('recorder'));
    }

    public function show(Expense $expense): ExpenseResource
    {
        $this->authorize('view', $expense);

        return new ExpenseResource($expense->load('recorder'));
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): ExpenseResource
    {
        $this->authorize('update', $expense);

        $expense->update($request->validated());

        return new ExpenseResource($expense->load('recorder'));
    }

    public function destroy(Expense $expense): Response
    {
        $this->authorize('delete', $expense);

        $expense->delete();

        return response()->noContent();
    }
}
