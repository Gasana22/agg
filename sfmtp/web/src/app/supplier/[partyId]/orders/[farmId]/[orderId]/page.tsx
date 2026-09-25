"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { FormDialog, num, text } from "@/components/forms/form-dialog";
import { uploadDocument } from "@/components/portal/supplier";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatDateTime } from "@/lib/format";
import { formatMoney, formatQty } from "@/lib/inventory";
import { pendingSubmitted, stillExpected, stillToInvoice, supplierOrderState, type PortalOrder } from "@/lib/portal";

type Dialog = "respond" | "dispatch" | "invoice" | null;

export default function SupplierOrderPage() {
  const { partyId, farmId, orderId } = useParams<{ partyId: string; farmId: string; orderId: string }>();
  const queryClient = useQueryClient();
  const [dialog, setDialog] = useState<Dialog>(null);
  const path = { party: partyId, farm: farmId, po: orderId };
  const order = useQuery({
    queryKey: ["supplier-order", partyId, orderId],
    queryFn: async () => (await api.GET("/supplier/{party}/farms/{farm}/orders/{po}", { params: { path } })).data!.data!,
  });
  const done = async () => {
    setDialog(null);
    await queryClient.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith("supplier-") });
  };

  if (order.isLoading) return <Skeleton className="h-64 w-full" />;
  if (order.error) return <ErrorNotice error={order.error} />;
  const o = order.data!;
  const st = supplierOrderState(o);
  const lines = o.lines ?? [];
  const pending = pendingSubmitted(o);
  const canRespond = o.status === "sent";
  const canDispatch = ["sent", "partially_received"].includes(o.status ?? "") && o.supplier_response !== "rejected" && lines.some((l) => stillExpected(l) > 0);
  const canInvoice = ["partially_received", "received", "closed"].includes(o.status ?? "") && lines.some((l) => stillToInvoice(l, pending[l.id!]) > 0);

  return (
    <>
      <Link href={`/supplier/${partyId}/orders`} className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" /> Purchase orders
      </Link>
      <PageHeader
        title={`${o.code} · ${o.farm?.name}`}
        description={[o.sent_at ? `sent ${formatDateTime(o.sent_at)}` : null, o.expected_on ? `wanted by ${o.expected_on}` : null, o.delivery_location ? `deliver to ${o.delivery_location}` : null].filter(Boolean).join(" · ")}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={st.tone}>{st.label}</Badge>
            {canRespond ? <Button onClick={() => setDialog("respond")}>{o.supplier_response ? "Change answer" : "Answer the order"}</Button> : null}
            {canDispatch ? (
              <Button variant={canRespond ? "secondary" : "primary"} onClick={() => setDialog("dispatch")}>
                Announce dispatch
              </Button>
            ) : null}
            {canInvoice ? (
              <Button variant="secondary" onClick={() => setDialog("invoice")}>
                Send invoice
              </Button>
            ) : null}
          </div>
        }
      />
      {o.supplier_response ? (
        <p className="mb-4 text-sm text-muted">
          You {o.supplier_response === "accepted" ? `accepted${o.supplier_promised_on ? `, delivering by ${o.supplier_promised_on}` : ""}` : "declined"} on {formatDateTime(o.supplier_responded_at)}
          {o.supplier_note ? `: “${o.supplier_note}”` : "."}
        </p>
      ) : null}
      {o.cancel_reason ? <p className="mb-4 text-sm text-danger">The farm cancelled this order: {o.cancel_reason}</p> : null}

      <Table className="mb-6">
        <thead>
          <tr>
            <Th>Item</Th>
            <Th className="text-right">Ordered</Th>
            <Th className="text-right">Confirmed</Th>
            <Th className="text-right">Received</Th>
            <Th className="text-right">On the way</Th>
            <Th className="text-right">Invoiced</Th>
            <Th className="text-right">Price</Th>
            <Th className="text-right">Amount</Th>
          </tr>
        </thead>
        <tbody>
          {lines.map((l) => (
            <tr key={l.id}>
              <Td>
                {l.item}
                {l.description ? <span className="block text-xs text-muted">{l.description}</span> : null}
              </Td>
              <Td className="text-right tabular-nums">{formatQty(l.quantity, l.unit)}</Td>
              <Td className="text-right tabular-nums">{formatQty(l.confirmed_quantity)}</Td>
              <Td className="text-right tabular-nums">{formatQty(l.received_quantity)}</Td>
              <Td className="text-right tabular-nums">{formatQty(l.on_the_way)}</Td>
              <Td className="text-right tabular-nums">{formatQty(l.invoiced_quantity)}</Td>
              <Td className="text-right tabular-nums">{formatMoney(l.unit_price, o.currency)}</Td>
              <Td className="text-right tabular-nums">{formatMoney(Math.round((l.quantity ?? 0) * (l.unit_price ?? 0) * 100) / 100, o.currency)}</Td>
            </tr>
          ))}
          <tr>
            <Td colSpan={7} className="text-right font-medium">
              Total
            </Td>
            <Td className="text-right font-semibold tabular-nums">{formatMoney(o.total_amount, o.currency)}</Td>
          </tr>
        </tbody>
      </Table>

      <div className="grid gap-6 lg:grid-cols-3">
        <Section title="Dispatches" empty="Nothing announced yet.">
          {(o.dispatches ?? []).map((d) => (
            <Row key={d.id} title={`${d.code} · ${d.dispatched_on}`} badge={d.status === "received" ? "received" : d.status === "cancelled" ? "cancelled" : "on the way"}>
              {[d.reference ? `note ${d.reference}` : null, d.expected_on ? `expected ${d.expected_on}` : null, d.vehicle].filter(Boolean).join(" · ")}
              <LineList order={o} lines={d.lines ?? []} />
            </Row>
          ))}
        </Section>
        <Section title="Received by the farm" empty="Nothing received yet.">
          {(o.deliveries ?? []).map((d) => (
            <Row key={d.code} title={`${d.code} · ${d.received_on}`}>
              {d.supplier_reference ? `your note ${d.supplier_reference}` : null}
              <LineList order={o} lines={d.lines ?? []} />
            </Row>
          ))}
        </Section>
        <Section title="Invoices" empty="No invoice yet.">
          {(o.submissions ?? []).filter((s) => s.status !== "recorded").map((s) => (
            <Row key={s.id} title={`${s.invoice_number} · ${formatMoney(s.amount, o.currency)}`} badge={s.status === "submitted" ? "waiting for the farm" : "sent back"}>
              {s.reject_reason ? <span className="text-danger">{s.reject_reason}</span> : `sent ${formatDateTime(s.submitted_at)}`}
            </Row>
          ))}
          {(o.invoices ?? []).map((i) => (
            <Row key={i.invoice_number} title={`${i.invoice_number} · ${formatMoney(i.amount, o.currency)}`} badge={i.status === "paid" ? "paid" : i.status === "cancelled" ? "cancelled" : "recorded"}>
              {i.status === "recorded" ? `${formatMoney(i.paid_amount, o.currency)} paid, ${formatMoney(i.outstanding, o.currency)} due ${i.due_on ?? ""}` : null}
            </Row>
          ))}
        </Section>
      </div>

      {dialog === "respond" ? <RespondDialog order={o} path={path} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "dispatch" ? <DispatchDialog order={o} path={path} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "invoice" ? <InvoiceDialog order={o} path={path} pending={pending} onClose={() => setDialog(null)} onDone={done} /> : null}
    </>
  );
}

type Path = { party: string; farm: string; po: string };
type DialogProps = { order: PortalOrder; path: Path; onClose: () => void; onDone: () => Promise<void> };

function Section({ title, empty, children }: { title: string; empty: string; children: React.ReactNode[] }) {
  const items = children.flat().filter(Boolean);
  return (
    <section>
      <h2 className="mb-2 text-lg font-semibold">{title}</h2>
      {items.length === 0 ? <p className="text-sm text-muted">{empty}</p> : <ul className="space-y-3">{items}</ul>}
    </section>
  );
}

function Row({ title, badge, children }: { title: string; badge?: string; children?: React.ReactNode }) {
  return (
    <li className="rounded-xl border border-border bg-surface p-4 text-sm">
      <div className="flex items-start justify-between gap-2">
        <p className="font-medium">{title}</p>
        {badge ? <Badge tone="neutral">{badge}</Badge> : null}
      </div>
      <div className="mt-1 text-xs text-muted">{children}</div>
    </li>
  );
}

function LineList({ order, lines }: { order: PortalOrder; lines: { order_line_id?: string; quantity?: number }[] }) {
  return (
    <ul className="mt-2">
      {lines.map((l) => {
        const line = order.lines?.find((x) => x.id === l.order_line_id);
        return (
          <li key={l.order_line_id} className="border-t border-border py-1 text-foreground">
            {formatQty(l.quantity, line?.unit)} {line?.item}
          </li>
        );
      })}
    </ul>
  );
}

function RespondDialog({ order, path, onClose, onDone }: DialogProps) {
  const [decision, setDecision] = useState<"accepted" | "rejected">(order.supplier_response === "rejected" ? "rejected" : "accepted");
  return (
    <FormDialog
      title={`Answer ${order.code}`}
      description="Accept with the quantities you can supply, or tell the farm you cannot. The farm's buyers are notified."
      submitLabel={decision === "accepted" ? "Accept order" : "Decline order"}
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/supplier/{party}/farms/{farm}/orders/{po}/respond", {
          params: { path },
          body: {
            decision,
            note: text(f, "note"),
            promised_on: decision === "accepted" ? text(f, "promised_on") : null,
            lines: decision === "accepted" ? (order.lines ?? []).map((l) => ({ line_id: l.id!, confirmed_quantity: num(f, `q-${l.id}`) ?? 0 })) : undefined,
          },
        });
        await onDone();
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="decision">Your answer</Label>
            <Select id="decision" value={decision} onChange={(e) => setDecision(e.target.value as "accepted" | "rejected")}>
              <option value="accepted">I can supply it</option>
              <option value="rejected">I cannot supply it</option>
            </Select>
          </div>
          {decision === "accepted" ? (
            <>
              {(order.lines ?? []).map((l) => (
                <div key={l.id}>
                  <Label htmlFor={`q-${l.id}`}>
                    {l.item} (ordered {formatQty(l.quantity, l.unit)})
                  </Label>
                  <Input id={`q-${l.id}`} name={`q-${l.id}`} type="number" step="0.001" min={0} max={l.quantity} defaultValue={l.confirmed_quantity ?? l.quantity} required />
                </div>
              ))}
              <div>
                <Label htmlFor="promised_on">Delivery date</Label>
                <Input id="promised_on" name="promised_on" type="date" defaultValue={order.supplier_promised_on ?? order.expected_on ?? ""} />
                <FieldError>{error?.fieldError("promised_on")}</FieldError>
              </div>
            </>
          ) : null}
          <div>
            <Label htmlFor="note">{decision === "accepted" ? "Note to the farm (optional)" : "Why not?"}</Label>
            <Textarea id="note" name="note" rows={2} required={decision === "rejected"} defaultValue={order.supplier_note ?? ""} />
            <FieldError>{error?.fieldError("note")}</FieldError>
          </div>
        </>
      )}
    </FormDialog>
  );
}

function DispatchDialog({ order, path, onClose, onDone }: DialogProps) {
  const lines = (order.lines ?? []).filter((l) => stillExpected(l) > 0);
  return (
    <FormDialog
      title="Announce a dispatch"
      description="Tell the farm what is on the way. The store receives against it."
      submitLabel="Announce"
      onClose={onClose}
      onSubmit={async (f) => {
        const file = f.get("document");
        const media = file instanceof File && file.size > 0 ? await uploadDocument(path.party, path.farm, file) : null;
        await api.POST("/supplier/{party}/farms/{farm}/orders/{po}/dispatches", {
          params: { path },
          body: {
            reference: text(f, "reference"),
            expected_on: text(f, "expected_on"),
            vehicle: text(f, "vehicle"),
            driver: text(f, "driver"),
            note: text(f, "note"),
            media_id: media,
            lines: lines.map((l) => ({ order_line_id: l.id!, quantity: num(f, `q-${l.id}`) ?? 0 })).filter((l) => l.quantity > 0),
          },
        });
        await onDone();
      }}
    >
      {(error) => (
        <>
          {lines.map((l, i) => (
            <div key={l.id}>
              <Label htmlFor={`q-${l.id}`}>
                {l.item} (up to {formatQty(stillExpected(l), l.unit)})
              </Label>
              <Input id={`q-${l.id}`} name={`q-${l.id}`} type="number" step="0.001" min={0} max={stillExpected(l)} defaultValue={stillExpected(l)} />
              <FieldError>{error?.fieldError(`lines.${i}.quantity`)}</FieldError>
            </div>
          ))}
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <Label htmlFor="reference">Delivery note number</Label>
              <Input id="reference" name="reference" />
            </div>
            <div>
              <Label htmlFor="expected_on">Arriving on</Label>
              <Input id="expected_on" name="expected_on" type="date" />
            </div>
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
            <Label htmlFor="document">Delivery note (photo or PDF)</Label>
            <Input id="document" name="document" type="file" accept="image/*,application/pdf" />
            <FieldError>{error?.fieldError("file")}</FieldError>
          </div>
          <div>
            <Label htmlFor="note">Note</Label>
            <Textarea id="note" name="note" rows={2} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

function InvoiceDialog({ order, path, pending, onClose, onDone }: DialogProps & { pending: Record<string, number> }) {
  const lines = (order.lines ?? []).filter((l) => stillToInvoice(l, pending[l.id!]) > 0);
  const today = new Date().toISOString().slice(0, 10);
  return (
    <FormDialog
      title="Send an invoice"
      description="For goods the farm has received. The farm checks it against the order and deliveries, then records it or sends it back."
      submitLabel="Send invoice"
      onClose={onClose}
      onSubmit={async (f) => {
        const file = f.get("document");
        const media = file instanceof File && file.size > 0 ? await uploadDocument(path.party, path.farm, file) : null;
        await api.POST("/supplier/{party}/farms/{farm}/orders/{po}/invoices", {
          params: { path },
          body: {
            invoice_number: text(f, "invoice_number") ?? "",
            invoice_date: text(f, "invoice_date") ?? today,
            due_on: text(f, "due_on"),
            notes: text(f, "notes"),
            media_id: media,
            lines: lines
              .map((l) => ({ order_line_id: l.id!, quantity: num(f, `q-${l.id}`) ?? 0, unit_price: num(f, `p-${l.id}`) ?? 0 }))
              .filter((l) => l.quantity > 0),
          },
        });
        await onDone();
      }}
    >
      {(error) => (
        <>
          <div className="grid gap-3 sm:grid-cols-3">
            <div>
              <Label htmlFor="invoice_number">Invoice number</Label>
              <Input id="invoice_number" name="invoice_number" required />
              <FieldError>{error?.fieldError("invoice_number")}</FieldError>
            </div>
            <div>
              <Label htmlFor="invoice_date">Date</Label>
              <Input id="invoice_date" name="invoice_date" type="date" defaultValue={today} max={today} required />
            </div>
            <div>
              <Label htmlFor="due_on">Due</Label>
              <Input id="due_on" name="due_on" type="date" />
            </div>
          </div>
          {lines.map((l, i) => (
            <div key={l.id} className="grid gap-3 sm:grid-cols-2">
              <div>
                <Label htmlFor={`q-${l.id}`}>
                  {l.item} (up to {formatQty(stillToInvoice(l, pending[l.id!]), l.unit)})
                </Label>
                <Input id={`q-${l.id}`} name={`q-${l.id}`} type="number" step="0.001" min={0} defaultValue={stillToInvoice(l, pending[l.id!])} />
                <FieldError>{error?.fieldError(`lines.${i}.quantity`)}</FieldError>
              </div>
              <div>
                <Label htmlFor={`p-${l.id}`}>Unit price ({order.currency})</Label>
                <Input id={`p-${l.id}`} name={`p-${l.id}`} type="number" step="0.01" min={0} defaultValue={l.unit_price} />
              </div>
            </div>
          ))}
          <div>
            <Label htmlFor="document">Invoice document (PDF or photo)</Label>
            <Input id="document" name="document" type="file" accept="application/pdf,image/*" />
          </div>
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" rows={2} />
          </div>
        </>
      )}
    </FormDialog>
  );
}
