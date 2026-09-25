"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { useActions } from "@/components/inventory/common";
import { ReasonDialog } from "@/components/inventory/dialogs";
import { InvoiceDialog, OrderDialog, ReceiveDialog, type DispatchNotice } from "@/components/procurement/dialogs";
import { SupplierPortalSection } from "@/components/procurement/portal-section";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { formatDateTime } from "@/lib/format";
import { formatMoney, formatQty, ORDER_STATUS, outstanding, RECEIVABLE, toInvoice } from "@/lib/inventory";
import { can } from "@/lib/permissions";

export default function OrderPage() {
  return (
    <Suspense>
      <Order />
    </Suspense>
  );
}

function Order() {
  const { farmId, orderId } = useParams<{ farmId: string; orderId: string }>();
  const search = useSearchParams();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const writable = workspace?.type === "farm";
  const canBuy = writable && can(perms, "procurement.orders.manage");
  const canApprove = writable && can(perms, "procurement.orders.approve");
  const canReceive = writable && can(perms, "procurement.deliveries.receive");
  const seesPrices = can(perms, "procurement.orders.manage|finance.values.view");
  const seesTrace = can(perms, "trace.batches.view");
  const [dialog, setDialog] = useState<string | null>(search.get("action"));
  const [dispatch, setDispatch] = useState<DispatchNotice | null>(null);

  const order = useQuery({
    queryKey: ["purchase-orders", farmId, orderId],
    queryFn: async () => (await api.GET("/farms/{farm}/purchase-orders/{order}", { params: { path: { farm: farmId, order: orderId } } })).data!.data!,
  });
  const refresh = () =>
    Promise.all(["purchase-orders", "supplier-invoices", "purchase-requests", "inventory-items"].map((k) => queryClient.invalidateQueries({ queryKey: [k, farmId] })));
  const { error, busy, run } = useActions(refresh);
  const done = async () => {
    setDialog(null);
    await refresh();
  };

  if (order.isLoading) return <Skeleton className="h-64 w-full" />;
  if (order.error) return <ErrorNotice error={order.error} />;
  const o = order.data!;
  const st = ORDER_STATUS[o.status ?? ""];
  const path = { path: { farm: farmId, order: o.id! } };
  const lines = o.lines ?? [];
  const receivable = RECEIVABLE.includes(o.status ?? "") && lines.some((l) => outstanding(l) > 0);
  const invoiceable = lines.some((l) => toInvoice(l) > 0) && o.status !== "cancelled";

  return (
    <>
      <Link href={`/farms/${farmId}/procurement`} className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" /> Purchasing
      </Link>
      <PageHeader
        title={`${o.code} · ${o.supplier?.name ?? ""}`}
        description={[o.purchase_request ? `from ${o.purchase_request.code}` : null, o.expected_on ? `expected ${o.expected_on}` : null, o.delivery_location ? `to ${o.delivery_location.name}` : null].filter(Boolean).join(" · ")}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={st?.tone ?? "neutral"}>{st?.label ?? o.status}</Badge>
            {canBuy && o.status === "draft" ? (
              <Button variant="secondary" onClick={() => setDialog("edit")}>
                Edit
              </Button>
            ) : null}
            {canApprove && o.status === "draft" ? (
              <Button disabled={busy === "approve"} onClick={() => run("approve", () => api.POST("/farms/{farm}/purchase-orders/{order}/approve", { params: path }))}>
                Approve
              </Button>
            ) : null}
            {canBuy && o.status === "approved" ? (
              <Button disabled={busy === "send"} onClick={() => run("send", () => api.POST("/farms/{farm}/purchase-orders/{order}/send", { params: path }))}>
                Mark as sent
              </Button>
            ) : null}
            {canReceive && receivable ? <Button onClick={() => setDialog("receive")}>Receive delivery</Button> : null}
            {canBuy && invoiceable ? (
              <Button variant="secondary" onClick={() => setDialog("invoice")}>
                Record invoice
              </Button>
            ) : null}
            {canBuy && ["draft", "approved", "sent"].includes(o.status ?? "") && !lines.some((l) => (l.received_quantity ?? 0) > 0) ? (
              <Button variant="ghost" onClick={() => setDialog("cancel")}>
                Cancel order
              </Button>
            ) : null}
            {canBuy && o.status === "partially_received" ? (
              <Button variant="ghost" disabled={busy === "close"} onClick={() => run("close", () => api.POST("/farms/{farm}/purchase-orders/{order}/close", { params: path }))}>
                Close short
              </Button>
            ) : null}
          </div>
        }
      />
      {error ? <div className="mb-4"><ErrorNotice error={error} /></div> : null}
      {o.cancel_reason ? <p className="mb-4 text-sm text-muted">Cancelled: {o.cancel_reason}</p> : null}
      {o.supplier_response ? (
        <p className={`mb-4 text-sm ${o.supplier_response === "rejected" ? "text-danger" : ""}`}>
          {o.supplier?.name} {o.supplier_response === "accepted" ? `accepted in the portal${o.supplier_promised_on ? `, delivering by ${o.supplier_promised_on}` : ""}` : "cannot supply this order"} ({formatDateTime(o.supplier_responded_at)})
          {o.supplier_note ? `: “${o.supplier_note}”` : "."}
        </p>
      ) : o.status === "sent" && o.supplier?.on_portal ? (
        <p className="mb-4 text-sm text-muted">Waiting for {o.supplier.name} to answer in the supplier portal.</p>
      ) : null}

      <Table className="mb-6">
        <thead>
          <tr>
            <Th>Item</Th>
            <Th className="text-right">Ordered</Th>
            <Th className="text-right">Received</Th>
            <Th className="text-right">Invoiced</Th>
            {seesPrices ? <Th className="text-right">Price</Th> : null}
            {seesPrices ? <Th className="text-right">Amount</Th> : null}
          </tr>
        </thead>
        <tbody>
          {lines.map((l) => (
            <tr key={l.id}>
              <Td>
                {l.item?.name}
                {l.description ? <span className="block text-xs text-muted">{l.description}</span> : null}
              </Td>
              <Td className="text-right tabular-nums">{formatQty(l.quantity, l.item?.unit)}</Td>
              <Td className="text-right tabular-nums">{formatQty(l.received_quantity)}</Td>
              <Td className="text-right tabular-nums">{formatQty(l.invoiced_quantity)}</Td>
              {seesPrices ? <Td className="text-right tabular-nums">{formatMoney(l.unit_price, o.currency)}</Td> : null}
              {seesPrices ? <Td className="text-right tabular-nums">{formatMoney(Math.round((l.quantity ?? 0) * (l.unit_price ?? 0) * 100) / 100, o.currency)}</Td> : null}
            </tr>
          ))}
          {seesPrices ? (
            <tr>
              <Td colSpan={5} className="text-right font-medium">
                Total
              </Td>
              <Td className="text-right font-semibold tabular-nums">{formatMoney(o.total_amount, o.currency)}</Td>
            </tr>
          ) : null}
        </tbody>
      </Table>

      <div className="grid gap-6 lg:grid-cols-2">
        <section>
          <h2 className="mb-2 text-lg font-semibold">Deliveries</h2>
          {(o.deliveries ?? []).length === 0 ? (
            <p className="text-sm text-muted">Nothing received yet.</p>
          ) : (
            <ul className="space-y-3">
              {o.deliveries!.map((d) => (
                <li key={d.id} className="rounded-xl border border-border bg-surface p-4 text-sm">
                  <p className="font-medium">
                    {d.code} · {d.received_on}
                  </p>
                  <p className="text-xs text-muted">
                    Into {d.location?.name} by {d.received_by?.name}
                    {d.supplier_reference ? ` · note ${d.supplier_reference}` : ""}
                  </p>
                  <ul className="mt-2">
                    {(d.lines ?? []).map((dl) => {
                      const line = lines.find((l) => l.id === dl.order_line_id);
                      return (
                        <li key={dl.order_line_id} className="flex justify-between gap-2 border-t border-border py-1">
                          <span>
                            {formatQty(dl.quantity, line?.item?.unit)} {line?.item?.name}
                          </span>
                          {dl.lot ? (
                            seesTrace && dl.lot.trace_batch_id ? (
                              <Link href={`/farms/${farmId}/traceability/batches/${dl.lot.trace_batch_id}`} className="text-primary hover:underline">
                                {dl.lot.code}
                                {dl.lot.lot_number ? ` (${dl.lot.lot_number})` : ""}
                              </Link>
                            ) : (
                              <span className="text-muted">{dl.lot.code}</span>
                            )
                          ) : null}
                        </li>
                      );
                    })}
                  </ul>
                </li>
              ))}
            </ul>
          )}
        </section>
        {seesPrices ? (
          <section>
            <h2 className="mb-2 text-lg font-semibold">Invoices</h2>
            {(o.invoices ?? []).length === 0 ? (
              <p className="text-sm text-muted">No invoice recorded yet.</p>
            ) : (
              <ul className="space-y-2">
                {o.invoices!.map((i) => (
                  <li key={i.id} className="flex justify-between gap-3 rounded-xl border border-border bg-surface p-4 text-sm">
                    <span>
                      <span className="font-medium">{i.invoice_number}</span>
                      <span className="block text-xs text-muted">
                        {i.code} · dated {i.invoice_date}
                        {i.due_on ? ` · due ${i.due_on}` : ""}
                      </span>
                    </span>
                    <span className="tabular-nums font-medium">{formatMoney(i.amount, o.currency)}</span>
                  </li>
                ))}
              </ul>
            )}
          </section>
        ) : null}
      </div>
      <SupplierPortalSection
        farmId={farmId}
        order={o}
        canReceive={canReceive && receivable}
        canReview={writable && can(perms, "procurement.orders.manage|finance.manage")}
        onReceive={(d) => setDispatch(d)}
        onChanged={refresh}
      />
      <p className="mt-6 text-xs text-muted">
        Created {formatDateTime(o.created_at)}
        {o.created_by ? ` by ${o.created_by.name}` : ""}
        {o.approved_at ? ` · approved ${formatDateTime(o.approved_at)}${o.approved_by ? ` by ${o.approved_by.name}` : ""}` : ""}
        {o.sent_at ? ` · sent ${formatDateTime(o.sent_at)}` : ""}
      </p>

      {dialog === "edit" && canBuy && o.status === "draft" ? <OrderDialog farmId={farmId} order={o} currency={o.currency ?? ""} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "receive" && canReceive && receivable ? <ReceiveDialog farmId={farmId} order={o} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dispatch && canReceive ? (
        <ReceiveDialog
          farmId={farmId}
          order={o}
          dispatch={dispatch}
          onClose={() => setDispatch(null)}
          onDone={async () => {
            setDispatch(null);
            await done();
          }}
        />
      ) : null}
      {dialog === "invoice" && canBuy && invoiceable ? <InvoiceDialog farmId={farmId} order={o} currency={o.currency ?? ""} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "cancel" && canBuy ? (
        <ReasonDialog
          title={`Cancel ${o.code}`}
          submitLabel="Cancel order"
          field="reason"
          onClose={() => setDialog(null)}
          onSubmit={async (reason) => {
            await api.POST("/farms/{farm}/purchase-orders/{order}/cancel", { params: path, body: { reason } });
            await done();
          }}
        />
      ) : null}
    </>
  );
}
