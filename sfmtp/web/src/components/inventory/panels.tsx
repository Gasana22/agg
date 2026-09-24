"use client";

import { useInfiniteQuery, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatDateTime } from "@/lib/format";
import { ADJUSTMENT_STATUS, formatMoney, formatQty, MOVEMENT_LABEL, REQUEST_STATUS, type InventoryRequest } from "@/lib/inventory";

import { useActions } from "./common";
import { IssueRequestDialog, ReasonDialog } from "./dialogs";
import { SUBJECT_LABEL } from "./pickers";

type Props = { farmId: string; seesValues: boolean; currency: string };

export function MovementsPanel({ farmId, seesValues, currency, itemId }: Props & { itemId?: string }) {
  const [type, setType] = useState("");
  const movements = useInfiniteQuery({
    queryKey: ["inventory-movements", farmId, itemId ?? null, type],
    queryFn: async ({ pageParam }) =>
      (
        await api.GET("/farms/{farm}/inventory/movements", {
          params: { path: { farm: farmId }, query: { "filter[item_id]": itemId, "filter[type]": (type || undefined) as "issue", cursor: pageParam ?? undefined, per_page: 50 } },
        })
      ).data!,
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta?.next_cursor ?? null,
  });
  const rows = movements.data?.pages.flatMap((p) => p.data ?? []) ?? [];

  return (
    <>
      <div className="mb-3">
        <Select aria-label="Movement type" className="h-9 w-48" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">All movements</option>
          {Object.entries(MOVEMENT_LABEL).map(([k, v]) => (
            <option key={k} value={k}>
              {v}
            </option>
          ))}
        </Select>
      </div>
      {movements.isLoading ? (
        <Skeleton className="h-48 w-full" />
      ) : movements.error ? (
        <ErrorNotice error={movements.error} />
      ) : rows.length === 0 ? (
        <EmptyState title="No stock movements yet" />
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>When</Th>
                <Th>Movement</Th>
                {itemId ? null : <Th>Item</Th>}
                <Th>Store · lot</Th>
                <Th className="text-right">Quantity</Th>
                <Th className="text-right">Balance</Th>
                {seesValues ? <Th className="text-right">Value</Th> : null}
                <Th>By</Th>
              </tr>
            </thead>
            <tbody>
              {rows.map((m) => (
                <tr key={m.id}>
                  <Td className="whitespace-nowrap text-muted">{formatDateTime(m.occurred_at)}</Td>
                  <Td>
                    {MOVEMENT_LABEL[m.type ?? ""] ?? m.type}
                    {m.subject ? <span className="block text-xs text-muted">to {SUBJECT_LABEL[m.subject.type ?? ""]?.toLowerCase() ?? m.subject.type}</span> : null}
                    {m.note ? <span className="block text-xs text-muted">{m.note}</span> : null}
                  </Td>
                  {itemId ? null : (
                    <Td>
                      <Link href={`/farms/${farmId}/inventory/items/${m.item?.id}`} className="text-primary hover:underline">
                        {m.item?.name}
                      </Link>
                    </Td>
                  )}
                  <Td className="text-muted">
                    {m.location?.name}
                    {m.lot ? <span className="block text-xs">{m.lot.lot_number ?? m.lot.code}</span> : null}
                  </Td>
                  <Td className={`text-right tabular-nums ${(m.quantity ?? 0) < 0 ? "text-danger" : ""}`}>{formatQty(m.quantity, m.item?.unit)}</Td>
                  <Td className="text-right tabular-nums text-muted">{formatQty(m.balance_after)}</Td>
                  {seesValues ? <Td className="text-right tabular-nums">{formatMoney(m.value, currency)}</Td> : null}
                  <Td className="text-muted">{m.recorded_by?.name ?? "—"}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
          {movements.hasNextPage ? (
            <div className="mt-3 text-center">
              <Button variant="secondary" size="sm" onClick={() => movements.fetchNextPage()} disabled={movements.isFetchingNextPage}>
                Load more
              </Button>
            </div>
          ) : null}
        </>
      )}
    </>
  );
}

export function RequestsPanel({ farmId, canMove, canApprove }: { farmId: string; canMove: boolean; canApprove: boolean }) {
  const queryClient = useQueryClient();
  const [show, setShow] = useState("open");
  const [issuing, setIssuing] = useState<InventoryRequest | null>(null);
  const [rejecting, setRejecting] = useState<InventoryRequest | null>(null);
  const statuses = show === "open" ? "requested,approved,partially_issued" : undefined;
  const requests = useQuery({
    queryKey: ["inventory-requests", farmId, show],
    queryFn: async () => (await api.GET("/farms/{farm}/inventory/requests", { params: { path: { farm: farmId }, query: { "filter[status]": statuses } } })).data!.data!,
  });
  const refresh = () => Promise.all([queryClient.invalidateQueries({ queryKey: ["inventory-requests", farmId] }), queryClient.invalidateQueries({ queryKey: ["inventory-items", farmId] })]);
  const { error, busy, run } = useActions(refresh);
  const path = (r: InventoryRequest) => ({ path: { farm: farmId, inventoryRequest: r.id! } });

  return (
    <>
      <div className="mb-3">
        <Select aria-label="Show" className="h-9 w-40" value={show} onChange={(e) => setShow(e.target.value)}>
          <option value="open">Open</option>
          <option value="all">All</option>
        </Select>
      </div>
      {error ? <div className="mb-3"><ErrorNotice error={error} /></div> : null}
      {requests.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : requests.error ? (
        <ErrorNotice error={requests.error} />
      ) : requests.data!.length === 0 ? (
        <EmptyState title="No stock requests" />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Request</Th>
              <Th>Items</Th>
              <Th>For</Th>
              <Th>Status</Th>
              <Th className="text-right">Actions</Th>
            </tr>
          </thead>
          <tbody>
            {requests.data!.map((r) => {
              const st = REQUEST_STATUS[r.status ?? ""];
              return (
                <tr key={r.id}>
                  <Td>
                    <span className="font-medium">{r.code}</span>
                    <span className="block text-xs text-muted">
                      {r.requested_by?.name} · {formatDateTime(r.created_at)}
                    </span>
                    {r.needed_on ? <span className="block text-xs text-muted">needed {r.needed_on}</span> : null}
                  </Td>
                  <Td>
                    {(r.lines ?? []).map((l) => (
                      <span key={l.id} className="block">
                        {formatQty(l.quantity, l.item?.unit)} {l.item?.name}
                        {(l.issued_quantity ?? 0) > 0 ? <span className="text-xs text-muted"> · {formatQty(l.issued_quantity)} issued</span> : null}
                      </span>
                    ))}
                    {r.note ? <span className="block text-xs text-muted">{r.note}</span> : null}
                  </Td>
                  <Td className="text-muted">{r.subject?.label ?? SUBJECT_LABEL[r.subject?.type ?? "general"]}</Td>
                  <Td>
                    <Badge tone={st?.tone ?? "neutral"}>{st?.label ?? r.status}</Badge>
                    {r.decision_note ? <span className="mt-1 block text-xs text-muted">{r.decision_note}</span> : null}
                  </Td>
                  <Td className="text-right">
                    <div className="flex flex-wrap justify-end gap-2">
                      {canApprove && r.status === "requested" ? (
                        <>
                          <Button size="sm" disabled={busy === r.id} onClick={() => run(r.id!, () => api.POST("/farms/{farm}/inventory/requests/{inventoryRequest}/approve", { params: path(r), body: {} }))}>
                            Approve
                          </Button>
                          <Button size="sm" variant="secondary" onClick={() => setRejecting(r)}>
                            Reject
                          </Button>
                        </>
                      ) : null}
                      {canMove && (r.status === "approved" || r.status === "partially_issued") ? (
                        <Button size="sm" onClick={() => setIssuing(r)}>
                          Issue
                        </Button>
                      ) : null}
                      {r.status === "requested" || (r.status === "approved" && !(r.lines ?? []).some((l) => (l.issued_quantity ?? 0) > 0)) ? (
                        <Button size="sm" variant="ghost" disabled={busy === r.id} onClick={() => run(r.id!, () => api.POST("/farms/{farm}/inventory/requests/{inventoryRequest}/cancel", { params: path(r) }))}>
                          Cancel
                        </Button>
                      ) : null}
                    </div>
                  </Td>
                </tr>
              );
            })}
          </tbody>
        </Table>
      )}
      {issuing ? <IssueRequestDialog farmId={farmId} request={issuing} onClose={() => setIssuing(null)} onDone={async () => { setIssuing(null); await refresh(); }} /> : null}
      {rejecting ? (
        <ReasonDialog
          title={`Reject ${rejecting.code}`}
          submitLabel="Reject"
          onClose={() => setRejecting(null)}
          onSubmit={async (note) => {
            await api.POST("/farms/{farm}/inventory/requests/{inventoryRequest}/reject", { params: path(rejecting), body: { note } });
            setRejecting(null);
            await refresh();
          }}
        />
      ) : null}
    </>
  );
}

export function CountsPanel({ farmId, canApprove, seesValues, currency }: Props & { canApprove: boolean }) {
  const queryClient = useQueryClient();
  const [rejecting, setRejecting] = useState<string | null>(null);
  const counts = useQuery({
    queryKey: ["inventory-adjustments", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/inventory/adjustments", { params: { path: { farm: farmId } } })).data!.data!,
  });
  const refresh = () => Promise.all([queryClient.invalidateQueries({ queryKey: ["inventory-adjustments", farmId] }), queryClient.invalidateQueries({ queryKey: ["inventory-items", farmId] })]);
  const { error, busy, run } = useActions(refresh);

  if (counts.isLoading) return <Skeleton className="h-40 w-full" />;
  if (counts.error) return <ErrorNotice error={counts.error} />;
  if (counts.data!.length === 0) return <EmptyState title="No stock counts yet">Counts adjust stock only once someone else approves them.</EmptyState>;

  return (
    <>
      {error ? <div className="mb-3"><ErrorNotice error={error} /></div> : null}
      <div className="space-y-3">
        {counts.data!.map((a) => {
          const st = ADJUSTMENT_STATUS[a.status ?? ""];
          return (
            <div key={a.id} className="rounded-xl border border-border bg-surface p-4">
              <div className="mb-2 flex flex-wrap items-start justify-between gap-2">
                <div>
                  <span className="font-medium">{a.code}</span> · {a.location?.name}
                  <span className="block text-xs text-muted">
                    {a.reason} · {a.proposed_by?.name}, {formatDateTime(a.created_at)}
                  </span>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone={st?.tone ?? "neutral"}>{st?.label ?? a.status}</Badge>
                  {canApprove && a.status === "proposed" ? (
                    <>
                      <Button size="sm" disabled={busy === a.id} onClick={() => run(a.id!, () => api.POST("/farms/{farm}/inventory/adjustments/{adjustment}/approve", { params: { path: { farm: farmId, adjustment: a.id! } }, body: {} }))}>
                        Approve
                      </Button>
                      <Button size="sm" variant="secondary" onClick={() => setRejecting(a.id!)}>
                        Reject
                      </Button>
                    </>
                  ) : null}
                </div>
              </div>
              <ul className="text-sm">
                {(a.lines ?? []).map((l) => (
                  <li key={l.id} className="flex justify-between gap-3 border-t border-border py-1.5">
                    <span>
                      {l.item?.name}
                      {l.lot ? <span className="text-xs text-muted"> · {l.lot.lot_number ?? l.lot.code}</span> : null}
                    </span>
                    <span className="tabular-nums">
                      {formatQty(l.expected_quantity)} → {formatQty(l.counted_quantity, l.item?.unit)}{" "}
                      <span className={(l.difference ?? 0) < 0 ? "text-danger" : "text-success"}>
                        ({(l.difference ?? 0) > 0 ? "+" : ""}
                        {formatQty(l.difference)})
                      </span>
                    </span>
                  </li>
                ))}
              </ul>
              {seesValues && a.value_change !== null && a.value_change !== undefined ? <p className="mt-1 text-xs text-muted">Value change {formatMoney(a.value_change, currency)}</p> : null}
              {a.decision_note ? <p className="mt-1 text-xs text-muted">{a.decided_by?.name}: {a.decision_note}</p> : null}
            </div>
          );
        })}
      </div>
      {rejecting ? (
        <ReasonDialog
          title="Reject count"
          submitLabel="Reject"
          onClose={() => setRejecting(null)}
          onSubmit={async (note) => {
            await api.POST("/farms/{farm}/inventory/adjustments/{adjustment}/reject", { params: { path: { farm: farmId, adjustment: rejecting } }, body: { note } });
            setRejecting(null);
            await refresh();
          }}
        />
      ) : null}
    </>
  );
}

export function TransfersPanel({ farmId }: { farmId: string }) {
  const transfers = useQuery({
    queryKey: ["inventory-transfers", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/inventory/transfers", { params: { path: { farm: farmId } } })).data!.data!,
  });
  if (transfers.isLoading) return <Skeleton className="h-40 w-full" />;
  if (transfers.error) return <ErrorNotice error={transfers.error} />;
  if (transfers.data!.length === 0) return <EmptyState title="No transfers yet" />;
  return (
    <Table>
      <thead>
        <tr>
          <Th>Transfer</Th>
          <Th>From → to</Th>
          <Th>Items</Th>
        </tr>
      </thead>
      <tbody>
        {transfers.data!.map((t) => (
          <tr key={t.id}>
            <Td>
              <span className="font-medium">{t.code}</span>
              <span className="block text-xs text-muted">{formatDateTime(t.occurred_at)}</span>
            </Td>
            <Td>
              {t.from?.name} → {t.to?.name}
              {t.note ? <span className="block text-xs text-muted">{t.note}</span> : null}
            </Td>
            <Td>
              {(t.lines ?? []).map((l, i) => (
                <span key={i} className="block">
                  {formatQty(l.quantity, l.item?.unit)} {l.item?.name}
                  {l.lot ? <span className="text-xs text-muted"> · {l.lot.lot_number ?? l.lot.code}</span> : null}
                </span>
              ))}
            </Td>
          </tr>
        ))}
      </tbody>
    </Table>
  );
}
