"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, ArrowLeft, Combine, Factory, MapPin, Package, Pencil, ShieldCheck, Split, Truck } from "lucide-react";
import dynamic from "next/dynamic";
import Link from "next/link";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";

import { useCustomers } from "@/components/finance/queries";
import { CorrectDialog, DispatchDialog, RecallDialog, SplitDialog, TransformDialog } from "@/components/trace/dialogs";
import { JourneyGraph } from "@/components/trace/journey-graph";
import { PublishPanel } from "@/components/trace/publish";
import { KIND_LABELS, STATUS_TONE } from "@/components/trace/labels";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { FieldError, Input, Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api, idempotencyKey, type components } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { formatDateTime, humanize } from "@/lib/format";
import { can } from "@/lib/permissions";
import { formatQty, isUsable, type TimelineEvent } from "@/lib/trace";
import { cn } from "@/lib/utils";

const TraceMap = dynamic(() => import("@/components/trace/trace-map"), { ssr: false, loading: () => <Skeleton className="h-[380px] w-full" /> });

const TABS = [
  { key: "journey", label: "Journey" },
  { key: "timeline", label: "Timeline" },
  { key: "inputs", label: "Seeds & inputs" },
  { key: "workers", label: "Workers" },
  { key: "sales", label: "Customers" },
  { key: "map", label: "Map" },
  { key: "publish", label: "Public page & QR" },
  { key: "history", label: "This batch's events" },
] as const;
type Tab = (typeof TABS)[number]["key"];
const LINK_EVENTS = ["linked_from", "linked_to"];
/** Written by the system from other records: corrected through those records, not here. */
const SYSTEM_EVENTS = [...LINK_EVENTS, "created", "status_changed", "correction"];
type Dialog = null | "split" | "process" | "package" | "merge" | "recall" | "ship" | { correct: TimelineEvent };

export default function BatchPage() {
  const { farmId, batchId } = useParams<{ farmId: string; batchId: string }>();
  const router = useRouter();
  const search = useSearchParams();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const [tab, setTab] = useState<Tab>((search.get("tab") as Tab) || "journey");
  const [dialog, setDialog] = useState<Dialog>(null);
  const path = { farm: farmId, batch: batchId };

  // The explorer reads the graph live, so a change shows at once.
  const journey = useQuery({
    queryKey: ["journey", farmId, batchId],
    queryFn: async () => (await api.GET("/farms/{farm}/traceability/batches/{batch}/journey", { params: { path, query: { fresh: true } } })).data!.data!,
  });
  const batchQuery = useQuery({
    queryKey: ["batch", farmId, batchId],
    queryFn: async () => (await api.GET("/farms/{farm}/traceability/batches/{batch}", { params: { path } })).data!.data!,
  });
  const customers = useCustomers(farmId, dialog === "ship");

  if (journey.isLoading || batchQuery.isLoading) return <Skeleton className="h-64 w-full" />;
  if (journey.error || batchQuery.error) return <ErrorNotice error={journey.error ?? batchQuery.error} />;
  const batch = batchQuery.data!;
  const summary = journey.data!.evidence_summary;
  const canCreate = can(perms, "trace.batches.create");
  const usable = isUsable(batch);
  const hasLeft = batch.available === null || Number(batch.available?.value) > 0;

  const done = async (id?: string) => {
    setDialog(null);
    await queryClient.invalidateQueries({ predicate: (q) => ["journey", "batch", "batches", "trace-view", "events"].includes(String(q.queryKey[0])) });
    if (id && id !== batchId) router.push(`/farms/${farmId}/traceability/batches/${id}`);
  };

  return (
    <>
      <Link href={`/farms/${farmId}/traceability`} className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden /> All batches
      </Link>
      <PageHeader
        title={batch.batch_code ?? "Batch"}
        description={[KIND_LABELS[batch.kind ?? ""], batch.name].filter(Boolean).join(" · ")}
        actions={<Badge tone={STATUS_TONE[batch.status ?? "open"]} className="text-sm">{batch.status}</Badge>}
      />

      {batch.status === "recalled" ? (
        <div role="alert" className="mb-4 flex items-center gap-2 rounded-lg border border-danger/40 bg-danger/5 px-4 py-2 text-sm text-danger">
          <AlertTriangle className="size-4" aria-hidden /> This batch is recalled. It cannot be used, sold or shipped.
        </div>
      ) : null}

      <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <Stat label="Quantity" value={formatQty(batch.quantity)} />
        <Stat label="Left" value={formatQty(batch.available)} />
        <Stat label="Sources" value={String(summary?.sources ?? 0)} />
        <Stat label="Destinations" value={String(summary?.destinations ?? 0)} />
        <Stat label="Evidence" value={`${summary?.events ?? 0} events · ${summary?.gps_points ?? 0} GPS`} />
      </div>

      {canCreate || can(perms, "trace.publish") || can(perms, "sales.fulfil") ? (
        <div className="mb-4 flex flex-wrap gap-2">
          {canCreate && usable && hasLeft && batch.quantity ? (
            <Button variant="secondary" size="sm" onClick={() => setDialog("split")}>
              <Split /> Split
            </Button>
          ) : null}
          {canCreate && usable && hasLeft ? (
            <>
              <Button variant="secondary" size="sm" onClick={() => setDialog("merge")}>
                <Combine /> Merge
              </Button>
              <Button variant="secondary" size="sm" onClick={() => setDialog("process")}>
                <Factory /> Process
              </Button>
              <Button variant="secondary" size="sm" onClick={() => setDialog("package")}>
                <Package /> Pack
              </Button>
            </>
          ) : null}
          {can(perms, "sales.fulfil") && usable && hasLeft ? (
            <Button variant="secondary" size="sm" onClick={() => setDialog("ship")}>
              <Truck /> Ship to a customer
            </Button>
          ) : null}
          {can(perms, "trace.publish") && batch.status !== "recalled" ? (
            <Button variant="danger" size="sm" onClick={() => setDialog("recall")}>
              <AlertTriangle /> Recall
            </Button>
          ) : null}
        </div>
      ) : null}

      <div role="tablist" aria-label="Batch views" className="mb-4 flex gap-1 overflow-x-auto border-b border-border">
        {TABS.map((t) => (
          <button
            key={t.key}
            role="tab"
            type="button"
            aria-selected={tab === t.key}
            onClick={() => setTab(t.key)}
            className={cn("-mb-px shrink-0 border-b-2 px-3 py-2 text-sm", tab === t.key ? "border-primary font-medium text-foreground" : "border-transparent text-muted hover:text-foreground")}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === "journey" ? (
        <Card>
          <CardHeader>
            <CardTitle>Where it came from and where it went</CardTitle>
          </CardHeader>
          <CardContent>
            {(journey.data!.backward?.nodes?.length ?? 0) + (journey.data!.forward?.nodes?.length ?? 0) === 0 ? (
              <EmptyState title="Not linked to any other batch yet." />
            ) : (
              <JourneyGraph farmId={farmId} batch={batch} backward={journey.data!.backward} forward={journey.data!.forward} />
            )}
          </CardContent>
        </Card>
      ) : tab === "timeline" ? (
        <Timeline farmId={farmId} batchId={batchId} canCorrect={can(perms, "trace.events.create")} onCorrect={(e) => setDialog({ correct: e })} />
      ) : tab === "inputs" ? (
        <Inputs farmId={farmId} batchId={batchId} />
      ) : tab === "workers" ? (
        <Workers farmId={farmId} batchId={batchId} />
      ) : tab === "sales" ? (
        <Sales farmId={farmId} batchId={batchId} />
      ) : tab === "map" ? (
        <Places farmId={farmId} batchId={batchId} />
      ) : tab === "publish" ? (
        <PublishPanel farmId={farmId} batchId={batchId} canPublish={can(perms, "trace.publish")} recalled={batch.status === "recalled"} />
      ) : (
        <History farmId={farmId} batchId={batchId} canAdd={can(perms, "trace.events.create")} />
      )}

      {dialog === "split" ? <SplitDialog farmId={farmId} batch={batch} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "merge" || dialog === "process" || dialog === "package" ? <TransformDialog farmId={farmId} batch={batch} mode={dialog} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "recall" ? <RecallDialog farmId={farmId} batch={batch} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "ship" ? (
        <DispatchDialog farmId={farmId} batch={batch} customers={customers.data ?? []} onClose={() => setDialog(null)} onDone={(id) => (id ? router.push(`/farms/${farmId}/shipments?shipment=${id}`) : done())} />
      ) : null}
      {dialog && typeof dialog === "object" ? <CorrectDialog farmId={farmId} event={dialog.correct} onClose={() => setDialog(null)} onDone={done} /> : null}
    </>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-border bg-surface px-4 py-3">
      <p className="text-xs text-muted">{label}</p>
      <p className="truncate text-sm font-semibold tabular-nums">{value}</p>
    </div>
  );
}

function useView<T>(farmId: string, batchId: string, view: "timeline" | "inputs" | "workers" | "sales" | "locations") {
  return useQuery({
    queryKey: ["trace-view", farmId, batchId, view],
    queryFn: async () => {
      const path = { farm: farmId, batch: batchId };
      const res =
        view === "timeline"
          ? await api.GET("/farms/{farm}/traceability/batches/{batch}/timeline", { params: { path } })
          : view === "inputs"
            ? await api.GET("/farms/{farm}/traceability/batches/{batch}/inputs", { params: { path } })
            : view === "workers"
              ? await api.GET("/farms/{farm}/traceability/batches/{batch}/workers", { params: { path } })
              : view === "sales"
                ? await api.GET("/farms/{farm}/traceability/batches/{batch}/sales", { params: { path } })
                : await api.GET("/farms/{farm}/traceability/batches/{batch}/locations", { params: { path } });
      return (res.data as { data: T }).data;
    },
  });
}

function Payload({ payload }: { payload: Record<string, unknown> | null | undefined }) {
  const entries = Object.entries(payload ?? {});
  if (entries.length === 0) return null;
  return (
    <dl className="mt-1 grid grid-cols-[auto_1fr] gap-x-3 text-xs text-muted">
      {entries.map(([k, v]) => (
        <div key={k} className="contents">
          <dt>{humanize(k)}</dt>
          <dd className="truncate text-foreground">{typeof v === "object" ? JSON.stringify(v) : String(v)}</dd>
        </div>
      ))}
    </dl>
  );
}

function Timeline({ farmId, batchId, canCorrect, onCorrect }: { farmId: string; batchId: string; canCorrect: boolean; onCorrect: (e: TimelineEvent) => void }) {
  const q = useView<TimelineEvent[]>(farmId, batchId, "timeline");
  const [links, setLinks] = useState(false);
  if (q.isLoading) return <Skeleton className="h-64 w-full" />;
  if (q.error) return <ErrorNotice error={q.error} />;
  // Link events repeat what the journey graph shows; hidden unless asked for.
  const events = (q.data ?? []).filter((e) => links || !LINK_EVENTS.includes(e.event_type ?? ""));
  return (
    <Card>
      <CardHeader>
        <CardTitle>From the first source to today</CardTitle>
        <label className="flex items-center gap-2 text-xs text-muted">
          <input type="checkbox" className="size-3.5" checked={links} onChange={(e) => setLinks(e.target.checked)} /> Show link events
        </label>
      </CardHeader>
      <CardContent>
        {events.length === 0 ? (
          <EmptyState title="No events yet." />
        ) : (
          <ol className="relative space-y-5 border-l border-border pl-5">
            {events.map((e) => (
              <li key={e.id} className="relative">
                <span className={cn("absolute -left-[1.6rem] top-1.5 size-2.5 rounded-full border-2 border-surface", e.corrected ? "bg-warning" : "bg-primary")} aria-hidden />
                <div className="flex flex-wrap items-baseline gap-x-2">
                  <p className="text-sm font-medium">{humanize(e.event_type ?? "")}</p>
                  <Link href={`/farms/${farmId}/traceability/batches/${e.batch?.id}`} className="font-mono text-xs text-primary hover:underline">
                    {e.batch?.batch_code}
                  </Link>
                  <time className="text-xs text-muted" dateTime={e.occurred_at}>
                    {formatDateTime(e.occurred_at)}
                  </time>
                  {e.actor?.name ? <span className="text-xs text-muted">by {e.actor.name}</span> : null}
                  {e.recorded_late ? <Badge tone="warning">recorded later</Badge> : null}
                  {e.corrected ? <Badge tone="warning">corrected</Badge> : null}
                  {canCorrect && !SYSTEM_EVENTS.includes(e.event_type ?? "") && Object.keys(e.payload ?? {}).length > 0 ? (
                    <button type="button" onClick={() => onCorrect(e)} className="inline-flex items-center gap-1 text-xs text-muted hover:text-foreground">
                      <Pencil className="size-3" aria-hidden /> Correct
                    </button>
                  ) : null}
                </div>
                <Payload payload={e.payload as Record<string, unknown>} />
                {e.corrected ? (
                  <details className="mt-1 text-xs text-muted">
                    <summary className="cursor-pointer">Correction history</summary>
                    <p className="mt-1">Originally: {Object.entries(e.original_payload ?? {}).map(([k, v]) => `${humanize(k)} ${String(v)}`).join(" · ")}</p>
                    {(e.corrections ?? []).map((c) => (
                      <p key={c.id}>
                        {formatDateTime(c.recorded_at)}: {Object.entries(c.corrected ?? {}).map(([k, v]) => `${humanize(k)} → ${String(v)}`).join(", ")} ({c.reason})
                      </p>
                    ))}
                  </details>
                ) : null}
                {e.location ? (
                  <p className="mt-1 inline-flex items-center gap-1 text-xs text-muted">
                    <MapPin className="size-3" aria-hidden /> {e.location.lat?.toFixed(5)}, {e.location.lng?.toFixed(5)}
                  </p>
                ) : null}
              </li>
            ))}
          </ol>
        )}
      </CardContent>
    </Card>
  );
}

type InputsData = { lots?: { batch?: { id?: string; batch_code?: string; kind?: string; name?: string | null }; role?: string; lot_number?: string | null; supplier?: string | null; item?: string | null; expires_on?: string | null; received_at?: string | null }[]; applications?: { event_id?: string; event_type?: string; occurred_at?: string; applied_to?: string | null; product?: string | null; quantity?: string | null; unit?: string | null; input_batch?: { batch_code?: string; id?: string | null } | null; withholding_days?: number | null; meat_withdrawal_days?: number | null; milk_withdrawal_days?: number | null }[] };

function Inputs({ farmId, batchId }: { farmId: string; batchId: string }) {
  const q = useView<InputsData>(farmId, batchId, "inputs");
  if (q.isLoading) return <Skeleton className="h-48 w-full" />;
  if (q.error) return <ErrorNotice error={q.error} />;
  const { lots = [], applications = [] } = q.data ?? {};
  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>Seed and input lots</CardTitle>
        </CardHeader>
        <CardContent>
          {lots.length === 0 ? (
            <EmptyState title="No seed or input lot is linked." />
          ) : (
            <Table>
              <thead>
                <tr>
                  <Th>Lot</Th>
                  <Th>Role</Th>
                  <Th>Supplier lot</Th>
                  <Th>Supplier</Th>
                  <Th>Expires</Th>
                </tr>
              </thead>
              <tbody>
                {lots.map((l) => (
                  <tr key={l.batch?.id}>
                    <Td>
                      <Link href={`/farms/${farmId}/traceability/batches/${l.batch?.id}`} className="font-mono text-sm text-primary hover:underline">
                        {l.batch?.batch_code}
                      </Link>
                      <p className="text-xs text-muted">{l.batch?.name}</p>
                    </Td>
                    <Td>{l.role === "source" ? "Grown from" : "Applied"}</Td>
                    <Td>{l.lot_number ?? "—"}</Td>
                    <Td>{l.supplier ?? "—"}</Td>
                    <Td>{l.expires_on ?? "—"}</Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>Applied along the way</CardTitle>
        </CardHeader>
        <CardContent>
          {applications.length === 0 ? (
            <EmptyState title="No fertiliser, chemical, feed or drug recorded." />
          ) : (
            <Table>
              <thead>
                <tr>
                  <Th>When</Th>
                  <Th>What</Th>
                  <Th>On</Th>
                  <Th className="text-right">Amount</Th>
                  <Th>Withholding</Th>
                </tr>
              </thead>
              <tbody>
                {applications.map((a) => (
                  <tr key={a.event_id}>
                    <Td className="text-muted">{formatDateTime(a.occurred_at)}</Td>
                    <Td>
                      {a.product ?? humanize(a.event_type ?? "")}
                      {a.input_batch ? <p className="font-mono text-xs text-muted">{a.input_batch.batch_code}</p> : null}
                    </Td>
                    <Td className="font-mono text-xs">{a.applied_to}</Td>
                    <Td className="text-right tabular-nums">{a.quantity ? `${a.quantity} ${a.unit ?? ""}` : "—"}</Td>
                    <Td>
                      {[a.withholding_days ? `${a.withholding_days} days` : null, a.meat_withdrawal_days ? `meat ${a.meat_withdrawal_days} d` : null, a.milk_withdrawal_days ? `milk ${a.milk_withdrawal_days} d` : null]
                        .filter(Boolean)
                        .join(" · ") || "—"}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

type WorkersData = { workers?: { worker_id?: string; worker_code?: string | null; name?: string | null; events?: number; first_at?: string; last_at?: string; activities?: { event_id?: string; event_type?: string; activity_type?: string; batch_code?: string; occurred_at?: string; has_gps?: boolean }[] }[]; recorders?: { user_id?: string; name?: string | null; events?: number; event_types?: Record<string, number> }[] };

function Workers({ farmId, batchId }: { farmId: string; batchId: string }) {
  const q = useView<WorkersData>(farmId, batchId, "workers");
  if (q.isLoading) return <Skeleton className="h-48 w-full" />;
  if (q.error) return <ErrorNotice error={q.error} />;
  const { workers = [], recorders = [] } = q.data ?? {};
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle>Field workers</CardTitle>
        </CardHeader>
        <CardContent>
          {workers.length === 0 ? (
            <EmptyState title="No task work recorded on this lineage." />
          ) : (
            <ul className="space-y-3">
              {workers.map((w) => (
                <li key={w.worker_id}>
                  <p className="text-sm font-medium">
                    {w.name ?? w.worker_code} <span className="text-xs text-muted">{w.name ? w.worker_code : null}</span>
                  </p>
                  <p className="text-xs text-muted">
                    {w.events} tasks · {formatDateTime(w.first_at)} to {formatDateTime(w.last_at)}
                  </p>
                  <p className="text-xs text-muted">{(w.activities ?? []).map((a) => humanize(a.activity_type ?? a.event_type ?? "")).filter((v, i, all) => all.indexOf(v) === i).join(", ")}</p>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>Recorded by</CardTitle>
        </CardHeader>
        <CardContent>
          <ul className="space-y-2">
            {recorders.map((r) => (
              <li key={r.user_id} className="flex items-baseline justify-between gap-3 text-sm">
                <span>{r.name ?? "—"}</span>
                <span className="text-xs text-muted">
                  {r.events} events: {Object.entries(r.event_types ?? {}).slice(0, 4).map(([k, n]) => `${humanize(k)} ${n}`).join(", ")}
                </span>
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>
    </div>
  );
}

type SaleRow = { shipment_batch?: { id?: string; batch_code?: string; status?: string; quantity?: { value?: string; unit?: string | null } | null }; shipment?: { id?: string; code?: string | null } | null; customer?: string | null; destination?: string | null; invoice?: string | null; dispatched_at?: string | null; delivered_at?: string | null; received_by?: string | null; from?: { batch_id?: string; batch_code?: string | null; quantity?: string | null; unit?: string | null }[] };

function Sales({ farmId, batchId }: { farmId: string; batchId: string }) {
  const q = useView<SaleRow[]>(farmId, batchId, "sales");
  if (q.isLoading) return <Skeleton className="h-48 w-full" />;
  if (q.error) return <ErrorNotice error={q.error} />;
  const rows = q.data ?? [];
  return (
    <Card>
      <CardHeader>
        <CardTitle>Customers it reached</CardTitle>
      </CardHeader>
      <CardContent>
        {rows.length === 0 ? (
          <EmptyState title="Not shipped to any customer yet." />
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Shipment</Th>
                <Th>Customer</Th>
                <Th>From</Th>
                <Th>Dispatched</Th>
                <Th>Delivered</Th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.shipment_batch?.id}>
                  <Td>
                    <Link href={`/farms/${farmId}/shipments?shipment=${r.shipment?.id}`} className="font-medium text-primary hover:underline">
                      {r.shipment?.code ?? r.shipment_batch?.batch_code}
                    </Link>
                    {r.shipment_batch?.status === "recalled" ? (
                      <Badge tone="danger" className="ml-2">
                        recalled
                      </Badge>
                    ) : null}
                  </Td>
                  <Td>
                    {r.customer}
                    {r.destination ? <p className="text-xs text-muted">{r.destination}</p> : null}
                  </Td>
                  <Td className="text-xs">{(r.from ?? []).map((f) => `${f.batch_code} (${formatQty({ value: f.quantity, unit: f.unit })})`).join(", ")}</Td>
                  <Td className="text-muted">{formatDateTime(r.dispatched_at)}</Td>
                  <Td>{r.delivered_at ? `${formatDateTime(r.delivered_at)}${r.received_by ? ` · ${r.received_by}` : ""}` : <Badge tone="warning">awaiting</Badge>}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </CardContent>
    </Card>
  );
}

function Places({ farmId, batchId }: { farmId: string; batchId: string }) {
  const q = useView<components["schemas"]["JourneyLocations"]>(farmId, batchId, "locations");
  if (q.isLoading) return <Skeleton className="h-[380px] w-full" />;
  if (q.error) return <ErrorNotice error={q.error} />;
  const data = q.data!;
  return (
    <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
      {(data.plots?.length ?? 0) + (data.points?.length ?? 0) === 0 ? <EmptyState title="No plot or GPS point on this journey." /> : <TraceMap data={data} />}
      <Card>
        <CardHeader>
          <CardTitle>Places</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3 text-sm">
          {(data.plots ?? []).map((p) => (
            <p key={p.id}>
              <strong>Plot {p.code}</strong> <span className="text-xs text-muted">{(p.batches ?? []).join(", ")}</span>
            </p>
          ))}
          <p className="text-xs text-muted">{data.points?.length ?? 0} GPS-stamped events</p>
          {(data.moves ?? []).map((m) => (
            <p key={m.event_id} className="text-xs">
              {formatDateTime(m.occurred_at)}: {m.batch_code} moved {m.from ? `from ${m.from} ` : ""}to {m.to}
            </p>
          ))}
        </CardContent>
      </Card>
    </div>
  );
}

function History({ farmId, batchId, canAdd }: { farmId: string; batchId: string; canAdd: boolean }) {
  const events = useQuery({
    queryKey: ["events", farmId, batchId],
    queryFn: async () => (await api.GET("/farms/{farm}/traceability/batches/{batch}/events", { params: { path: { farm: farmId, batch: batchId }, query: { per_page: 100 } } })).data!.data!,
  });
  return (
    <Card>
      <CardHeader>
        <CardTitle>Events on this batch</CardTitle>
        <span className="inline-flex items-center gap-1 text-xs text-muted">
          <ShieldCheck className="size-3.5 text-primary" aria-hidden /> Append-only, hash-chained
        </span>
      </CardHeader>
      <CardContent>
        {canAdd ? <AddEvent farmId={farmId} batchId={batchId} /> : null}
        {events.isLoading ? (
          <Skeleton className="h-32 w-full" />
        ) : events.error ? (
          <ErrorNotice error={events.error} />
        ) : (
          <ol className="relative mt-4 space-y-5 border-l border-border pl-5">
            {events.data!.map((e) => (
              <li key={e.id} className="relative">
                <span className="absolute -left-[1.6rem] top-1.5 size-2.5 rounded-full border-2 border-surface bg-primary" aria-hidden />
                <div className="flex flex-wrap items-baseline gap-x-2">
                  <p className="text-sm font-medium">{humanize(e.event_type ?? "")}</p>
                  <time className="text-xs text-muted" dateTime={e.occurred_at}>
                    {formatDateTime(e.occurred_at)}
                  </time>
                  {e.recorded_late ? <Badge tone="warning">recorded later</Badge> : null}
                  {e.corrects_event_id ? <Badge tone="warning">correction</Badge> : null}
                </div>
                <Payload payload={e.payload as Record<string, unknown>} />
                <p className="mt-0.5 font-mono text-[10px] text-muted/70" title={e.integrity?.hash}>
                  #{e.integrity?.seq} · {e.integrity?.hash?.slice(0, 12)}…
                </p>
              </li>
            ))}
          </ol>
        )}
      </CardContent>
    </Card>
  );
}

function AddEvent({ farmId, batchId }: { farmId: string; batchId: string }) {
  const queryClient = useQueryClient();
  const [error, setError] = useState<ApiError | null>(null);
  const [pending, setPending] = useState(false);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = e.currentTarget;
    const f = new FormData(form);
    setPending(true);
    setError(null);
    try {
      await api.POST("/farms/{farm}/traceability/batches/{batch}/events", {
        params: { path: { farm: farmId, batch: batchId }, header: { "Idempotency-Key": idempotencyKey() } },
        body: { event_type: f.get("event_type") as "note", payload: { note: String(f.get("note") ?? "") } },
      });
      form.reset();
      await queryClient.invalidateQueries({ queryKey: ["events", farmId, batchId] });
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    } finally {
      setPending(false);
    }
  }

  return (
    <form onSubmit={onSubmit} className="flex flex-wrap gap-2">
      <Select name="event_type" aria-label="Event type" className="w-40">
        <option value="note">Note</option>
        <option value="inspection">Inspection</option>
        <option value="certification">Certification</option>
        <option value="storage_check">Storage check</option>
      </Select>
      <Input name="note" aria-label="Details" placeholder="What happened?" required className="min-w-48 flex-1" />
      <Button type="submit" variant="secondary" disabled={pending}>
        Add to history
      </Button>
      {error ? <FieldError>{error.problem.title}</FieldError> : null}
    </form>
  );
}
