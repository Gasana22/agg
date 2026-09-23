"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ArrowRight, MapPin, ShieldCheck } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { KIND_LABELS, STATUS_TONE } from "@/components/trace/labels";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { FieldError, Input, Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api, idempotencyKey, type components } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { formatDateTime, humanize } from "@/lib/format";
import { can } from "@/lib/permissions";

type Graph = components["schemas"]["Graph"];

export default function BatchPage() {
  const { farmId, batchId } = useParams<{ farmId: string; batchId: string }>();
  const { workspace } = useFarmWorkspace(farmId);
  const path = { farm: farmId, batch: batchId };

  const journey = useQuery({
    queryKey: ["journey", farmId, batchId],
    queryFn: async () => (await api.GET("/farms/{farm}/traceability/batches/{batch}/journey", { params: { path } })).data!.data!,
  });
  const events = useQuery({
    queryKey: ["events", farmId, batchId],
    queryFn: async () => (await api.GET("/farms/{farm}/traceability/batches/{batch}/events", { params: { path, query: { per_page: 100 } } })).data!.data!,
  });

  if (journey.isLoading) return <Skeleton className="h-64 w-full" />;
  if (journey.error) return <ErrorNotice error={journey.error} />;
  const batch = journey.data!.batch!;

  return (
    <>
      <Link href={`/farms/${farmId}/traceability`} className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden /> All batches
      </Link>
      <PageHeader
        title={batch.batch_code ?? "Batch"}
        description={[KIND_LABELS[batch.kind ?? ""], batch.name, batch.quantity ? `${Number(batch.quantity.value).toLocaleString()} ${batch.quantity.unit ?? ""}` : null].filter(Boolean).join(" · ")}
        actions={<Badge tone={STATUS_TONE[batch.status ?? "open"]} className="text-sm">{batch.status}</Badge>}
      />

      <div className="grid gap-4 lg:grid-cols-2">
        <JourneyCard title="Where it came from" icon={<ArrowLeft className="size-4" />} graph={journey.data!.backward ?? null} farmId={farmId} empty="No upstream batches linked." />
        <JourneyCard title="Where it went" icon={<ArrowRight className="size-4" />} graph={journey.data!.forward ?? null} farmId={farmId} empty="Not used in any later batch yet." />
      </div>

      <Card className="mt-4">
        <CardHeader>
          <CardTitle>History</CardTitle>
          <span className="inline-flex items-center gap-1 text-xs text-muted">
            <ShieldCheck className="size-3.5 text-primary" aria-hidden /> Append-only, hash-chained
          </span>
        </CardHeader>
        <CardContent>
          {can(workspace?.permissions, "trace.events.create") ? <AddEvent farmId={farmId} batchId={batchId} /> : null}
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
                  {e.payload && Object.keys(e.payload).length > 0 ? (
                    <dl className="mt-1 grid grid-cols-[auto_1fr] gap-x-3 text-xs text-muted">
                      {Object.entries(e.payload).map(([k, v]) => (
                        <div key={k} className="contents">
                          <dt>{humanize(k)}</dt>
                          <dd className="truncate text-foreground">{typeof v === "object" ? JSON.stringify(v) : String(v)}</dd>
                        </div>
                      ))}
                    </dl>
                  ) : null}
                  {e.location ? (
                    <p className="mt-1 inline-flex items-center gap-1 text-xs text-muted">
                      <MapPin className="size-3" aria-hidden /> {e.location.lat.toFixed(5)}, {e.location.lng.toFixed(5)}
                    </p>
                  ) : null}
                  <p className="mt-0.5 font-mono text-[10px] text-muted/70" title={e.integrity?.hash}>
                    #{e.integrity?.seq} · {e.integrity?.hash?.slice(0, 12)}…
                  </p>
                </li>
              ))}
            </ol>
          )}
        </CardContent>
      </Card>
    </>
  );
}

function JourneyCard({ title, icon, graph, farmId, empty }: { title: string; icon: React.ReactNode; graph: Graph | null; farmId: string; empty: string }) {
  const nodes = [...(graph?.nodes ?? [])].sort((a, b) => (a.depth ?? 0) - (b.depth ?? 0));
  return (
    <Card>
      <CardHeader>
        <CardTitle className="inline-flex items-center gap-2">
          {icon} {title}
        </CardTitle>
      </CardHeader>
      <CardContent>
        {nodes.length === 0 ? (
          <EmptyState title={empty} />
        ) : (
          <ul className="space-y-2">
            {nodes.map((n) => (
              <li key={n.id} className="flex items-center gap-3 text-sm" style={{ paddingLeft: `${((n.depth ?? 1) - 1) * 16}px` }}>
                <Badge>{KIND_LABELS[n.kind ?? ""] ?? n.kind}</Badge>
                <Link href={`/farms/${farmId}/traceability/batches/${n.id}`} className="font-mono text-primary hover:underline">
                  {n.batch_code}
                </Link>
                <span className="truncate text-muted">{n.name}</span>
              </li>
            ))}
          </ul>
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
