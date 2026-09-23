"use client";

import { useInfiniteQuery } from "@tanstack/react-query";
import { Plus, Search } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useDeferredValue, useState } from "react";

import { KIND_LABELS, STATUS_TONE } from "@/components/trace/labels";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { Input, Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api, type components } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { formatDateTime } from "@/lib/format";
import { can } from "@/lib/permissions";

type Kind = components["schemas"]["BatchKind"];

export default function TraceabilityPage() {
  const { farmId } = useParams<{ farmId: string }>();
  const { workspace } = useFarmWorkspace(farmId);
  const [kind, setKind] = useState<Kind | "">("");
  const [q, setQ] = useState("");
  const search = useDeferredValue(q.trim());

  const query = useInfiniteQuery({
    queryKey: ["batches", farmId, kind, search],
    initialPageParam: undefined as string | undefined,
    queryFn: async ({ pageParam }) =>
      (
        await api.GET("/farms/{farm}/traceability/batches", {
          params: {
            path: { farm: farmId },
            query: { cursor: pageParam, per_page: 25, "filter[kind]": kind || undefined, q: search || undefined },
          },
        })
      ).data!,
    getNextPageParam: (last) => last.meta?.next_cursor ?? undefined,
  });

  const batches = query.data?.pages.flatMap((p) => p.data ?? []) ?? [];

  return (
    <>
      <PageHeader
        title="Traceability"
        description="Every batch has a permanent, auditable journey from seed to sale."
        actions={
          can(workspace?.permissions, "trace.batches.create") ? (
            <Link href={`/farms/${farmId}/traceability/batches/new`} className={buttonVariants()}>
              <Plus /> New batch
            </Link>
          ) : null
        }
      />

      <div className="mb-4 flex flex-wrap gap-2">
        <div className="relative w-full max-w-xs">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden />
          <Input aria-label="Search batches" placeholder="Search code or name" className="pl-9" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        <Select aria-label="Kind" className="w-44" value={kind} onChange={(e) => setKind(e.target.value as Kind | "")}>
          <option value="">All kinds</option>
          {Object.entries(KIND_LABELS).map(([k, label]) => (
            <option key={k} value={k}>
              {label}
            </option>
          ))}
        </Select>
      </div>

      {query.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : query.error ? (
        <ErrorNotice error={query.error} />
      ) : batches.length === 0 ? (
        <EmptyState title="No batches yet">Batches appear as seed lots, harvests and products are recorded.</EmptyState>
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>Batch</Th>
                <Th>Kind</Th>
                <Th className="text-right">Quantity</Th>
                <Th>Status</Th>
                <Th>Created</Th>
              </tr>
            </thead>
            <tbody>
              {batches.map((b) => (
                <tr key={b.id} className="hover:bg-surface-muted/50">
                  <Td>
                    <Link href={`/farms/${farmId}/traceability/batches/${b.id}`} className="font-mono text-sm font-medium text-primary hover:underline">
                      {b.batch_code}
                    </Link>
                    {b.name ? <p className="text-xs text-muted">{b.name}</p> : null}
                  </Td>
                  <Td>{KIND_LABELS[b.kind ?? ""] ?? b.kind}</Td>
                  <Td className="text-right tabular-nums">{b.quantity ? `${Number(b.quantity.value).toLocaleString()} ${b.quantity.unit ?? ""}` : "—"}</Td>
                  <Td>
                    <Badge tone={STATUS_TONE[b.status ?? "open"]}>{b.status}</Badge>
                  </Td>
                  <Td className="text-muted">{formatDateTime(b.created_at)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
          {query.hasNextPage ? (
            <div className="mt-4 text-center">
              <Button variant="secondary" onClick={() => query.fetchNextPage()} disabled={query.isFetchingNextPage}>
                {query.isFetchingNextPage ? "Loading…" : "Load more"}
              </Button>
            </div>
          ) : null}
        </>
      )}
    </>
  );
}
