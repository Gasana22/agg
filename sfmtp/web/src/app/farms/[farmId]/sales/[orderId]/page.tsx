"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { FormDialog, text } from "@/components/forms/form-dialog";
import { useActions } from "@/components/inventory/common";
import { DispatchOrderDialog } from "@/components/sales/dialogs";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Label, Textarea } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { formatDateTime } from "@/lib/format";
import { formatMoney, formatQty } from "@/lib/inventory";
import { can } from "@/lib/permissions";
import { SALES_ORDER_STATUS } from "@/lib/portal";

export default function SalesOrderPage() {
  const { farmId, orderId } = useParams<{ farmId: string; orderId: string }>();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const writable = workspace?.type === "farm";
  const canDecide = writable && can(perms, "sales.orders.create|sales.orders.approve");
  const canInvoice = writable && can(perms, "sales.invoice");
  const canFulfil = writable && can(perms, "sales.fulfil");
  const [dialog, setDialog] = useState<null | "reject" | "cancel" | "dispatch">(null);
  const order = useQuery({
    queryKey: ["sales-orders", farmId, orderId],
    queryFn: async () => (await api.GET("/farms/{farm}/sales-orders/{salesOrder}", { params: { path: { farm: farmId, salesOrder: orderId } } })).data!.data!,
  });
  const refresh = () => queryClient.invalidateQueries({ predicate: (q) => ["sales-orders", "customer-invoices", "shipments", "batches"].includes(String(q.queryKey[0])) });
  const { error, busy, run } = useActions(refresh);
  const done = async () => {
    setDialog(null);
    await refresh();
  };

  if (order.isLoading) return <Skeleton className="h-64 w-full" />;
  if (order.error) return <ErrorNotice error={order.error} />;
  const o = order.data!;
  const st = SALES_ORDER_STATUS[o.status ?? ""] ?? { label: o.status, tone: "neutral" as const };
  const path = { path: { farm: farmId, salesOrder: o.id! } };
  const status = o.status ?? "";
  const toSend = (o.lines ?? []).some((l) => (l.quantity ?? 0) > (l.dispatched_quantity ?? 0));
  const seesMoney = o.total_amount !== undefined;

  return (
    <>
      <Link href={`/farms/${farmId}/sales`} className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" /> Sales
      </Link>
      <PageHeader
        title={`${o.code} · ${o.customer?.name}`}
        description={[o.source === "portal" ? "ordered in the customer portal" : `recorded by ${o.placed_by?.name ?? "staff"}`, o.requested_delivery_on ? `wanted by ${o.requested_delivery_on}` : null, o.delivery_address].filter(Boolean).join(" · ")}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={st.tone}>{st.label}</Badge>
            {canDecide && status === "requested" ? (
              <>
                <Button disabled={busy === "approve"} onClick={() => run("approve", () => api.POST("/farms/{farm}/sales-orders/{salesOrder}/approve", { params: path }))}>
                  Approve
                </Button>
                <Button variant="secondary" onClick={() => setDialog("reject")}>
                  Decline
                </Button>
              </>
            ) : null}
            {canInvoice && ["approved", "dispatched", "delivered"].includes(status) && (!o.invoice || o.invoice.status === "void") ? (
              <Button variant="secondary" disabled={busy === "invoice"} onClick={() => run("invoice", () => api.POST("/farms/{farm}/sales-orders/{salesOrder}/invoice", { params: path }))}>
                Draft invoice
              </Button>
            ) : null}
            {canFulfil && ["approved", "invoiced", "dispatched"].includes(status) && toSend ? <Button onClick={() => setDialog("dispatch")}>Dispatch</Button> : null}
            {canDecide && ["requested", "approved"].includes(status) ? (
              <Button variant="ghost" onClick={() => setDialog("cancel")}>
                Cancel
              </Button>
            ) : null}
          </div>
        }
      />
      {error ? (
        <div className="mb-4">
          <ErrorNotice error={error} />
        </div>
      ) : null}
      {o.reject_reason ? <p className="mb-4 text-sm text-danger">Declined: {o.reject_reason}</p> : null}
      {o.cancel_reason ? <p className="mb-4 text-sm text-muted">Cancelled: {o.cancel_reason}</p> : null}
      {o.customer_note ? <p className="mb-2 text-sm">Customer note: “{o.customer_note}”</p> : null}
      {o.internal_note ? <p className="mb-4 text-sm text-muted">Internal note: {o.internal_note}</p> : null}
      {o.invoice ? (
        <p className="mb-4 text-sm">
          Invoice{" "}
          <Link href={`/farms/${farmId}/finance/invoices/${o.invoice.id}`} className="font-medium text-primary hover:underline">
            {o.invoice.code}
          </Link>{" "}
          ({o.invoice.status})
        </p>
      ) : null}

      <Table className="mb-6">
        <thead>
          <tr>
            <Th>Product</Th>
            <Th className="text-right">Ordered</Th>
            <Th className="text-right">Sent</Th>
            {seesMoney ? <Th className="text-right">Price</Th> : null}
            {seesMoney ? <Th className="text-right">Amount</Th> : null}
          </tr>
        </thead>
        <tbody>
          {(o.lines ?? []).map((l) => (
            <tr key={l.id}>
              <Td>{l.description}</Td>
              <Td className="text-right tabular-nums">{formatQty(l.quantity, l.unit)}</Td>
              <Td className="text-right tabular-nums">{formatQty(l.dispatched_quantity, l.unit)}</Td>
              {seesMoney ? <Td className="text-right tabular-nums">{formatMoney(l.unit_price, o.currency)}</Td> : null}
              {seesMoney ? <Td className="text-right tabular-nums">{formatMoney(l.amount, o.currency)}</Td> : null}
            </tr>
          ))}
          {seesMoney ? (
            <tr>
              <Td colSpan={4} className="text-right font-medium">
                Total
              </Td>
              <Td className="text-right font-semibold tabular-nums">{formatMoney(o.total_amount, o.currency)}</Td>
            </tr>
          ) : null}
        </tbody>
      </Table>

      <h2 className="mb-2 text-lg font-semibold">Shipments</h2>
      {(o.shipments ?? []).length === 0 ? (
        <p className="text-sm text-muted">Nothing dispatched yet.</p>
      ) : (
        <ul className="space-y-2">
          {o.shipments!.map((s) => (
            <li key={s.id} className="rounded-xl border border-border bg-surface p-4 text-sm">
              <Link href={`/farms/${farmId}/shipments?shipment=${s.id}`} className="font-medium text-primary hover:underline">
                {s.code}
              </Link>{" "}
              · {formatDateTime(s.dispatched_at)} · <Badge tone={s.status === "delivered" ? "success" : s.status === "failed" ? "danger" : "warning"}>{s.status}</Badge>
              <p className="text-xs text-muted">{(s.lines ?? []).map((l) => `${l.batch?.batch_code} ${l.quantity ? `${l.quantity.value} ${l.quantity.unit ?? ""}` : ""}`).join(", ")}</p>
            </li>
          ))}
        </ul>
      )}

      {dialog === "reject" || dialog === "cancel" ? (
        <FormDialog
          title={dialog === "reject" ? `Decline ${o.code}` : `Cancel ${o.code}`}
          description={o.source === "portal" ? "The customer sees the reason in the portal." : undefined}
          submitLabel={dialog === "reject" ? "Decline" : "Cancel order"}
          onClose={() => setDialog(null)}
          onSubmit={async (f) => {
            const body = { reason: text(f, "reason") ?? "" };
            if (dialog === "reject") await api.POST("/farms/{farm}/sales-orders/{salesOrder}/reject", { params: path, body });
            else await api.POST("/farms/{farm}/sales-orders/{salesOrder}/cancel", { params: path, body });
            await done();
          }}
        >
          {() => (
            <div>
              <Label htmlFor="reason">Reason</Label>
              <Textarea id="reason" name="reason" rows={2} required minLength={3} />
            </div>
          )}
        </FormDialog>
      ) : null}
      {dialog === "dispatch" ? <DispatchOrderDialog farmId={farmId} order={o} onClose={() => setDialog(null)} onDone={done} /> : null}
    </>
  );
}
