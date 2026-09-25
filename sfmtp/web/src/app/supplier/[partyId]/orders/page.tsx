"use client";

import { useQuery } from "@tanstack/react-query";
import { useParams } from "next/navigation";
import { useState } from "react";

import { usePortalWorkspace } from "@/components/portal/portal-layout";
import { OrderRows } from "@/components/portal/supplier";
import { Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";

const FILTERS: Record<string, string> = { open: "sent,partially_received", done: "received,closed", cancelled: "cancelled" };

export default function SupplierOrdersPage() {
  const { partyId } = useParams<{ partyId: string }>();
  const { workspace } = usePortalWorkspace("supplier", partyId);
  const [filter, setFilter] = useState("open");
  const [farm, setFarm] = useState("");
  const orders = useQuery({
    queryKey: ["supplier-orders", partyId, filter, farm],
    queryFn: async () =>
      (await api.GET("/supplier/{party}/orders", { params: { path: { party: partyId }, query: { "filter[status]": FILTERS[filter], "filter[farm_id]": farm || undefined } } })).data!.data ?? [],
  });

  return (
    <>
      <PageHeader title="Purchase orders" description="Orders the farms have sent you. Answer new ones, announce dispatches and send invoices from each order." />
      <div className="mb-4 flex flex-wrap gap-2">
        <Select aria-label="Status" className="w-44" value={filter} onChange={(e) => setFilter(e.target.value)}>
          <option value="open">Open</option>
          <option value="done">Completed</option>
          <option value="cancelled">Cancelled</option>
        </Select>
        {(workspace?.farms ?? []).length > 1 ? (
          <Select aria-label="Farm" className="w-56" value={farm} onChange={(e) => setFarm(e.target.value)}>
            <option value="">All farms</option>
            {workspace!.farms!.map((f) => (
              <option key={f.id} value={f.id}>
                {f.name}
              </option>
            ))}
          </Select>
        ) : null}
      </div>
      {orders.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : orders.error ? (
        <ErrorNotice error={orders.error} />
      ) : (orders.data ?? []).length === 0 ? (
        <EmptyState title="No orders here" />
      ) : (
        <OrderRows partyId={partyId} orders={orders.data!} />
      )}
    </>
  );
}
