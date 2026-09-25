"use client";

import { useState } from "react";

import { useActions } from "@/components/inventory/common";
import { ReasonDialog } from "@/components/inventory/dialogs";
import type { DispatchNotice } from "@/components/procurement/dialogs";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ErrorNotice } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import type { components } from "@/lib/api/schema";
import { formatDateTime } from "@/lib/format";
import { formatMoney, formatQty } from "@/lib/inventory";

type PurchaseOrder = components["schemas"]["PurchaseOrder"];
type Dispatch = DispatchNotice & { status: string; dispatched_on: string; expected_on?: string | null; vehicle?: string | null; media_id?: string | null };
type Submission = {
  id: string;
  code: string;
  status: string;
  invoice_number: string;
  invoice_date: string;
  due_on?: string | null;
  amount: number;
  reject_reason?: string | null;
  submitted_at?: string | null;
  media_id?: string | null;
  lines: { order_line_id: string; quantity: number; unit_price: number }[];
};

/**
 * What the supplier did in the portal: dispatch notices the store receives
 * against, and invoices to record (three-way match) or send back.
 */
export function SupplierPortalSection({
  farmId,
  order,
  canReceive,
  canReview,
  onReceive,
  onChanged,
}: {
  farmId: string;
  order: PurchaseOrder;
  canReceive: boolean;
  canReview: boolean;
  onReceive: (d: DispatchNotice) => void;
  onChanged: () => Promise<unknown>;
}) {
  const dispatches = (order.dispatches ?? []) as unknown as Dispatch[];
  const submissions = ((order.invoice_submissions ?? []) as unknown as Submission[]).filter((s) => s.status !== "recorded");
  const { error, busy, run } = useActions(onChanged);
  const [rejecting, setRejecting] = useState<Submission | null>(null);
  if (dispatches.length === 0 && submissions.length === 0) return null;
  const item = (id: string) => order.lines?.find((l) => l.id === id);
  const media = (id?: string | null) =>
    id ? (
      <a href={`/api/proxy/farms/${farmId}/media/${id}/content`} target="_blank" rel="noreferrer" className="text-primary hover:underline">
        document
      </a>
    ) : null;

  return (
    <section className="mt-6">
      <h2 className="mb-2 text-lg font-semibold">From the supplier portal</h2>
      {error ? (
        <div className="mb-3">
          <ErrorNotice error={error} />
        </div>
      ) : null}
      <ul className="grid gap-3 lg:grid-cols-2">
        {dispatches.map((d) => (
          <li key={d.id} className="rounded-xl border border-border bg-surface p-4 text-sm">
            <div className="flex items-start justify-between gap-2">
              <p className="font-medium">
                Dispatch {d.code} · {d.dispatched_on}
              </p>
              {d.status === "dispatched" && canReceive ? (
                <Button size="sm" onClick={() => onReceive(d)}>
                  Receive
                </Button>
              ) : (
                <Badge tone={d.status === "received" ? "success" : "neutral"}>{d.status === "dispatched" ? "on the way" : d.status}</Badge>
              )}
            </div>
            <p className="text-xs text-muted">
              {[d.reference ? `note ${d.reference}` : null, d.expected_on ? `expected ${d.expected_on}` : null, d.vehicle].filter(Boolean).join(" · ")} {media(d.media_id)}
            </p>
            <ul className="mt-2">
              {d.lines.map((l) => (
                <li key={l.order_line_id} className="border-t border-border py-1">
                  {formatQty(l.quantity, item(l.order_line_id)?.item?.unit)} {item(l.order_line_id)?.item?.name}
                </li>
              ))}
            </ul>
          </li>
        ))}
        {submissions.map((s) => (
          <li key={s.id} className="rounded-xl border border-border bg-surface p-4 text-sm">
            <div className="flex items-start justify-between gap-2">
              <p className="font-medium">
                Invoice {s.invoice_number} · {formatMoney(s.amount, order.currency)}
              </p>
              {s.status === "submitted" && canReview ? (
                <div className="flex gap-1">
                  <Button
                    size="sm"
                    disabled={busy === s.id}
                    onClick={() => run(s.id, () => api.POST("/farms/{farm}/supplier-invoice-submissions/{submission}/record", { params: { path: { farm: farmId, submission: s.id } }, body: {} }))}
                  >
                    Record
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setRejecting(s)}>
                    Send back
                  </Button>
                </div>
              ) : (
                <Badge tone={s.status === "rejected" ? "danger" : "warning"}>{s.status === "rejected" ? "sent back" : "waiting"}</Badge>
              )}
            </div>
            <p className="text-xs text-muted">
              {s.code} · dated {s.invoice_date}
              {s.due_on ? ` · due ${s.due_on}` : ""} · sent {formatDateTime(s.submitted_at)} {media(s.media_id)}
            </p>
            {s.reject_reason ? <p className="text-xs text-danger">{s.reject_reason}</p> : null}
            <ul className="mt-2">
              {s.lines.map((l) => (
                <li key={l.order_line_id} className="flex justify-between gap-2 border-t border-border py-1">
                  <span>
                    {formatQty(l.quantity, item(l.order_line_id)?.item?.unit)} {item(l.order_line_id)?.item?.name} at {formatMoney(l.unit_price, order.currency)}
                  </span>
                  {item(l.order_line_id)?.unit_price !== undefined && item(l.order_line_id)?.unit_price !== l.unit_price ? (
                    <span className="text-xs text-accent">order price {formatMoney(item(l.order_line_id)?.unit_price, order.currency)}</span>
                  ) : null}
                </li>
              ))}
            </ul>
          </li>
        ))}
      </ul>
      {rejecting ? (
        <ReasonDialog
          title={`Send back ${rejecting.invoice_number}`}
          submitLabel="Send back"
          field="reason"
          onClose={() => setRejecting(null)}
          onSubmit={async (reason) => {
            await api.POST("/farms/{farm}/supplier-invoice-submissions/{submission}/reject", { params: { path: { farm: farmId, submission: rejecting.id } }, body: { reason } });
            setRejecting(null);
            await onChanged();
          }}
        />
      ) : null}
    </section>
  );
}
