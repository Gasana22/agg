"use client";

import { useInfiniteQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useDeferredValue, useState } from "react";

import { ListToolbar } from "@/components/admin/list-toolbar";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status";
import { api, type components } from "@/lib/api/client";
import { formatRelative } from "@/lib/format";

type Status = components["schemas"]["TicketStatus"];

export default function AdminSupportPage() {
  const [status, setStatus] = useState("open");
  const [assigned, setAssigned] = useState("");
  const [q, setQ] = useState("");
  const search = useDeferredValue(q.trim());

  const query = useInfiniteQuery({
    queryKey: ["admin-tickets", status, assigned, search],
    initialPageParam: undefined as string | undefined,
    queryFn: async ({ pageParam }) =>
      (
        await api.GET("/admin/support/tickets", {
          params: { query: { cursor: pageParam, "filter[status]": (status || undefined) as Status | undefined, "filter[assigned_to]": (assigned || undefined) as "me" | "none" | undefined, q: search || undefined } },
        })
      ).data!,
    getNextPageParam: (last) => last.meta?.next_cursor ?? undefined,
  });
  const rows = query.data?.pages.flatMap((p) => p.data ?? []) ?? [];

  return (
    <>
      <PageHeader title="Support" description="Tickets from farms, most recent activity first." />
      <div className="flex flex-wrap gap-2">
        <ListToolbar q={q} onQ={setQ} placeholder="Subject or SUP-reference" status={status} onStatus={setStatus} statuses={["open", "pending", "resolved", "closed"]} />
        <Select aria-label="Assignee" className="mb-4 w-44" value={assigned} onChange={(e) => setAssigned(e.target.value)}>
          <option value="">Anyone</option>
          <option value="me">Assigned to me</option>
          <option value="none">Unassigned</option>
        </Select>
      </div>
      {query.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : query.error ? (
        <ErrorNotice error={query.error} />
      ) : rows.length === 0 ? (
        <EmptyState title="No tickets here" />
      ) : (
        <>
          <Table>
            <thead><tr><Th>Ticket</Th><Th>Farm</Th><Th>Priority</Th><Th>Status</Th><Th>Assignee</Th><Th>Activity</Th></tr></thead>
            <tbody>
              {rows.map((t) => (
                <tr key={t.id} className="hover:bg-surface-muted/50">
                  <Td>
                    <Link href={`/admin/support/${t.id}`} className="font-medium text-primary hover:underline">{t.subject}</Link>
                    <p className="font-mono text-xs text-muted">{t.reference} · {t.organization?.name}</p>
                  </Td>
                  <Td>{t.farm?.name ?? "—"}</Td>
                  <Td className="capitalize">{t.priority}</Td>
                  <Td><StatusBadge status={t.status} /></Td>
                  <Td>{t.assigned_to?.name ?? <span className="text-muted">—</span>}</Td>
                  <Td className="text-muted">{t.last_activity_at ? formatRelative(t.last_activity_at) : ""}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
          {query.hasNextPage ? (
            <div className="mt-4 text-center"><Button variant="secondary" onClick={() => query.fetchNextPage()}>Load more</Button></div>
          ) : null}
        </>
      )}
    </>
  );
}
