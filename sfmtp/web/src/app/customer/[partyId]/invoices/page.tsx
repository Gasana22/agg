"use client";

import { useQuery } from "@tanstack/react-query";
import { useParams } from "next/navigation";

import { Badge } from "@/components/ui/badge";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatMoney } from "@/lib/inventory";

const TONE = { issued: ["To pay", "warning"], paid: ["Paid", "success"], void: ["Cancelled", "neutral"] } as const;

export default function CustomerInvoicesPage() {
  const { partyId } = useParams<{ partyId: string }>();
  const rows = useQuery({
    queryKey: ["customer-invoices", partyId],
    queryFn: async () => (await api.GET("/customer/{party}/invoices", { params: { path: { party: partyId } } })).data!.data ?? [],
  });

  return (
    <>
      <PageHeader title="Invoices" description="Invoices the farms have issued to you, and what is still due. Pay the farm as agreed; online payment comes later." />
      {rows.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : rows.error ? (
        <ErrorNotice error={rows.error} />
      ) : (rows.data ?? []).length === 0 ? (
        <EmptyState title="No invoices yet" />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Invoice</Th>
              <Th>Farm</Th>
              <Th>For</Th>
              <Th>Due</Th>
              <Th className="text-right">Amount</Th>
              <Th className="text-right">Still due</Th>
              <Th>Status</Th>
            </tr>
          </thead>
          <tbody>
            {rows.data!.map((i) => {
              const [label, tone] = TONE[(i.status ?? "issued") as keyof typeof TONE] ?? ["", "neutral"];
              return (
                <tr key={i.id} className="align-top">
                  <Td>
                    <p className="font-medium">{i.code}</p>
                    <p className="text-xs text-muted">{i.invoice_date}</p>
                  </Td>
                  <Td>{i.farm?.name}</Td>
                  <Td className="text-xs">{(i.lines ?? []).map((l) => `${l.quantity} ${l.unit ?? ""} ${l.description}`).join(", ")}</Td>
                  <Td>{i.due_on ?? "—"}</Td>
                  <Td className="text-right tabular-nums">{formatMoney(i.amount, i.currency)}</Td>
                  <Td className="text-right tabular-nums">{formatMoney(i.outstanding, i.currency)}</Td>
                  <Td>
                    <Badge tone={tone}>{label}</Badge>
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
