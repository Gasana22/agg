"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, CheckCircle2, Circle } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { FormDialog, text } from "@/components/forms/form-dialog";
import { ConfirmDeliveryDialog } from "@/components/portal/confirm-delivery";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Label, Textarea } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatDateTime } from "@/lib/format";
import { formatMoney, formatQty } from "@/lib/inventory";
import { SALES_ORDER_STATUS, TIMELINE_LABEL, type PortalShipment } from "@/lib/portal";

export default function CustomerOrderPage() {
  const { partyId, farmId, orderId } = useParams<{ partyId: string; farmId: string; orderId: string }>();
  const queryClient = useQueryClient();
  const [dialog, setDialog] = useState<null | "cancel" | { confirm: PortalShipment }>(null);
  const order = useQuery({
    queryKey: ["customer-order", partyId, orderId],
    queryFn: async () => (await api.GET("/customer/{party}/farms/{farm}/orders/{salesOrder}", { params: { path: { party: partyId, farm: farmId, salesOrder: orderId } } })).data!.data!,
  });
  const done = async () => {
    setDialog(null);
    await queryClient.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith("customer-") });
  };

  if (order.isLoading) return <Skeleton className="h-64 w-full" />;
  if (order.error) return <ErrorNotice error={order.error} />;
  const o = order.data!;
  const st = SALES_ORDER_STATUS[o.status ?? ""] ?? { label: o.status, tone: "neutral" as const };

  return (
    <>
      <Link href={`/customer/${partyId}/orders`} className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" /> My orders
      </Link>
      <PageHeader
        title={`${o.code} · ${o.farm?.name}`}
        description={[o.requested_delivery_on ? `wanted by ${o.requested_delivery_on}` : null, o.delivery_address].filter(Boolean).join(" · ")}
        actions={
          <div className="flex items-center gap-2">
            <Badge tone={st.tone}>{st.label}</Badge>
            {o.status === "requested" ? (
              <Button variant="ghost" onClick={() => setDialog("cancel")}>
                Cancel order
              </Button>
            ) : null}
          </div>
        }
      />
      {o.reject_reason ? <p className="mb-4 text-sm text-danger">The farm declined: {o.reject_reason}</p> : null}
      {o.cancel_reason ? <p className="mb-4 text-sm text-muted">Cancelled: {o.cancel_reason}</p> : null}

      <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
        <div className="space-y-6">
          <Table>
            <thead>
              <tr>
                <Th>Product</Th>
                <Th className="text-right">Ordered</Th>
                <Th className="text-right">Sent</Th>
                <Th className="text-right">Price</Th>
                <Th className="text-right">Amount</Th>
              </tr>
            </thead>
            <tbody>
              {(o.lines ?? []).map((l) => (
                <tr key={l.id}>
                  <Td>{l.description}</Td>
                  <Td className="text-right tabular-nums">{formatQty(l.quantity, l.unit)}</Td>
                  <Td className="text-right tabular-nums">{formatQty(l.dispatched_quantity, l.unit)}</Td>
                  <Td className="text-right tabular-nums">{formatMoney(l.unit_price, o.currency)}</Td>
                  <Td className="text-right tabular-nums">{formatMoney(l.amount, o.currency)}</Td>
                </tr>
              ))}
              <tr>
                <Td colSpan={4} className="text-right font-medium">
                  Total
                </Td>
                <Td className="text-right font-semibold tabular-nums">{formatMoney(o.total_amount, o.currency)}</Td>
              </tr>
            </tbody>
          </Table>

          <section>
            <h2 className="mb-2 text-lg font-semibold">Deliveries</h2>
            {(o.shipments ?? []).length === 0 ? (
              <p className="text-sm text-muted">Nothing sent yet.</p>
            ) : (
              <ul className="space-y-3">
                {o.shipments!.map((s) => (
                  <li key={s.id} className="rounded-xl border border-border bg-surface p-4 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="font-medium">
                        {s.code} · dispatched {formatDateTime(s.dispatched_at)}
                      </p>
                      {s.status === "dispatched" ? (
                        <Button size="sm" onClick={() => setDialog({ confirm: s })}>
                          It arrived
                        </Button>
                      ) : (
                        <Badge tone={s.status === "delivered" ? "success" : "danger"}>{s.status === "delivered" ? "delivered" : "not delivered"}</Badge>
                      )}
                    </div>
                    <p className="text-xs text-muted">
                      {[s.vehicle, s.delivered_at ? `received ${formatDateTime(s.delivered_at)}${s.received_by ? ` by ${s.received_by}` : ""}` : null, s.failure_reason].filter(Boolean).join(" · ")}
                    </p>
                    <ul className="mt-2">
                      {(s.lines ?? []).map((l, i) => (
                        <li key={i} className="border-t border-border py-1">
                          {formatQty(l.quantity, l.unit)} {l.description} <span className="font-mono text-xs text-muted">{l.batch_code}</span>
                        </li>
                      ))}
                    </ul>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>

        <div className="space-y-6">
          <section className="rounded-2xl border border-border bg-surface p-4">
            <h2 className="mb-3 font-semibold">Progress</h2>
            <ol className="space-y-3">
              {(o.timeline ?? []).map((e, i) => (
                <li key={i} className="flex gap-3 text-sm">
                  {e.event === "rejected" || e.event === "cancelled" || e.event === "delivery_failed" ? (
                    <Circle className="mt-0.5 size-4 shrink-0 text-danger" aria-hidden />
                  ) : (
                    <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden />
                  )}
                  <div>
                    <p className="font-medium">{TIMELINE_LABEL[e.event ?? ""] ?? e.event}</p>
                    <p className="text-xs text-muted">
                      {formatDateTime(e.at)}
                      {e.detail ? ` · ${e.detail}` : ""}
                    </p>
                  </div>
                </li>
              ))}
            </ol>
          </section>
          {o.invoice ? (
            <section className="rounded-2xl border border-border bg-surface p-4 text-sm">
              <h2 className="mb-2 font-semibold">Invoice {o.invoice.code}</h2>
              <p>
                {formatMoney(o.invoice.amount, o.currency)}, due {o.invoice.due_on ?? "—"}
              </p>
              <p className="text-muted">
                Paid {formatMoney(o.invoice.paid_amount, o.currency)} · still due {formatMoney(o.invoice.outstanding, o.currency)}
              </p>
            </section>
          ) : null}
          {o.note ? (
            <section className="rounded-2xl border border-border bg-surface p-4 text-sm">
              <h2 className="mb-1 font-semibold">Your note</h2>
              <p className="text-muted">{o.note}</p>
            </section>
          ) : null}
        </div>
      </div>

      {dialog === "cancel" ? (
        <FormDialog
          title={`Cancel ${o.code}?`}
          submitLabel="Cancel order"
          onClose={() => setDialog(null)}
          onSubmit={async (f) => {
            await api.POST("/customer/{party}/farms/{farm}/orders/{salesOrder}/cancel", {
              params: { path: { party: partyId, farm: farmId, salesOrder: orderId } },
              body: { reason: text(f, "reason") ?? "" },
            });
            await done();
          }}
        >
          {(error) => (
            <div>
              <Label htmlFor="reason">Reason</Label>
              <Textarea id="reason" name="reason" rows={2} required minLength={3} />
              {error?.fieldError("reason") ? <p className="text-sm text-danger">{error.fieldError("reason")}</p> : null}
            </div>
          )}
        </FormDialog>
      ) : null}
      {dialog && typeof dialog === "object" ? <ConfirmDeliveryDialog partyId={partyId} shipment={dialog.confirm} onClose={() => setDialog(null)} onDone={done} /> : null}
    </>
  );
}
