"use client";

import { useQuery } from "@tanstack/react-query";
import { useState } from "react";

import { FormDialog, num, text } from "@/components/forms/form-dialog";
import { LinesEditor, newLine, StoreSelect, type Line } from "@/components/inventory/pickers";
import { useItems, useSuppliers } from "@/components/inventory/queries";
import { FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { api } from "@/lib/api/client";
import { formatMoney, formatQty, linesTotal, outstanding, toInvoice, type PurchaseOrder, type Supplier } from "@/lib/inventory";

type Base = { farmId: string; onClose: () => void; onDone: () => void };
const today = () => new Date().toISOString().slice(0, 10);

export function SupplierDialog({ farmId, supplier, onClose, onDone }: Base & { supplier?: Supplier }) {
  return (
    <FormDialog
      title={supplier ? `Edit ${supplier.name}` : "New supplier"}
      submitLabel={supplier ? "Save" : "Add supplier"}
      onClose={onClose}
      onSubmit={async (f) => {
        const body = {
          name: String(f.get("name")),
          contact_person: text(f, "contact_person"),
          phone: text(f, "phone"),
          email: text(f, "email"),
          address: text(f, "address"),
          tax_id: text(f, "tax_id"),
          payment_terms_days: num(f, "payment_terms_days"),
          notes: text(f, "notes"),
        };
        if (supplier) {
          await api.PATCH("/farms/{farm}/suppliers/{supplier}", { params: { path: { farm: farmId, supplier: supplier.id! } }, body: { ...body, is_active: f.get("is_active") === "on", version: supplier.version } });
        } else {
          await api.POST("/farms/{farm}/suppliers", { params: { path: { farm: farmId } }, body });
        }
        onDone();
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="name">Name</Label>
            <Input id="name" name="name" required minLength={2} defaultValue={supplier?.name} aria-invalid={!!error?.fieldError("name")} />
            <FieldError>{error?.fieldError("name")}</FieldError>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="contact_person">Contact person</Label>
              <Input id="contact_person" name="contact_person" defaultValue={supplier?.contact_person ?? ""} />
            </div>
            <div>
              <Label htmlFor="phone">Phone</Label>
              <Input id="phone" name="phone" type="tel" defaultValue={supplier?.phone ?? ""} />
            </div>
            <div>
              <Label htmlFor="email">Email</Label>
              <Input id="email" name="email" type="email" defaultValue={supplier?.email ?? ""} aria-invalid={!!error?.fieldError("email")} />
              <FieldError>{error?.fieldError("email")}</FieldError>
            </div>
            <div>
              <Label htmlFor="payment_terms_days">Pays in (days)</Label>
              <Input id="payment_terms_days" name="payment_terms_days" type="number" min="0" max="365" defaultValue={supplier?.payment_terms_days ?? ""} />
            </div>
            <div>
              <Label htmlFor="tax_id">Tax ID (TIN)</Label>
              <Input id="tax_id" name="tax_id" defaultValue={supplier?.tax_id ?? ""} />
            </div>
            <div>
              <Label htmlFor="address">Address</Label>
              <Input id="address" name="address" defaultValue={supplier?.address ?? ""} />
            </div>
          </div>
          {supplier ? (
            <label className="inline-flex items-center gap-2 text-sm">
              <input type="checkbox" name="is_active" defaultChecked={supplier.is_active} className="size-4" /> Active
            </label>
          ) : null}
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" defaultValue={supplier?.notes ?? ""} className="min-h-16" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function PurchaseRequestDialog({ farmId, onClose, onDone }: Base) {
  const items = useItems(farmId);
  const [rows, setRows] = useState<Line[]>([newLine()]);
  return (
    <FormDialog
      title="Purchase request"
      description="Ask for something to be bought. Once approved, it can be turned into a purchase order."
      submitLabel="Submit"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/purchase-requests", {
          params: { path: { farm: farmId } },
          body: { needed_by: text(f, "needed_by"), reason: text(f, "reason"), lines: rows.filter((l) => l.item_id).map((l) => ({ item_id: l.item_id, quantity: Number(l.quantity) })) },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <LinesEditor items={items.data ?? []} lines={rows} onChange={setRows} error={error} />
          <div>
            <Label htmlFor="needed_by">Needed by</Label>
            <Input id="needed_by" name="needed_by" type="date" />
          </div>
          <div>
            <Label htmlFor="reason">Why</Label>
            <Input id="reason" name="reason" placeholder="Season B top dressing" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

/** A new draft order, or changes to a draft. Starting from an approved purchase request copies its items. */
export function OrderDialog({ farmId, order, currency, onClose, onDone }: Base & { order?: PurchaseOrder; currency: string }) {
  const items = useItems(farmId);
  const suppliers = useSuppliers(farmId);
  const approved = useQuery({
    queryKey: ["purchase-requests", farmId, "approved"],
    queryFn: async () => (await api.GET("/farms/{farm}/purchase-requests", { params: { path: { farm: farmId }, query: { "filter[status]": "approved" } } })).data!.data!,
    enabled: !order,
  });
  const [requestId, setRequestId] = useState("");
  const [rows, setRows] = useState<Line[]>(
    order?.lines?.length
      ? order.lines.map((l) => ({ key: l.id!, item_id: l.item?.id ?? "", quantity: String(l.quantity ?? ""), unit_price: String(l.unit_price ?? "") }))
      : [newLine()],
  );
  const fromRequest = (id: string) => {
    setRequestId(id);
    const pr = approved.data?.find((r) => r.id === id);
    if (pr) setRows(pr.lines!.filter((l) => l.item).map((l) => ({ key: l.id!, item_id: l.item!.id!, quantity: String(l.quantity), unit_price: l.estimated_unit_price != null ? String(l.estimated_unit_price) : "" })));
  };
  const body = (f: FormData) => ({
    expected_on: text(f, "expected_on"),
    delivery_location_id: text(f, "delivery_location_id"),
    notes: text(f, "notes"),
    lines: rows.filter((l) => l.item_id).map((l) => ({ item_id: l.item_id, quantity: Number(l.quantity), unit_price: Number(l.unit_price) })),
  });

  return (
    <FormDialog
      title={order ? `Edit ${order.code}` : "New purchase order"}
      description={order ? "Drafts only; approval comes after." : "Saved as a draft for approval."}
      submitLabel={order ? "Save" : "Create draft"}
      onClose={onClose}
      onSubmit={async (f) => {
        if (order) {
          await api.PATCH("/farms/{farm}/purchase-orders/{order}", { params: { path: { farm: farmId, order: order.id! } }, body: { ...body(f), version: order.version } });
        } else {
          await api.POST("/farms/{farm}/purchase-orders", {
            params: { path: { farm: farmId } },
            body: { ...body(f), supplier_id: String(f.get("supplier_id")), purchase_request_id: requestId || null },
          });
        }
        onDone();
      }}
    >
      {(error) => (
        <>
          {!order ? (
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label htmlFor="supplier_id">Supplier</Label>
                <Select id="supplier_id" name="supplier_id" required aria-invalid={!!error?.fieldError("supplier_id")}>
                  <option value="">Choose…</option>
                  {(suppliers.data ?? []).map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
                </Select>
                <FieldError>{error?.fieldError("supplier_id")}</FieldError>
              </div>
              <div>
                <Label htmlFor="purchase_request_id">From request</Label>
                <Select id="purchase_request_id" value={requestId} onChange={(e) => fromRequest(e.target.value)}>
                  <option value="">None</option>
                  {(approved.data ?? []).map((r) => (
                    <option key={r.id} value={r.id}>
                      {r.code} · {r.requested_by?.name}
                    </option>
                  ))}
                </Select>
              </div>
            </div>
          ) : null}
          <LinesEditor items={items.data ?? []} lines={rows} onChange={setRows} withPrice error={error} />
          <p className="text-right text-sm">
            Total <strong className="tabular-nums">{formatMoney(linesTotal(rows.map((l) => ({ quantity: l.quantity, unit_price: l.unit_price ?? 0 }))), currency)}</strong>
          </p>
          <div className="grid grid-cols-2 gap-3">
            <StoreSelect farmId={farmId} id="delivery_location_id" name="delivery_location_id" label="Deliver to" defaultValue={order?.delivery_location?.id ?? ""} />
            <div>
              <Label htmlFor="expected_on">Expected on</Label>
              <Input id="expected_on" name="expected_on" type="date" defaultValue={order?.expected_on ?? ""} />
            </div>
          </div>
          <div>
            <Label htmlFor="notes">Notes for the supplier</Label>
            <Input id="notes" name="notes" defaultValue={order?.notes ?? ""} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

/** A goods received note: what arrived of each outstanding line, with its lot. */
/** A dispatch notice from the supplier portal to receive against. */
export type DispatchNotice = { id: string; code: string; reference?: string | null; lines: { order_line_id: string; quantity: number }[] };

export function ReceiveDialog({ farmId, order, dispatch, onClose, onDone }: Base & { order: PurchaseOrder; dispatch?: DispatchNotice }) {
  const items = useItems(farmId);
  const due = (order.lines ?? []).filter((l) => outstanding(l) > 0);
  const announced = (id: string) => dispatch?.lines.find((l) => l.order_line_id === id)?.quantity;
  const [qty, setQty] = useState<Record<string, string>>(Object.fromEntries(due.map((l) => [l.id!, String(dispatch ? (announced(l.id!) ?? 0) : outstanding(l))])));
  const tracking = (itemId?: string) => items.data?.find((i) => i.id === itemId);
  return (
    <FormDialog
      title={dispatch ? `Receive ${dispatch.code}` : `Receive ${order.code}`}
      description={`From ${order.supplier?.name}${dispatch ? `, as announced in the portal (${order.code})` : ""}. Each line becomes a stock lot you can trace.`}
      submitLabel="Receive"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/purchase-orders/{order}/deliveries", {
          params: { path: { farm: farmId, order: order.id! } },
          body: {
            location_id: String(f.get("location_id")),
            dispatch_id: dispatch?.id ?? null,
            received_on: text(f, "received_on") ?? undefined,
            supplier_reference: text(f, "supplier_reference"),
            note: text(f, "note"),
            lines: due
              .filter((l) => Number(qty[l.id!] || 0) > 0)
              .map((l) => ({ order_line_id: l.id!, quantity: Number(qty[l.id!]), lot_number: text(f, `lot_${l.id}`), expires_on: text(f, `exp_${l.id}`) })),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div className="grid grid-cols-2 gap-3">
            <StoreSelect farmId={farmId} id="location_id" name="location_id" required defaultValue={order.delivery_location?.id ?? ""} error={error?.fieldError("location_id")} />
            <div>
              <Label htmlFor="received_on">Received on</Label>
              <Input id="received_on" name="received_on" type="date" defaultValue={today()} max={today()} />
            </div>
          </div>
          <div>
            <Label htmlFor="supplier_reference">Delivery note number</Label>
            <Input id="supplier_reference" name="supplier_reference" defaultValue={dispatch?.reference ?? ""} />
          </div>
          <div className="space-y-3">
            {due.map((l, i) => {
              const it = tracking(l.item?.id);
              return (
                <div key={l.id} className="rounded-lg border border-border p-3 text-sm">
                  <div className="flex items-center gap-3">
                    <span className="min-w-0 flex-1 font-medium">
                      {l.item?.name}
                      <span className="block text-xs font-normal text-muted">
                        {formatQty(l.received_quantity, l.item?.unit)} of {formatQty(l.quantity, l.item?.unit)} received
                      </span>
                    </span>
                    <Input aria-label={`Received ${l.item?.name}`} type="number" step="any" min="0" className="w-28" value={qty[l.id!] ?? ""} onChange={(e) => setQty({ ...qty, [l.id!]: e.target.value })} />
                  </div>
                  {it?.tracks_lots ? (
                    <div className="mt-2 grid grid-cols-2 gap-2">
                      <Input aria-label={`Lot number ${l.item?.name}`} name={`lot_${l.id}`} placeholder="Supplier lot number" />
                      <Input aria-label={`Expiry ${l.item?.name}`} name={`exp_${l.id}`} type="date" required={it.tracks_expiry && Number(qty[l.id!] || 0) > 0} />
                    </div>
                  ) : null}
                  <FieldError>{error?.fieldError(`lines.${i}.quantity`) ?? error?.fieldError(`lines.${i}.expires_on`)}</FieldError>
                </div>
              );
            })}
          </div>
          <FieldError>{error?.fieldError("lines")}</FieldError>
        </>
      )}
    </FormDialog>
  );
}

/** The supplier's invoice, matched against what was received and not yet invoiced. */
export function InvoiceDialog({ farmId, order, currency, onClose, onDone }: Base & { order: PurchaseOrder; currency: string }) {
  const open = (order.lines ?? []).filter((l) => toInvoice(l) > 0);
  const [rows, setRows] = useState<Record<string, { quantity: string; unit_price: string }>>(Object.fromEntries(open.map((l) => [l.id!, { quantity: String(toInvoice(l)), unit_price: String(l.unit_price ?? "") }])));
  const chosen = open.filter((l) => Number(rows[l.id!]?.quantity || 0) > 0);
  const total = linesTotal(chosen.map((l) => rows[l.id!]));
  const atOrder = linesTotal(chosen.map((l) => ({ quantity: rows[l.id!].quantity, unit_price: l.unit_price ?? 0 })));
  return (
    <FormDialog
      title={`Invoice for ${order.code}`}
      description="Only what was received can be invoiced. A price different from the order goes to price variance."
      submitLabel="Record invoice"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/purchase-orders/{order}/invoices", {
          params: { path: { farm: farmId, order: order.id! } },
          body: {
            invoice_number: String(f.get("invoice_number")),
            invoice_date: String(f.get("invoice_date")),
            due_on: text(f, "due_on"),
            notes: text(f, "notes"),
            lines: chosen.map((l) => ({ order_line_id: l.id!, quantity: Number(rows[l.id!].quantity), unit_price: Number(rows[l.id!].unit_price) })),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label htmlFor="invoice_number">Invoice no.</Label>
              <Input id="invoice_number" name="invoice_number" required aria-invalid={!!error?.fieldError("invoice_number")} />
              <FieldError>{error?.fieldError("invoice_number")}</FieldError>
            </div>
            <div>
              <Label htmlFor="invoice_date">Dated</Label>
              <Input id="invoice_date" name="invoice_date" type="date" required defaultValue={today()} max={today()} />
            </div>
            <div>
              <Label htmlFor="due_on">Due</Label>
              <Input id="due_on" name="due_on" type="date" placeholder="From terms" />
            </div>
          </div>
          <div className="space-y-2">
            {open.map((l, i) => (
              <div key={l.id} className="flex items-center gap-2 text-sm">
                <span className="min-w-0 flex-1">
                  {l.item?.name}
                  <span className="block text-xs text-muted">
                    {formatQty(toInvoice(l), l.item?.unit)} to invoice · ordered at {formatMoney(l.unit_price, currency)}
                  </span>
                </span>
                <Input aria-label={`Quantity ${l.item?.name}`} type="number" step="any" min="0" className="w-24" value={rows[l.id!].quantity} onChange={(e) => setRows({ ...rows, [l.id!]: { ...rows[l.id!], quantity: e.target.value } })} />
                <Input aria-label={`Price ${l.item?.name}`} type="number" step="any" min="0" className="w-28" value={rows[l.id!].unit_price} onChange={(e) => setRows({ ...rows, [l.id!]: { ...rows[l.id!], unit_price: e.target.value } })} />
                <FieldError>{error?.fieldError(`lines.${i}.quantity`)}</FieldError>
              </div>
            ))}
          </div>
          <p className="text-right text-sm">
            Total <strong className="tabular-nums">{formatMoney(total, currency)}</strong>
            {total !== atOrder ? <span className="block text-xs text-muted">Price variance {formatMoney(Math.round((total - atOrder) * 100) / 100, currency)}</span> : null}
          </p>
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Input id="notes" name="notes" />
          </div>
        </>
      )}
    </FormDialog>
  );
}
