"use client";

import { useQuery } from "@tanstack/react-query";
import { CreditCard } from "lucide-react";
import { useParams } from "next/navigation";
import { useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatMoney } from "@/lib/inventory";

const TONE = { issued: ["To pay", "warning"], paid: ["Paid", "success"], void: ["Cancelled", "neutral"] } as const;

export default function CustomerInvoicesPage() {
  const { partyId } = useParams<{ partyId: string }>();
  const [paying, setPaying] = useState<string | null>(null);
  const [error, setError] = useState<unknown>(null);

  // Opens the gateway's checkout; the payment is recorded once the gateway confirms it (ADR-0018).
  async function pay(farmId: string, invoiceId: string) {
    setError(null);
    setPaying(invoiceId);
    try {
      const res = await api.POST("/customer/{party}/farms/{farm}/invoices/{invoiceId}/pay", { params: { path: { party: partyId, farm: farmId, invoiceId } } });
      const url = res.data?.data?.checkout_url;
      if (url) window.location.assign(url);
    } catch (e) {
      setError(e);
      setPaying(null);
    }
  }
  const rows = useQuery({
    queryKey: ["customer-invoices", partyId],
    queryFn: async () => (await api.GET("/customer/{party}/invoices", { params: { path: { party: partyId } } })).data!.data ?? [],
  });

  return (
    <>
      <PageHeader title="Invoices" description="Invoices the farms have issued to you, and what is still due. Pay online with mobile money or card where the farm offers it, or pay the farm as agreed." />
      {error ? <div className="mb-4"><ErrorNotice error={error} /></div> : null}
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
                    {i.pay_online && (i.outstanding ?? 0) > 0 ? (
                      <Button size="sm" className="mt-2" disabled={paying !== null} onClick={() => pay(i.farm!.id!, i.id!)}>
                        <CreditCard /> {paying === i.id ? "Opening…" : "Pay now"}
                      </Button>
                    ) : null}
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
