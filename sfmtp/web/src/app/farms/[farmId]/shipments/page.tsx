"use client";

import { useInfiniteQuery, useQueryClient } from "@tanstack/react-query";
import { Truck } from "lucide-react";
import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { useState } from "react";

import { useCustomers } from "@/components/finance/queries";
import { DeliverDialog, DispatchDialog, FailDialog } from "@/components/trace/dialogs";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { formatDateTime } from "@/lib/format";
import { can } from "@/lib/permissions";
import { formatQty, type Shipment } from "@/lib/trace";
import { cn } from "@/lib/utils";

const STATUS_TONE = { dispatched: "warning", delivered: "success", failed: "danger" } as const;
type Status = keyof typeof STATUS_TONE;

export default function ShipmentsPage() {
  const { farmId } = useParams<{ farmId: string }>();
  const focus = useSearchParams().get("shipment");
  const { workspace } = useFarmWorkspace(farmId);
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<Status | "">("");
  const [dialog, setDialog] = useState<null | "dispatch" | { deliver: Shipment } | { fail: Shipment }>(null);
  const canFulfil = can(workspace?.permissions, "sales.fulfil");
  const customers = useCustomers(farmId, dialog === "dispatch");

  const query = useInfiniteQuery({
    queryKey: ["shipments", farmId, status],
    initialPageParam: undefined as string | undefined,
    queryFn: async ({ pageParam }) =>
      (await api.GET("/farms/{farm}/shipments", { params: { path: { farm: farmId }, query: { cursor: pageParam, per_page: 25, "filter[status]": status || undefined } } })).data!,
    getNextPageParam: (last) => last.meta?.next_cursor ?? undefined,
  });
  const shipments = query.data?.pages.flatMap((p) => p.data ?? []) ?? [];
  const done = async () => {
    setDialog(null);
    await queryClient.invalidateQueries({ predicate: (q) => ["shipments", "batches", "trace-alerts"].includes(String(q.queryKey[0])) });
  };

  return (
    <>
      <PageHeader
        title="Shipments"
        description="Goods sent to customers. Each shipment ends the journey of the batches it carried."
        actions={
          canFulfil ? (
            <Button onClick={() => setDialog("dispatch")}>
              <Truck /> Dispatch
            </Button>
          ) : null
        }
      />
      <div className="mb-4">
        <Select aria-label="Status" className="w-44" value={status} onChange={(e) => setStatus(e.target.value as Status | "")}>
          <option value="">All shipments</option>
          <option value="dispatched">On the way</option>
          <option value="delivered">Delivered</option>
          <option value="failed">Not delivered</option>
        </Select>
      </div>

      {query.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : query.error ? (
        <ErrorNotice error={query.error} />
      ) : shipments.length === 0 ? (
        <EmptyState title="No shipments yet">Dispatch packed or harvested batches to a customer to complete their journey.</EmptyState>
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>Shipment</Th>
                <Th>Customer</Th>
                <Th>Batches</Th>
                <Th>Dispatched</Th>
                <Th>Status</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {shipments.map((s) => (
                <tr key={s.id} className={cn("align-top", s.id === focus && "bg-primary/5")}>
                  <Td>
                    <p className="font-medium">{s.code}</p>
                    <Link href={`/farms/${farmId}/traceability/batches/${s.trace_batch?.id}`} className="font-mono text-xs text-primary hover:underline">
                      {s.trace_batch?.batch_code}
                    </Link>
                  </Td>
                  <Td>
                    {s.customer?.name}
                    <p className="text-xs text-muted">{[s.destination, s.vehicle].filter(Boolean).join(" · ")}</p>
                  </Td>
                  <Td className="text-xs">
                    {(s.lines ?? []).length > 0
                      ? (s.lines ?? []).map((l) => (
                          <p key={l.id}>
                            {l.batch?.batch_code} · {formatQty(l.quantity)}
                          </p>
                        ))
                      : "—"}
                  </Td>
                  <Td className="text-muted">{formatDateTime(s.dispatched_at)}</Td>
                  <Td>
                    <Badge tone={STATUS_TONE[(s.status ?? "dispatched") as Status]}>{s.status === "dispatched" ? "on the way" : s.status}</Badge>
                    {s.trace_batch?.status === "recalled" ? (
                      <Badge tone="danger" className="ml-1">
                        recalled
                      </Badge>
                    ) : null}
                    {s.delivered_at ? <p className="text-xs text-muted">{formatDateTime(s.delivered_at)}{s.received_by ? ` · ${s.received_by}` : ""}</p> : null}
                    {s.failure_reason ? <p className="text-xs text-danger">{s.failure_reason}</p> : null}
                  </Td>
                  <Td className="whitespace-nowrap text-right">
                    {canFulfil && s.status === "dispatched" ? (
                      <div className="flex justify-end gap-1">
                        <Button size="sm" onClick={() => setDialog({ deliver: s })}>
                          Delivered
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setDialog({ fail: s })}>
                          Not delivered
                        </Button>
                      </div>
                    ) : null}
                  </Td>
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

      {dialog === "dispatch" ? <DispatchDialog farmId={farmId} customers={customers.data ?? []} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog && typeof dialog === "object" && "deliver" in dialog ? <DeliverDialog farmId={farmId} shipment={dialog.deliver} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog && typeof dialog === "object" && "fail" in dialog ? <FailDialog farmId={farmId} shipment={dialog.fail} onClose={() => setDialog(null)} onDone={done} /> : null}
    </>
  );
}
