"use client";

import { useInfiniteQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useDeferredValue, useState } from "react";

import { ListToolbar } from "@/components/admin/list-toolbar";
import { money } from "@/components/billing/usage";
import { Button } from "@/components/ui/button";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status";
import { api, type components } from "@/lib/api/client";

type Status = components["schemas"]["SubscriptionStatus"];

export default function AdminSubscriptionsPage() {
  const [status, setStatus] = useState("");
  const [q, setQ] = useState("");
  const search = useDeferredValue(q.trim());

  const query = useInfiniteQuery({
    queryKey: ["admin-subscriptions", status, search],
    initialPageParam: undefined as string | undefined,
    queryFn: async ({ pageParam }) =>
      (await api.GET("/admin/subscriptions", { params: { query: { cursor: pageParam, "filter[status]": (status || undefined) as Status | undefined, q: search || undefined } } })).data!,
    getNextPageParam: (last) => last.meta?.next_cursor ?? undefined,
  });
  const rows = query.data?.pages.flatMap((p) => p.data ?? []) ?? [];

  return (
    <>
      <PageHeader title="Subscriptions" description="One subscription per organization, soonest period end first." />
      <ListToolbar q={q} onQ={setQ} placeholder="Organization name" status={status} onStatus={setStatus} statuses={["trialing", "active", "grace", "suspended", "cancelled"]} />
      {query.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : query.error ? (
        <ErrorNotice error={query.error} />
      ) : rows.length === 0 ? (
        <EmptyState title="No subscriptions match" />
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>Organization</Th>
                <Th>Plan</Th>
                <Th>Status</Th>
                <Th>Period ends</Th>
                <Th className="text-right">Farms</Th>
                <Th className="text-right">Users</Th>
              </tr>
            </thead>
            <tbody>
              {rows.map((s) => (
                <tr key={s.id} className="hover:bg-surface-muted/50">
                  <Td>
                    <Link href={`/admin/subscriptions/${s.id}`} className="font-medium text-primary hover:underline">{s.organization?.name}</Link>
                    <p className="text-xs text-muted">{s.organization?.owner?.email}</p>
                  </Td>
                  <Td>
                    {s.plan?.name} <span className="text-xs text-muted">{money(s.plan?.price)}</span>
                  </Td>
                  <Td>
                    <StatusBadge status={s.status} />
                    {s.cancel_at_period_end ? <span className="ml-2 text-xs text-muted">cancels at end</span> : null}
                  </Td>
                  <Td className="tabular-nums">{s.status === "grace" ? `grace to ${s.grace_until}` : s.current_period_end}</Td>
                  <Td className="text-right tabular-nums">{s.usage?.farms?.used}/{s.usage?.farms?.limit ?? "∞"}</Td>
                  <Td className="text-right tabular-nums">{s.usage?.users?.used}/{s.usage?.users?.limit ?? "∞"}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
          {query.hasNextPage ? (
            <div className="mt-4 text-center">
              <Button variant="secondary" onClick={() => query.fetchNextPage()} disabled={query.isFetchingNextPage}>Load more</Button>
            </div>
          ) : null}
        </>
      )}
    </>
  );
}
