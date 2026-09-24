<?php

namespace Tests\Feature\Sales;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use Tests\TestCase;

class ShipmentTest extends TestCase
{
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->farm = $this->farm();
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    private function eggs(string $quantity = '300'): string
    {
        return $this->inFarm($this->farm, fn () => app(Recorder::class)->createBatch(BatchKind::AnimalProduct, ['name' => 'Eggs 24 Sep', 'quantity' => $quantity, 'unit' => 'pcs']))->id;
    }

    public function test_the_store_dispatches_and_the_customer_confirms(): void
    {
        $store = $this->memberWithRole($this->farm, 'store_manager');
        $accountant = $this->memberWithRole($this->farm, 'accountant');
        $customer = $this->asUser($accountant)->postJson($this->url('/customers'), ['name' => 'Shop A'])->json('data.id');
        $other = $this->asUser($accountant)->postJson($this->url('/customers'), ['name' => 'Shop B'])->json('data.id');
        $invoice = $this->asUser($accountant)->postJson($this->url('/customer-invoices'), ['customer_id' => $other, 'lines' => [['description' => 'Eggs', 'quantity' => 1, 'unit_price' => 100,
            'account_id' => collect($this->asUser($accountant)->getJson($this->url('/ledger/accounts'))->json('data'))->firstWhere('code', '4100')['id']]]])->json('data.id');
        $eggs = $this->eggs();

        // The store sees customers (to dispatch) but not invoices.
        $this->asUser($store)->getJson($this->url('/customers'))->assertOk();
        $this->asUser($store)->getJson($this->url('/customer-invoices'))->assertForbidden();

        $this->assertProblem($this->asUser($store)->postJson($this->url('/shipments'), ['customer_id' => $customer, 'customer_invoice_id' => $invoice, 'lines' => [['batch_id' => $eggs]]]), 422, 'validation_failed');
        $shipment = $this->asUser($store)->postJson($this->url('/shipments'), ['customer_id' => $customer, 'lines' => [['batch_id' => $eggs]]])
            ->assertCreated()->assertJsonPath('data.lines.0.quantity.value', '300.000')->assertJsonPath('data.trace_batch.status', 'open')->json('data');
        // All of it went: the eggs batch is closed.
        $this->asUser($store)->getJson($this->url("/traceability/batches/{$eggs}"))->assertJsonPath('data.status', 'closed');

        $this->assertProblem($this->asUser($store)->postJson($this->url("/shipments/{$shipment['id']}/deliver"), ['delivered_at' => now()->subYear()->toIso8601String()]), 422, 'validation_failed');
        $this->asUser($store)->postJson($this->url("/shipments/{$shipment['id']}/fail"), ['reason' => 'Shop closed'])->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertProblem($this->asUser($store)->postJson($this->url("/shipments/{$shipment['id']}/deliver")), 409, 'invalid_state_transition');

        $events = collect($this->asUser($store)->getJson($this->url("/traceability/batches/{$shipment['trace_batch']['id']}/events"))->json('data'))->pluck('event_type')->all();
        $this->assertSame(['created', 'linked_from', 'dispatched', 'delivery_failed'], $events);

        // The accountant can read shipments, not dispatch them; the worker neither.
        $this->asUser($accountant)->getJson($this->url('/shipments'))->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($accountant)->postJson($this->url('/shipments'), ['customer_id' => $customer, 'lines' => [['batch_id' => $this->eggs()]]])->assertForbidden();
        $worker = $this->memberWithRole($this->farm, 'field_worker');
        $this->asUser($worker)->getJson($this->url('/shipments'))->assertForbidden();
        $this->asUser($worker)->getJson($this->url("/shipments/{$shipment['id']}"))->assertForbidden();
    }
}
