"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { ConfirmDeliveryDialog } from "@/components/portal/confirm-delivery";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatDateTime } from "@/lib/format";
import { formatQty } from "@/lib/inventory";
import type { PortalShipment } from "@/lib/portal";

export default function DeliveriesPage() {
  const { partyId } = useParams<{ partyId: string }>();
  const queryClient = useQueryClient();
  const [confirm, setConfirm] = useState<PortalShipment | null>(null);
  const rows = useQuery({
    queryKey: ["customer-deliveries", partyId],
    queryFn: async () => (await api.GET("/customer/{party}/deliveries", { params: { path: { party: partyId } } })).data!.data ?? [],
  });

  return (
    <>
      <PageHeader title="Deliveries" description="Goods the farms have sent you. Confirm each one when it arrives." />
      {rows.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : rows.error ? (
        <ErrorNotice error={rows.error} />
      ) : (rows.data ?? []).length === 0 ? (
        <EmptyState title="Nothing sent yet" />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Shipment</Th>
              <Th>Farm</Th>
              <Th>Goods</Th>
              <Th>Dispatched</Th>
              <Th>Status</Th>
            </tr>
          </thead>
          <tbody>
            {rows.data!.map((s) => (
              <tr key={s.id} className="align-top">
                <Td className="font-medium">
                  {s.code}
                  {s.sales_order_id ? (
                    <Link href={`/customer/${partyId}/orders/${s.farm?.id}/${s.sales_order_id}`} className="block text-xs text-primary hover:underline">
                      Order
                    </Link>
                  ) : null}
                </Td>
                <Td>{s.farm?.name}</Td>
                <Td className="text-xs">
                  {(s.lines ?? []).map((l, i) => (
                    <p key={i}>
                      {formatQty(l.quantity, l.unit)} {l.description}
                    </p>
                  ))}
                </Td>
                <Td className="text-muted">{formatDateTime(s.dispatched_at)}</Td>
                <Td>
                  {s.status === "dispatched" ? (
                    <Button size="sm" onClick={() => setConfirm(s)}>
                      It arrived
                    </Button>
                  ) : (
                    <>
                      <Badge tone={s.status === "delivered" ? "success" : "danger"}>{s.status === "delivered" ? "delivered" : "not delivered"}</Badge>
                      {s.received_by ? <p className="text-xs text-muted">{s.received_by}</p> : null}
                    </>
                  )}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {confirm ? (
        <ConfirmDeliveryDialog
          partyId={partyId}
          shipment={confirm}
          onClose={() => setConfirm(null)}
          onDone={async () => {
            setConfirm(null);
            await queryClient.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith("customer-") });
          }}
        />
      ) : null}
    </>
  );
}
