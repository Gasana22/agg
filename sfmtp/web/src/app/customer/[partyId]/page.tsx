"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useParams } from "next/navigation";

import { SalesOrderRows } from "@/components/portal/customer";
import { Tile } from "@/components/portal/portal-layout";
import { buttonVariants } from "@/components/ui/button";
import { EmptyState, ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatMoney } from "@/lib/inventory";

/** docs/05 §3.10: products, active and delivered orders, what is owed and bought. */
export default function CustomerDashboardPage() {
  const { partyId } = useParams<{ partyId: string }>();
  const dash = useQuery({
    queryKey: ["customer-dashboard", partyId],
    queryFn: async () => (await api.GET("/customer/{party}/dashboard", { params: { path: { party: partyId } } })).data!.data!,
  });
  const orders = useQuery({
    queryKey: ["customer-orders", partyId, "active"],
    queryFn: async () =>
      (await api.GET("/customer/{party}/orders", { params: { path: { party: partyId }, query: { "filter[status]": "requested,approved,invoiced,dispatched" } } })).data!.data ?? [],
  });
  const d = dash.data;

  return (
    <>
      <PageHeader
        title="Dashboard"
        description="Your orders, deliveries and invoices from the farms you buy from."
        actions={
          <Link href={`/customer/${partyId}/shop`} className={buttonVariants()}>
            Shop
          </Link>
        }
      />
      {dash.error ? <ErrorNotice error={dash.error} /> : null}
      {!d ? (
        <Skeleton className="h-28 w-full" />
      ) : (
        <div className="mb-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Tile label="Products available" value={d.available_products} />
          <Tile label="Active orders" value={d.active_orders} />
          <Tile label="Delivered orders" value={d.delivered_orders} hint={d.deliveries_to_confirm ? `${d.deliveries_to_confirm} delivery to confirm` : undefined} />
          {(d.money ?? []).map((m) => (
            <Tile key={`p-${m.currency}`} label="To pay" value={formatMoney(m.pending_payments, m.currency)} hint={`Total purchases ${formatMoney(m.total_purchases, m.currency)}`} />
          ))}
        </div>
      )}
      <h2 className="mb-2 text-lg font-semibold">Active orders</h2>
      {orders.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : (orders.data ?? []).length === 0 ? (
        <EmptyState title="No active orders">Browse the shop to order from the farms you buy from.</EmptyState>
      ) : (
        <SalesOrderRows partyId={partyId} orders={orders.data!} />
      )}
    </>
  );
}
