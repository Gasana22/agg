"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useParams } from "next/navigation";

import { Badge } from "@/components/ui/badge";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatMoney } from "@/lib/inventory";

type Row = {
  type?: string;
  farm?: { id?: string; name?: string };
  order?: { id?: string; code?: string };
  currency?: string;
  invoice_number?: string;
  invoice_date?: string;
  due_on?: string | null;
  amount?: number;
  paid_amount?: number;
  outstanding?: number;
  status?: string;
  reject_reason?: string | null;
};

const TONE: Record<string, { label: string; tone: "warning" | "primary" | "success" | "danger" | "neutral" }> = {
  submitted: { label: "Waiting for the farm", tone: "warning" },
  rejected: { label: "Sent back", tone: "danger" },
  recorded: { label: "Recorded, not yet paid", tone: "primary" },
  paid: { label: "Paid", tone: "success" },
  cancelled: { label: "Cancelled", tone: "neutral" },
};

export default function SupplierInvoicesPage() {
  const { partyId } = useParams<{ partyId: string }>();
  const rows = useQuery({
    queryKey: ["supplier-invoices", partyId],
    queryFn: async () => ((await api.GET("/supplier/{party}/invoices", { params: { path: { party: partyId } } })).data!.data ?? []) as Row[],
  });

  return (
    <>
      <PageHeader title="Invoices & payments" description="Invoices you sent through the portal, what each farm recorded and what it has paid." />
      {rows.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : rows.error ? (
        <ErrorNotice error={rows.error} />
      ) : (rows.data ?? []).length === 0 ? (
        <EmptyState title="No invoices yet">Send an invoice from an order once the farm has received the goods.</EmptyState>
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Invoice</Th>
              <Th>Farm · order</Th>
              <Th>Due</Th>
              <Th className="text-right">Amount</Th>
              <Th className="text-right">Paid</Th>
              <Th className="text-right">Outstanding</Th>
              <Th>Status</Th>
            </tr>
          </thead>
          <tbody>
            {rows.data!.map((r, i) => {
              const st = TONE[r.status ?? ""] ?? { label: r.status ?? "", tone: "neutral" as const };
              return (
                <tr key={`${r.type}-${r.invoice_number}-${i}`} className="align-top">
                  <Td>
                    <p className="font-medium">{r.invoice_number}</p>
                    <p className="text-xs text-muted">{r.invoice_date}</p>
                  </Td>
                  <Td>
                    {r.farm?.name}
                    <Link href={`/supplier/${partyId}/orders/${r.farm?.id}/${r.order?.id}`} className="block text-xs text-primary hover:underline">
                      {r.order?.code}
                    </Link>
                  </Td>
                  <Td>{r.due_on ?? "—"}</Td>
                  <Td className="text-right tabular-nums">{formatMoney(r.amount, r.currency)}</Td>
                  <Td className="text-right tabular-nums">{r.type === "supplier_invoice" ? formatMoney(r.paid_amount, r.currency) : "—"}</Td>
                  <Td className="text-right tabular-nums">{r.type === "supplier_invoice" ? formatMoney(r.outstanding, r.currency) : "—"}</Td>
                  <Td>
                    <Badge tone={st.tone}>{st.label}</Badge>
                    {r.reject_reason ? <p className="mt-1 text-xs text-danger">{r.reject_reason}</p> : null}
                  </Td>
                </tr>
              );
            })}
          </tbody>
        </Table>
      )}
    </>
  );
}
