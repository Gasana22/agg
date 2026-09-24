<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Inventory\Application\Qty;
use App\Modules\Sales\Domain\Models\Customer;
use App\Modules\Sales\Domain\Models\CustomerInvoice;
use App\Modules\Sales\Domain\Models\SalesOrder;
use App\Modules\Sales\Domain\Models\Shipment;
use App\Modules\Sales\Domain\Models\ShipmentLine;
use App\Modules\Traceability\Application\BatchOperations;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use App\Modules\Traceability\Domain\Enums\BatchStatus;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Support\Database\Sequence;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sending goods to customers (docs/07 §2). Dispatch creates a `shipment`
 * trace batch, links it `ship` from each batch sent (taking the quantity,
 * so the same kilograms cannot leave twice) and records `dispatched` with
 * the customer; delivery records `delivered` and closes the batch.
 *
 * No prices here: shipments are handled by the store, and their events
 * may become public.
 */
class Shipments
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Recorder $recorder,
        private readonly BatchOperations $operations,
    ) {}

    /**
     * @param  array{customer_id:string, customer_invoice_id?:?string, destination?:?string, vehicle?:?string, driver?:?string, notes?:?string, dispatched_at?:?string,
     *     lines: array<int, array{batch_id:string, quantity?:string|float|null, description?:?string}>}  $data
     */
    public function dispatch(array $data): Shipment
    {
        $customer = Customer::find($data['customer_id']) ?? throw $this->invalid('customer_id', 'The selected customer does not exist in this farm.');
        if (! $customer->is_active) {
            throw $this->invalid('customer_id', 'This customer is inactive.');
        }
        $invoice = null;
        if (! empty($data['customer_invoice_id'])) {
            $invoice = CustomerInvoice::find($data['customer_invoice_id']) ?? throw $this->invalid('customer_invoice_id', 'The selected invoice does not exist in this farm.');
            if ($invoice->customer_id !== $customer->id || $invoice->status === 'void') {
                throw $this->invalid('customer_invoice_id', 'Choose a current invoice of this customer.');
            }
        }
        $at = isset($data['dispatched_at']) ? CarbonImmutable::parse($data['dispatched_at']) : CarbonImmutable::now();

        $lines = [];
        foreach (array_values($data['lines']) as $i => $line) {
            $batch = TraceBatch::find($line['batch_id']) ?? throw $this->invalid("lines.{$i}.batch_id", 'The selected batch does not exist in this farm.');
            $lines[] = ['batch' => $batch, 'quantity' => isset($line['quantity']) ? (string) $line['quantity'] : $this->operations->available($batch), 'description' => $line['description'] ?? null,
                'sales_order_line_id' => $line['sales_order_line_id'] ?? null];
        }

        return DB::transaction(function () use ($data, $customer, $invoice, $at, $lines) {
            $id = (string) Str::uuid7();
            $code = Sequence::code('shipment', 'SHP', 3);
            $units = array_unique(array_map(fn ($l) => $l['batch']->unit, $lines));
            $total = count($units) === 1 && $units[0] !== null && ! in_array(null, array_column($lines, 'quantity'), true)
                ? BatchOperations::decimal(array_sum(array_map(fn ($l) => BatchOperations::milli($l['quantity']), $lines))) : null;
            $event = ['occurred_at' => $at];

            $batch = $this->recorder->createBatch(BatchKind::Shipment, [
                'name' => mb_substr("{$code} → {$customer->name}", 0, 150),
                'quantity' => $total,
                'unit' => $total === null ? null : $units[0],
                'source_type' => 'shipment',
                'source_id' => $id,
            ], $event + ['subject_type' => 'shipment', 'subject_id' => $id]);
            $this->operations->ship($lines, $batch, $event);

            $shipment = new Shipment([
                'code' => $code,
                'status' => 'dispatched',
                'customer_id' => $customer->id,
                'customer_invoice_id' => $invoice?->id,
                'sales_order_id' => $data['sales_order_id'] ?? null,
                'trace_batch_id' => $batch->id,
                'destination' => $data['destination'] ?? $customer->address,
                'vehicle' => $data['vehicle'] ?? null,
                'driver' => $data['driver'] ?? null,
                'notes' => $data['notes'] ?? null,
                'dispatched_at' => $at,
                'dispatched_by' => Auth::id(),
            ]);
            $shipment->id = $id;
            $shipment->save();
            foreach ($lines as $i => $line) {
                ShipmentLine::create([
                    'shipment_id' => $shipment->id,
                    'position' => $i + 1,
                    'trace_batch_id' => $line['batch']->id,
                    'quantity' => $line['quantity'],
                    'unit' => $line['quantity'] === null ? null : $line['batch']->unit,
                    'description' => $line['description'] ?? $line['batch']->name,
                    'sales_order_line_id' => $line['sales_order_line_id'],
                ]);
            }

            $this->recorder->record($batch, 'dispatched', $event + ['subject_type' => 'shipment', 'subject_id' => $shipment->id, 'payload' => array_filter([
                'shipment' => $code,
                'customer' => $customer->name,
                'customer_code' => $customer->code,
                'destination' => $shipment->destination,
                'invoice' => $invoice?->code,
                'vehicle' => $shipment->vehicle,
                'batches' => implode(', ', array_map(fn ($l) => $l['batch']->batch_code, $lines)),
            ])]);
            $this->audit->record('sales.shipment.dispatched', $shipment, null, ['code' => $code, 'customer' => $customer->code, 'batch' => $batch->batch_code]);

            return $shipment->load('lines.batch', 'customer', 'invoice', 'batch');
        });
    }

    public function deliver(Shipment $shipment, array $data): Shipment
    {
        $this->assertDispatched($shipment);
        $at = isset($data['delivered_at']) ? CarbonImmutable::parse($data['delivered_at']) : CarbonImmutable::now();
        if ($at->lessThan($shipment->dispatched_at)) {
            throw $this->invalid('delivered_at', 'Delivery cannot be before dispatch.');
        }

        return DB::transaction(function () use ($shipment, $data, $at) {
            $shipment->forceFill(['status' => 'delivered', 'delivered_at' => $at, 'received_by' => $data['received_by'] ?? null, 'closed_by' => Auth::id()])->save();
            $batch = $shipment->batch;
            $this->recorder->record($batch, 'delivered', ['occurred_at' => $at, 'subject_type' => 'shipment', 'subject_id' => $shipment->id, 'payload' => array_filter([
                'shipment' => $shipment->code,
                'customer' => $shipment->customer->name,
                'received_by' => $shipment->received_by,
                'notes' => $data['notes'] ?? null,
            ])]);
            if ($batch->status === BatchStatus::Open) {
                $this->recorder->changeStatus($batch, BatchStatus::Closed, 'Delivered', ['occurred_at' => $at]);
            }
            $this->audit->record('sales.shipment.delivered', $shipment, ['status' => 'dispatched'], ['status' => 'delivered', 'received_by' => $shipment->received_by]);
            $this->settleOrder($shipment, $at);

            return $shipment->load('lines.batch', 'customer', 'invoice', 'batch');
        });
    }

    public function fail(Shipment $shipment, string $reason): Shipment
    {
        $this->assertDispatched($shipment);

        return DB::transaction(function () use ($shipment, $reason) {
            $shipment->forceFill(['status' => 'failed', 'failure_reason' => $reason, 'closed_by' => Auth::id()])->save();
            $this->recorder->record($shipment->batch, 'delivery_failed', ['subject_type' => 'shipment', 'subject_id' => $shipment->id,
                'payload' => ['shipment' => $shipment->code, 'customer' => $shipment->customer->name, 'reason' => $reason]]);
            $this->audit->record('sales.shipment.failed', $shipment, ['status' => 'dispatched'], ['status' => 'failed', 'reason' => $reason]);
            $this->returnToOrder($shipment);

            return $shipment->load('lines.batch', 'customer', 'invoice', 'batch');
        });
    }

    /** An order is delivered once all of it has left and every shipment that went has arrived. */
    private function settleOrder(Shipment $shipment, CarbonImmutable $at): void
    {
        if (! $shipment->sales_order_id) {
            return;
        }
        $order = SalesOrder::with('lines')->lockForUpdate()->find($shipment->sales_order_id);
        $allSent = $order && $order->lines->every(fn ($l) => Qty::milli($l->dispatched_quantity) >= Qty::milli($l->quantity));
        $open = $order ? Shipment::where('sales_order_id', $order->id)->where('status', 'dispatched')->exists() : true;
        if ($allSent && ! $open && $order->status === 'dispatched') {
            $order->forceFill(['status' => 'delivered', 'delivered_at' => $at])->save();
            $this->audit->record('sales.order.delivered', $order, ['status' => 'dispatched'], ['status' => 'delivered']);
        }
    }

    /** Goods that did not arrive are still owed: their quantity can be sent again. */
    private function returnToOrder(Shipment $shipment): void
    {
        if (! $shipment->sales_order_id) {
            return;
        }
        $order = SalesOrder::with(['lines', 'invoice'])->lockForUpdate()->find($shipment->sales_order_id);
        if (! $order) {
            return;
        }
        foreach ($shipment->lines()->whereNotNull('sales_order_line_id')->get() as $sl) {
            $line = $order->lines->firstWhere('id', $sl->sales_order_line_id);
            $line?->forceFill(['dispatched_quantity' => Qty::of(max(0, Qty::milli($line->dispatched_quantity) - Qty::milli($sl->quantity)))])->save();
        }
        $sent = $order->lines->contains(fn ($l) => Qty::milli($l->dispatched_quantity) > 0);
        if (! $sent && $order->status === 'dispatched') {
            $back = $order->invoice && $order->invoice->status !== 'void' ? 'invoiced' : 'approved';
            $order->forceFill(['status' => $back])->save();
            $this->audit->record('sales.order.returned', $order, ['status' => 'dispatched'], ['status' => $back, 'shipment' => $shipment->code]);
        }
    }

    private function assertDispatched(Shipment $shipment): void
    {
        if ($shipment->status !== 'dispatched') {
            throw ApiException::conflict('invalid_state_transition', "The shipment is already {$shipment->status}.");
        }
    }

    private function invalid(string $field, string $message): ApiException
    {
        return ApiException::unprocessable('validation_failed', 'The given data was invalid.', [$field => [$message]]);
    }
}
