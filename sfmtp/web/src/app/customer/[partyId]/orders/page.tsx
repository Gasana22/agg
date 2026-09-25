"use client";

import { useQuery } from "@tanstack/react-query";
import { useParams } from "next/navigation";
import { useState } from "react";

import { SalesOrderRows } from "@/components/portal/customer";
import { Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";

const FILTERS: Record<string, string | undefined> = { active: "requested,approved,invoiced,dispatched", delivered: "delivered", closed: "rejected,cancelled", all: undefined };

export default function CustomerOrdersPage() {
  const { partyId } = useParams<{ partyId: string }>();
  const [filter, setFilter] = useState("active");
  const orders = useQuery({
    queryKey: ["customer-orders", partyId, filter],
    queryFn: async () => (await api.GET("/customer/{party}/orders", { params: { path: { party: partyId }, query: { "filter[status]": FILTERS[filter] } } })).data!.data ?? [],
  });

  return (
    <>
      <PageHeader title="My orders" description="Every order from every farm, with where it is now." />
      <Select aria-label="Status" className="mb-4 w-44" value={filter} onChange={(e) => setFilter(e.target.value)}>
        <option value="active">Active</option>
        <option value="delivered">Delivered</option>
        <option value="closed">Declined or cancelled</option>
        <option value="all">All</option>
      </Select>
      {orders.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : orders.error ? (
        <ErrorNotice error={orders.error} />
      ) : (orders.data ?? []).length === 0 ? (
        <EmptyState title="No orders here" />
      ) : (
        <SalesOrderRows partyId={partyId} orders={orders.data!} />
      )}
    </>
  );
}
