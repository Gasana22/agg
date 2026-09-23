"use client";

import { useInfiniteQuery } from "@tanstack/react-query";
import { useParams } from "next/navigation";

import { Button } from "@/components/ui/button";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatDateTime } from "@/lib/format";

export default function AuditLogPage() {
  const { farmId } = useParams<{ farmId: string }>();
  const query = useInfiniteQuery({
    queryKey: ["audit", farmId],
    initialPageParam: undefined as string | undefined,
    queryFn: async ({ pageParam }) =>
      (await api.GET("/farms/{farm}/audit-logs", { params: { path: { farm: farmId }, query: { cursor: pageParam, per_page: 50 } } })).data!,
    getNextPageParam: (last) => last.meta?.next_cursor ?? undefined,
  });
  const rows = query.data?.pages.flatMap((p) => p.data ?? []) ?? [];

  return (
    <>
      <PageHeader title="Audit log" description="Who did what, and when. Entries can never be changed or deleted." />
      {query.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : query.error ? (
        <ErrorNotice error={query.error} />
      ) : rows.length === 0 ? (
        <EmptyState title="No entries yet" />
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>When</Th>
                <Th>Action</Th>
                <Th>Record</Th>
                <Th>Change</Th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id}>
                  <Td className="whitespace-nowrap text-muted">{formatDateTime(r.created_at)}</Td>
                  <Td className="font-mono text-xs">{r.action}</Td>
                  <Td className="text-xs text-muted">{r.entity_type ? `${r.entity_type} ${r.entity_id?.slice(0, 8) ?? ""}` : "—"}</Td>
                  <Td className="max-w-md truncate font-mono text-xs text-muted" title={JSON.stringify(r.new_values)}>
                    {r.new_values ? JSON.stringify(r.new_values) : "—"}
                  </Td>
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
