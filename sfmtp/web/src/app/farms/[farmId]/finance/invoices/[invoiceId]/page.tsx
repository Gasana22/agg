"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { PaymentDialog } from "@/components/finance/dialogs";
import { useActions } from "@/components/inventory/common";
import { ReasonDialog } from "@/components/inventory/dialogs";
import { useFarmCurrency } from "@/components/inventory/queries";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { balanceDue, INVOICE_STATUS, PAYMENT_METHODS } from "@/lib/finance";
import { formatDateTime } from "@/lib/format";
import { formatMoney, formatQty } from "@/lib/inventory";
import { can } from "@/lib/permissions";

export default function InvoicePage() {
  const { farmId, invoiceId } = useParams<{ farmId: string; invoiceId: string }>();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const writable = workspace?.type === "farm";
  const canInvoice = writable && can(perms, "sales.invoice");
  const canCollect = writable && can(perms, "sales.invoice|finance.manage");
  const [dialog, setDialog] = useState<string | null>(null);
  const farmCurrency = useFarmCurrency(farmId);

  const invoice = useQuery({
    queryKey: ["customer-invoices", farmId, invoiceId],
    queryFn: async () => (await api.GET("/farms/{farm}/customer-invoices/{customerInvoice}", { params: { path: { farm: farmId, customerInvoice: invoiceId } } })).data!.data!,
  });
  const payments = useQuery({
    queryKey: ["payments", farmId, "invoice", invoiceId],
    queryFn: async () => (await api.GET("/farms/{farm}/payments", { params: { path: { farm: farmId }, query: { "filter[payable_id]": invoiceId } } })).data!.data!,
  });
  const refresh = () => Promise.all(["customer-invoices", "payments", "ledger-accounts", "open-documents", "billable-sales"].map((k) => queryClient.invalidateQueries({ queryKey: [k, farmId] })));
  const { error, busy, run } = useActions(refresh);

  if (invoice.isLoading) return <Skeleton className="h-64 w-full" />;
  if (invoice.error) return <ErrorNotice error={invoice.error} />;
  const i = invoice.data!;
  const st = INVOICE_STATUS[i.status ?? ""];
  const currency = farmCurrency;
  const path = { path: { farm: farmId, customerInvoice: i.id! } };
  const due = balanceDue(i);

  return (
    <>
      <Link href={`/farms/${farmId}/finance?tab=invoices`} className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" /> Invoices
      </Link>
      <PageHeader
        title={`${i.code} · ${i.customer?.name ?? ""}`}
        description={`Dated ${i.invoice_date}${i.due_on ? ` · due ${i.due_on}` : ""}`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={i.overdue ? "danger" : (st?.tone ?? "neutral")}>{i.overdue ? "Overdue" : (st?.label ?? i.status)}</Badge>
            {canInvoice && i.status === "draft" ? (
              <Button disabled={busy === "issue"} onClick={() => run("issue", () => api.POST("/farms/{farm}/customer-invoices/{customerInvoice}/issue", { params: path }))}>
                Issue
              </Button>
            ) : null}
            {canCollect && i.status === "issued" ? <Button onClick={() => setDialog("pay")}>Record payment</Button> : null}
            {canInvoice && (i.status === "draft" || (i.status === "issued" && (i.paid_amount ?? 0) === 0)) ? (
              <Button variant="ghost" onClick={() => setDialog("void")}>
                Void
              </Button>
            ) : null}
          </div>
        }
      />
      {error ? <div className="mb-4"><ErrorNotice error={error} /></div> : null}
      {i.void_reason ? <p className="mb-4 text-sm text-muted">Voided: {i.void_reason}</p> : null}
      <Table className="mb-4">
        <thead>
          <tr>
            <Th>Description</Th>
            <Th className="text-right">Quantity</Th>
            <Th className="text-right">Price</Th>
            <Th className="text-right">Amount</Th>
          </tr>
        </thead>
        <tbody>
          {(i.lines ?? []).map((l) => (
            <tr key={l.id}>
              <Td>
                {l.description}
                <span className="block text-xs text-muted">
                  {l.account?.code} {l.account?.name}
                  {l.cost_center ? ` · ${l.cost_center.label}` : ""}
                </span>
              </Td>
              <Td className="text-right tabular-nums">{formatQty(l.quantity, l.unit)}</Td>
              <Td className="text-right tabular-nums">{formatMoney(l.unit_price, currency)}</Td>
              <Td className="text-right tabular-nums">{formatMoney(l.amount, currency)}</Td>
            </tr>
          ))}
          <tr>
            <Td colSpan={3} className="text-right font-medium">
              Total
            </Td>
            <Td className="text-right font-semibold tabular-nums">{formatMoney(i.amount, currency)}</Td>
          </tr>
          {i.status === "issued" || i.status === "paid" ? (
            <tr>
              <Td colSpan={3} className="text-right text-muted">
                Still to pay
              </Td>
              <Td className="text-right tabular-nums">{formatMoney(due, currency)}</Td>
            </tr>
          ) : null}
        </tbody>
      </Table>
      {i.notes ? <p className="mb-4 text-sm text-muted">{i.notes}</p> : null}

      <h2 className="mb-2 text-lg font-semibold">Payments</h2>
      {(payments.data ?? []).length === 0 ? (
        <p className="text-sm text-muted">No payments yet.</p>
      ) : (
        <ul className="space-y-2">
          {payments.data!.map((p) => (
            <li key={p.id} className={`flex justify-between gap-3 rounded-xl border border-border bg-surface p-3 text-sm ${p.status === "void" ? "text-muted line-through" : ""}`}>
              <span>
                {p.code} · {p.paid_on} · {PAYMENT_METHODS.find((m) => m.key === p.method)?.label}
                {p.reference ? ` · ${p.reference}` : ""}
              </span>
              <span className="tabular-nums font-medium">{formatMoney(p.amount, currency)}</span>
            </li>
          ))}
        </ul>
      )}
      <p className="mt-6 text-xs text-muted">
        {i.created_by ? `Drafted by ${i.created_by.name}` : ""}
        {i.issued_at ? ` · issued ${formatDateTime(i.issued_at)}${i.issued_by ? ` by ${i.issued_by.name}` : ""}` : ""}
      </p>

      {dialog === "pay" ? (
        <PaymentDialog
          farmId={farmId}
          currency={currency}
          types={["customer_invoice"]}
          preset={{ type: "customer_invoice", id: i.id!, label: `${i.code} ${i.customer?.name ?? ""}`, due }}
          onClose={() => setDialog(null)}
          onDone={async () => { setDialog(null); await refresh(); }}
        />
      ) : null}
      {dialog === "void" ? (
        <ReasonDialog
          title={`Void ${i.code}`}
          submitLabel="Void invoice"
          field="reason"
          onClose={() => setDialog(null)}
          onSubmit={async (reason) => {
            await api.POST("/farms/{farm}/customer-invoices/{customerInvoice}/void", { params: path, body: { reason } });
            setDialog(null);
            await refresh();
          }}
        />
      ) : null}
    </>
  );
}
