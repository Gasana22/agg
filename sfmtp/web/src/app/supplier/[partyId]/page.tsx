"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useParams } from "next/navigation";

import { Tile } from "@/components/portal/portal-layout";
import { OrderRows } from "@/components/portal/supplier";
import { EmptyState, ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatMoney } from "@/lib/inventory";

/** docs/05 §3.9: new and pending orders, deliveries on the way, invoices and payments. */
export default function SupplierDashboardPage() {
  const { partyId } = useParams<{ partyId: string }>();
  const dash = useQuery({
    queryKey: ["supplier-dashboard", partyId],
    queryFn: async () => (await api.GET("/supplier/{party}/dashboard", { params: { path: { party: partyId } } })).data!.data!,
  });
  const orders = useQuery({
    queryKey: ["supplier-orders", partyId, "open"],
    queryFn: async () => (await api.GET("/supplier/{party}/orders", { params: { path: { party: partyId }, query: { "filter[status]": "sent,partially_received" } } })).data!.data ?? [],
  });

  const d = dash.data;
  return (
    <>
      <PageHeader title="Dashboard" description="Orders from the farms you supply, what is on the way and what you are owed." />
      {dash.error ? <ErrorNotice error={dash.error} /> : null}
      {!d ? (
        <Skeleton className="h-28 w-full" />
      ) : (
        <div className="mb-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Tile label="New orders" value={d.new_orders} hint="Waiting for your answer" />
          <Tile label="Open orders" value={d.pending_orders} hint="Accepted, not fully delivered" />
          <Tile label="On the way" value={d.deliveries_in_transit} hint="Dispatches not yet received" />
          <Tile label="Completed orders" value={d.completed_orders} />
          {(d.money ?? []).map((m) => (
            <Tile key={`o-${m.currency}`} label="Outstanding invoices" value={formatMoney(m.outstanding_invoices, m.currency)} hint={d.invoices_awaiting_farm ? `${d.invoices_awaiting_farm} more waiting for the farm` : undefined} />
          ))}
          {(d.money ?? []).map((m) => (
            <Tile key={`p-${m.currency}`} label="Payments received" value={formatMoney(m.payments_received, m.currency)} />
          ))}
        </div>
      )}

      <div className="mb-2 flex items-center justify-between">
        <h2 className="text-lg font-semibold">Open orders</h2>
        <Link href={`/supplier/${partyId}/orders`} className="text-sm text-primary hover:underline">
          All orders
        </Link>
      </div>
      {orders.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : (orders.data ?? []).length === 0 ? (
        <EmptyState title="No open orders">When a farm sends you a purchase order it appears here, and you get an email.</EmptyState>
      ) : (
        <OrderRows partyId={partyId} orders={orders.data!} />
      )}
    </>
  );
}
