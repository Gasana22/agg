"use client";

import Link from "next/link";

import { Badge } from "@/components/ui/badge";
import { Table, Td, Th } from "@/components/ui/misc";
import { formatDateTime } from "@/lib/format";
import { formatMoney } from "@/lib/inventory";
import { SALES_ORDER_STATUS, type PortalSalesOrder } from "@/lib/portal";

export function SalesOrderRows({ partyId, orders }: { partyId: string; orders: PortalSalesOrder[] }) {
  return (
    <Table>
      <thead>
        <tr>
          <Th>Order</Th>
          <Th>Farm</Th>
          <Th>Items</Th>
          <Th className="text-right">Total</Th>
          <Th>Status</Th>
        </tr>
      </thead>
      <tbody>
        {orders.map((o) => {
          const st = SALES_ORDER_STATUS[o.status ?? ""] ?? { label: o.status, tone: "neutral" as const };
          return (
            <tr key={o.id}>
              <Td>
                <Link href={`/customer/${partyId}/orders/${o.farm?.id}/${o.id}`} className="font-medium text-primary hover:underline">
                  {o.code}
                </Link>
                <p className="text-xs text-muted">{formatDateTime(o.placed_at)}</p>
              </Td>
              <Td>{o.farm?.name}</Td>
              <Td className="text-xs">{(o.lines ?? []).map((l) => `${l.quantity} ${l.unit} ${l.description}`).join(", ")}</Td>
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
