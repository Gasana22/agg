"use client";

import { useQuery } from "@tanstack/react-query";
import { Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { useCustomers } from "@/components/finance/queries";
import { FormDialog, num, text } from "@/components/forms/form-dialog";
import { useUsableBatches } from "@/components/trace/dialogs";
import { Button } from "@/components/ui/button";
import { Checkbox, FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { api } from "@/lib/api/client";
import { formatMoney, formatQty } from "@/lib/inventory";
import type { Product, SalesOrder } from "@/lib/portal";

type Base = { farmId: string; onClose: () => void; onDone: () => Promise<void> };

export function useProducts(farmId: string, enabled = true) {
  return useQuery({
    queryKey: ["products", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/products", { params: { path: { farm: farmId } } })).data!.data ?? [],
    enabled,
  });
}

/** Add or change a product and its list price (sales.pricing.manage). */
export function ProductDialog({ farmId, product, onClose, onDone }: Base & { product?: Product }) {
  return (
    <FormDialog
      title={product ? `Edit ${product.name}` : "New product"}
      description="Customers on the portal order at the list price; published products appear in their shop."
      submitLabel={product ? "Save" : "Add product"}
      onClose={onClose}
      onSubmit={async (f) => {
        const body = {
          name: text(f, "name") ?? "",
          description: text(f, "description"),
          category: text(f, "category"),
          unit: text(f, "unit") ?? "",
          list_price: num(f, "list_price") ?? 0,
          min_order_quantity: num(f, "min_order_quantity"),
          availability_note: text(f, "availability_note"),
          is_published: f.get("is_published") === "on",
          is_active: f.get("is_active") === "on",
        };
        if (product) {
          await api.PATCH("/farms/{farm}/products/{product}", { params: { path: { farm: farmId, product: product.id! } }, body: { ...body, version: product.version } });
        } else {
          await api.POST("/farms/{farm}/products", { params: { path: { farm: farmId } }, body });
        }
        await onDone();
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="name">Name</Label>
            <Input id="name" name="name" required defaultValue={product?.name} />
            <FieldError>{error?.fieldError("name")}</FieldError>
          </div>
          <div className="grid gap-3 sm:grid-cols-3">
            <div>
              <Label htmlFor="unit">Sold per</Label>
              <Input id="unit" name="unit" required placeholder="kg, tray, l…" defaultValue={product?.unit} />
              <FieldError>{error?.fieldError("unit")}</FieldError>
            </div>
            <div>
              <Label htmlFor="list_price">List price</Label>
              <Input id="list_price" name="list_price" type="number" min={0} step="0.01" required defaultValue={product?.list_price} />
              <FieldError>{error?.fieldError("list_price")}</FieldError>
            </div>
            <div>
              <Label htmlFor="min_order_quantity">Minimum order</Label>
              <Input id="min_order_quantity" name="min_order_quantity" type="number" min={0} step="any" defaultValue={product?.min_order_quantity ?? ""} />
            </div>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <Label htmlFor="category">Category</Label>
              <Input id="category" name="category" defaultValue={product?.category ?? ""} />
            </div>
            <div>
              <Label htmlFor="availability_note">Availability</Label>
              <Input id="availability_note" name="availability_note" placeholder="About 2 t in store" defaultValue={product?.availability_note ?? ""} />
            </div>
          </div>
          <div>
            <Label htmlFor="description">Description</Label>
            <Textarea id="description" name="description" rows={2} defaultValue={product?.description ?? ""} />
          </div>
          <Checkbox name="is_published" label="Published in the customer portal" defaultChecked={product?.is_published ?? false} />
          <Checkbox name="is_active" label="Active" defaultChecked={product?.is_active ?? true} />
        </>
      )}
    </FormDialog>
  );
}

/** Record an order for a customer (by phone, at the gate …). */
export function NewOrderDialog({ farmId, onClose, onDone }: Base) {
  const customers = useCustomers(farmId);
  const products = useProducts(farmId);
  const [lines, setLines] = useState([{ key: 0 }]);
  const active = (products.data ?? []).filter((p) => p.is_active);
  return (
    <FormDialog
      title="New sales order"
      description="Approved as you record it when within the farm's sales order threshold; above it, the owner approves."
      submitLabel="Record order"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/sales-orders", {
          params: { path: { farm: farmId } },
          body: {
            customer_id: String(f.get("customer_id")),
            requested_delivery_on: text(f, "requested_delivery_on"),
            internal_note: text(f, "internal_note"),
            lines: lines.map((l) => ({ product_id: String(f.get(`product_${l.key}`)), quantity: num(f, `qty_${l.key}`) ?? 0, unit_price: num(f, `price_${l.key}`) })),
          },
        });
        await onDone();
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="customer_id">Customer</Label>
            <Select id="customer_id" name="customer_id" required>
              <option value="">Choose…</option>
              {(customers.data ?? []).map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
            <FieldError>{error?.fieldError("customer_id")}</FieldError>
          </div>
          {lines.map((l, i) => (
            <div key={l.key} className="grid grid-cols-[1fr_6rem_7rem_auto] items-end gap-2">
              <div>
                <Label htmlFor={`product_${l.key}`}>Product</Label>
                <Select id={`product_${l.key}`} name={`product_${l.key}`} required>
                  <option value="">Choose…</option>
                  {active.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name} ({formatMoney(p.list_price, p.currency)}/{p.unit})
                    </option>
                  ))}
                </Select>
                <FieldError>{error?.fieldError(`lines.${i}.product_id`) ?? error?.fieldError(`lines.${i}.quantity`)}</FieldError>
              </div>
              <div>
                <Label htmlFor={`qty_${l.key}`}>Quantity</Label>
                <Input id={`qty_${l.key}`} name={`qty_${l.key}`} type="number" step="any" min={0} required />
              </div>
              <div>
                <Label htmlFor={`price_${l.key}`}>Price</Label>
                <Input id={`price_${l.key}`} name={`price_${l.key}`} type="number" step="0.01" min={0} placeholder="List" />
              </div>
              <Button type="button" variant="ghost" size="icon" aria-label="Remove line" disabled={lines.length === 1} onClick={() => setLines(lines.filter((x) => x.key !== l.key))}>
                <Trash2 />
              </Button>
            </div>
          ))}
          <Button type="button" variant="secondary" size="sm" onClick={() => setLines([...lines, { key: Math.max(...lines.map((l) => l.key)) + 1 }])}>
            <Plus /> Add line
          </Button>
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <Label htmlFor="requested_delivery_on">Deliver by</Label>
              <Input id="requested_delivery_on" name="requested_delivery_on" type="date" />
            </div>
            <div>
              <Label htmlFor="internal_note">Internal note</Label>
              <Input id="internal_note" name="internal_note" placeholder="Not shown to the customer" />
            </div>
          </div>
        </>
      )}
    </FormDialog>
  );
}

/** Ship all or part of an order from trace batches (sales.fulfil). */
export function DispatchOrderDialog({ farmId, order, onClose, onDone }: Base & { order: SalesOrder }) {
  const usable = useUsableBatches(farmId);
  const open = (order.lines ?? []).filter((l) => (l.quantity ?? 0) > (l.dispatched_quantity ?? 0));
  return (
    <FormDialog
      title={`Dispatch ${order.code}`}
      description={`To ${order.customer?.name}. Choose the batch each line comes from; it must be counted in the order's unit.`}
      submitLabel="Dispatch"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/sales-orders/{salesOrder}/dispatch", {
          params: { path: { farm: farmId, salesOrder: order.id! } },
          body: {
            vehicle: text(f, "vehicle"),
            driver: text(f, "driver"),
            destination: text(f, "destination"),
            lines: open
              .map((l) => ({ order_line_id: l.id!, batch_id: String(f.get(`batch_${l.id}`) ?? ""), quantity: num(f, `qty_${l.id}`) ?? 0 }))
              .filter((l) => l.batch_id && l.quantity > 0),
          },
        });
        await onDone();
      }}
    >
      {(error) => (
        <>
          {open.map((l, i) => {
            const left = Math.round(((l.quantity ?? 0) - (l.dispatched_quantity ?? 0)) * 1000) / 1000;
            const batches = (usable.data ?? []).filter((b) => !b.quantity?.unit || b.quantity.unit === l.unit);
            return (
              <div key={l.id} className="grid grid-cols-[1fr_7rem] items-end gap-2">
                <div>
                  <Label htmlFor={`batch_${l.id}`}>
                    {l.description} ({formatQty(left, l.unit)} to send)
                  </Label>
                  <Select id={`batch_${l.id}`} name={`batch_${l.id}`}>
                    <option value="">Not in this shipment</option>
                    {batches.map((b) => (
                      <option key={b.id} value={b.id}>
                        {b.batch_code} · {b.name} ({b.available ? `${b.available.value} ${b.available.unit ?? ""}` : "—"})
                      </option>
                    ))}
                  </Select>
                  <FieldError>{error?.fieldError(`lines.${i}.batch_id`) ?? error?.fieldError(`lines.${i}.quantity`)}</FieldError>
                </div>
                <div>
                  <Label htmlFor={`qty_${l.id}`}>Quantity</Label>
                  <Input id={`qty_${l.id}`} name={`qty_${l.id}`} type="number" step="any" min={0} max={left} defaultValue={left} />
                </div>
              </div>
            );
          })}
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <Label htmlFor="vehicle">Vehicle</Label>
              <Input id="vehicle" name="vehicle" />
            </div>
            <div>
              <Label htmlFor="driver">Driver</Label>
              <Input id="driver" name="driver" />
            </div>
          </div>
          <div>
            <Label htmlFor="destination">Destination</Label>
            <Input id="destination" name="destination" defaultValue={order.delivery_address ?? ""} />
          </div>
        </>
      )}
    </FormDialog>
  );
}
