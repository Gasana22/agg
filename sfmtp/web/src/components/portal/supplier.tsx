"use client";

import Link from "next/link";

import { Badge } from "@/components/ui/badge";
import { Table, Td, Th } from "@/components/ui/misc";
import { formatDateTime } from "@/lib/format";
import { formatMoney } from "@/lib/inventory";
import { supplierOrderState, type PortalOrder } from "@/lib/portal";

/** Purchase orders from every farm, each labelled with its farm. */
export function OrderRows({ partyId, orders }: { partyId: string; orders: PortalOrder[] }) {
  return (
    <Table>
      <thead>
        <tr>
          <Th>Order</Th>
          <Th>Farm</Th>
          <Th>Wanted by</Th>
          <Th className="text-right">Total</Th>
          <Th>Status</Th>
        </tr>
      </thead>
      <tbody>
        {orders.map((o) => {
          const st = supplierOrderState(o);
          return (
            <tr key={o.id}>
              <Td>
                <Link href={`/supplier/${partyId}/orders/${o.farm?.id}/${o.id}`} className="font-medium text-primary hover:underline">
                  {o.code}
                </Link>
                <p className="text-xs text-muted">Sent {formatDateTime(o.sent_at)}</p>
              </Td>
              <Td>{o.farm?.name}</Td>
              <Td>{o.supplier_promised_on ?? o.expected_on ?? "—"}</Td>
              <Td className="text-right tabular-nums">{formatMoney(o.total_amount, o.currency)}</Td>
              <Td>
                <Badge tone={st.tone}>{st.label}</Badge>
              </Td>
            </tr>
          );
        })}
      </tbody>
    </Table>
  );
}

/** Upload a document to the farm the order belongs to; returns its media id. */
export async function uploadDocument(partyId: string, farmId: string, file: File): Promise<string> {
  const { toApiError } = await import("@/lib/api/errors");
  const body = new FormData();
  body.append("file", file);
  const res = await fetch(`/api/proxy/supplier/${partyId}/farms/${farmId}/media`, { method: "POST", body, headers: { accept: "application/json", "idempotency-key": crypto.randomUUID() } });
  if (!res.ok) throw await toApiError(res);
  return ((await res.json()) as { data: { id: string } }).data.id;
}
