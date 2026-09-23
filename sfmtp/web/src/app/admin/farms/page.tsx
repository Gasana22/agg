"use client";

import { useInfiniteQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useDeferredValue, useState } from "react";

import { ListToolbar } from "@/components/admin/list-toolbar";
import { Button } from "@/components/ui/button";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status";
import { api } from "@/lib/api/client";
import { formatDateTime } from "@/lib/format";

type FarmStatus = "pending" | "active" | "suspended" | "closed";

function FarmsList() {
  const params = useSearchParams();
  const [status, setStatus] = useState<string>(params.get("status") ?? "");
  const [q, setQ] = useState("");
  const search = useDeferredValue(q.trim());

  const query = useInfiniteQuery({
    queryKey: ["admin-farms", status, search],
    initialPageParam: undefined as string | undefined,
    queryFn: async ({ pageParam }) =>
      (await api.GET("/admin/farms", { params: { query: { cursor: pageParam, "filter[status]": (status || undefined) as FarmStatus | undefined, q: search || undefined } } })).data!,
    getNextPageParam: (last) => last.meta?.next_cursor ?? undefined,
  });
  const farms = query.data?.pages.flatMap((p) => p.data ?? []) ?? [];

  return (
    <>
      <PageHeader title="Farms" description="Approve and suspend farms. Only farm metadata is visible here, never farm records." />
      <ListToolbar q={q} onQ={setQ} placeholder="Name or farm code" status={status} onStatus={setStatus} statuses={["pending", "active", "suspended", "closed"]} />
      {query.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : query.error ? (
        <ErrorNotice error={query.error} />
      ) : farms.length === 0 ? (
        <EmptyState title="No farms match" />
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>Farm</Th>
                <Th>Owner</Th>
                <Th>Status</Th>
                <Th>Subscription</Th>
                <Th className="text-right">Members</Th>
                <Th>Created</Th>
              </tr>
            </thead>
            <tbody>
              {farms.map((f) => (
                <tr key={f.id} className="hover:bg-surface-muted/50">
                  <Td>
                    <Link href={`/admin/farms/${f.id}`} className="font-medium text-primary hover:underline">
                      {f.name}
                    </Link>
                    <p className="font-mono text-xs text-muted">{f.code}</p>
                  </Td>
                  <Td>
                    <p>{f.owner?.name ?? "—"}</p>
                    <p className="text-xs text-muted">{f.owner?.email}</p>
                  </Td>
                  <Td>
                    <StatusBadge status={f.status} />
                  </Td>
                  <Td>
                    {f.subscription ? (
                      <span className="inline-flex items-center gap-2">
                        <StatusBadge status={f.subscription.status} />
                        <span className="text-xs text-muted">{f.subscription.plan}</span>
                      </span>
                    ) : (
                      "—"
                    )}
                  </Td>
                  <Td className="text-right tabular-nums">{f.member_count}</Td>
                  <Td className="text-muted">{formatDateTime(f.created_at)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
          {query.hasNextPage ? (
            <div className="mt-4 text-center">
              <Button variant="secondary" onClick={() => query.fetchNextPage()} disabled={query.isFetchingNextPage}>
                Load more
              </Button>
            </div>
          ) : null}
        </>
      )}
    </>
  );
}

export default function AdminFarmsPage() {
  return (
    <Suspense>
      <FarmsList />
    </Suspense>
  );
}
