"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Bug, Leaf, Sprout, Wheat } from "lucide-react";
import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { StageBadge } from "@/components/crops/stage-badge";
import { CloseCycleDialog, HarvestDialog, ObservationDialog, OperationDialog, TransplantDialog } from "@/components/crops/work-dialogs";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ErrorNotice, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { type CropHarvest, type CropObservation, type CropOperation, formatQuantity, relativeDays, SEVERITY_TONE, STAGES, underWithholding, yieldProgress } from "@/lib/crops";
import { formatDateTime, humanize } from "@/lib/format";
import { formatHa } from "@/lib/structure";
import { can } from "@/lib/permissions";
import { cn } from "@/lib/utils";

type Dialog = "operation" | "observation" | "harvest" | "transplant" | "close" | null;

type Entry =
  | { kind: "operation"; at: string; item: CropOperation }
  | { kind: "observation"; at: string; item: CropObservation }
  | { kind: "harvest"; at: string; item: CropHarvest };

export default function CyclePage() {
  return (
    <Suspense>
      <Cycle />
    </Suspense>
  );
}

function Cycle() {
  const { farmId, cycleId } = useParams<{ farmId: string; cycleId: string }>();
  const search = useSearchParams();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const writable = workspace?.type === "farm";
  const perms = workspace?.permissions;
  const canManage = writable && can(perms, "crops.plans.manage");
  const canRecord = writable && perms?.["crops.operations.record"] === "all";
  const canHarvest = writable && perms?.["crops.harvest.record"] === "all";
  const canVerify = writable && can(perms, "crops.operations.approve");
  const seesOps = can(perms, "crops.operations.view");
  const seesHarvests = can(perms, "crops.harvest.view");

  const initial = search.get("action");
  const [dialog, setDialog] = useState<Dialog>(initial === "operation" || initial === "observation" || initial === "harvest" ? initial : null);
  const [error, setError] = useState<ApiError | null>(null);
  const warning = search.get("warning");

  const path = { params: { path: { farm: farmId, cycle: cycleId } } };
  const cycle = useQuery({ queryKey: ["crop-cycle", cycleId], queryFn: async () => (await api.GET("/farms/{farm}/crop-cycles/{cycle}", path)).data!.data! });
  const operations = useQuery({
    queryKey: ["crop-operations", cycleId],
    queryFn: async () => (await api.GET("/farms/{farm}/crop-operations", { params: { path: { farm: farmId }, query: { "filter[cycle_id]": cycleId, per_page: 100 } } })).data!.data!,
    enabled: seesOps,
  });
  const observations = useQuery({
    queryKey: ["crop-observations", cycleId],
    queryFn: async () => (await api.GET("/farms/{farm}/crop-observations", { params: { path: { farm: farmId }, query: { "filter[cycle_id]": cycleId, per_page: 100 } } })).data!.data!,
    enabled: seesOps,
  });
  const harvests = useQuery({
    queryKey: ["harvests", cycleId],
    queryFn: async () => (await api.GET("/farms/{farm}/harvests", { params: { path: { farm: farmId }, query: { "filter[cycle_id]": cycleId, per_page: 100 } } })).data!.data!,
    enabled: seesHarvests,
  });

  const refresh = async () => {
    setDialog(null);
    await Promise.all(["crop-cycle", "crop-operations", "crop-observations", "harvests"].map((k) => queryClient.invalidateQueries({ queryKey: [k, cycleId] })));
    await queryClient.invalidateQueries({ queryKey: ["crop-cycles", farmId] });
  };

  async function act(fn: () => Promise<unknown>) {
    setError(null);
    try {
      await fn();
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  if (cycle.isLoading) return <Skeleton className="h-96 w-full" />;
  if (cycle.error) return <ErrorNotice error={cycle.error} />;
  const c = cycle.data!;
  const open = c.stage !== "closed";
  const inField = c.stage === "planted" || c.stage === "growing" || c.stage === "harvesting";
  const progress = yieldProgress(c);

  const timeline: Entry[] = [
    ...(operations.data ?? []).map((item) => ({ kind: "operation" as const, at: item.occurred_at!, item })),
    ...(observations.data ?? []).map((item) => ({ kind: "observation" as const, at: item.observed_at!, item })),
    ...(harvests.data ?? []).map((item) => ({ kind: "harvest" as const, at: `${item.harvested_on}T12:00:00Z`, item })),
  ].sort((a, b) => b.at.localeCompare(a.at));

  return (
    <>
      <nav className="mb-2 text-sm text-muted">
        <Link href={`/farms/${farmId}/crops`} className="hover:underline">
          Crops
        </Link>{" "}
        / {c.code}
      </nav>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-3 text-2xl font-semibold tracking-tight">
            {c.crop?.label} <StageBadge stage={c.stage} />
          </h1>
          <p className="mt-1 text-sm text-muted">
            Plot {c.plot?.code}
            {c.plot?.name && c.plot.name !== `Plot ${c.plot.code}` ? ` · ${c.plot.name}` : ""} · {formatHa(c.area_ha)} · {c.code}
            {c.plan ? ` · plan ${c.plan.code}` : ""}
            {c.season ? ` · ${c.season.name}` : ""}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {open && canRecord ? (
            <>
              <Button size="sm" onClick={() => setDialog("operation")}>
                Record field work
              </Button>
              <Button size="sm" variant="secondary" onClick={() => setDialog("observation")}>
                Report pest / disease
              </Button>
            </>
          ) : null}
          {inField && canHarvest ? (
            <Button size="sm" variant="secondary" onClick={() => setDialog("harvest")}>
              Record harvest
            </Button>
          ) : null}
          {c.stage === "nursery" && canManage ? (
            <Button size="sm" variant="secondary" onClick={() => setDialog("transplant")}>
              Transplant
            </Button>
          ) : null}
          {c.stage === "planted" && canManage ? (
            <Button size="sm" variant="ghost" onClick={() => act(() => api.POST("/farms/{farm}/crop-cycles/{cycle}/stage", { ...path, body: { stage: "growing" } }))}>
              Mark growing
            </Button>
          ) : null}
          {open && canManage ? (
            <Button size="sm" variant="ghost" onClick={() => setDialog("close")}>
              Close cycle
            </Button>
          ) : null}
        </div>
      </div>

      {warning ? (
        <div className="mb-4 rounded-lg border border-warning/40 bg-warning/10 px-4 py-3 text-sm" role="status">
          {warning}
        </div>
      ) : null}
      {error ? (
        <div className="mb-4">
          <ErrorNotice error={error} />
        </div>
      ) : null}
      {underWithholding(c) ? (
        <div className="mb-4 flex items-center gap-2 rounded-lg border border-warning/40 bg-warning/10 px-4 py-3 text-sm" role="status">
          <AlertTriangle className="size-4 shrink-0 text-warning" />
          Withholding period: do not harvest before <strong>{c.safe_harvest_on}</strong> ({relativeDays(c.safe_harvest_on)}).
        </div>
      ) : null}

      <ol className="mb-6 flex items-center gap-1 text-xs" aria-label="Stages">
        {STAGES.filter((s) => s !== "nursery" || c.planting_method === "transplant").map((s, i, all) => {
          const reached = all.indexOf(c.stage as (typeof STAGES)[number]) >= i;
          return (
            <li key={s} className="flex items-center gap-1">
              <span className={cn("rounded-full px-2.5 py-1 capitalize", reached ? "bg-primary text-primary-foreground" : "bg-surface-muted text-muted")} aria-current={c.stage === s ? "step" : undefined}>
                {s}
              </span>
              {i < all.length - 1 ? <span className="h-px w-4 bg-border" aria-hidden /> : null}
            </li>
          );
        })}
      </ol>

      <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
        <section aria-label="Timeline">
          <h2 className="mb-3 text-lg font-semibold">Timeline</h2>
          {!seesOps && !seesHarvests ? <p className="text-sm text-muted">You don&apos;t have access to this cycle&apos;s records.</p> : null}
          {timeline.length === 0 && (seesOps || seesHarvests) ? <p className="text-sm text-muted">Nothing recorded yet.</p> : null}
          <ol className="space-y-3">
            {timeline.map((e) => (
              <li key={`${e.kind}:${e.item.id}`}>
                <Card>
                  <CardContent className="flex gap-3 py-3">
                    <TimelineIcon entry={e} />
                    <div className="min-w-0 flex-1 text-sm">
                      {e.kind === "operation" ? (
                        <OperationEntry
                          op={e.item}
                          canVerify={canVerify && e.item.status === "recorded"}
                          onVerify={() => act(() => api.POST("/farms/{farm}/crop-operations/{operation}/verify", { params: { path: { farm: farmId, operation: e.item.id! } } }))}
                          onReject={() => {
                            const reason = window.prompt("Why is this record rejected?");
                            if (reason) void act(() => api.POST("/farms/{farm}/crop-operations/{operation}/reject", { params: { path: { farm: farmId, operation: e.item.id! } }, body: { reason } }));
                          }}
                        />
                      ) : e.kind === "observation" ? (
                        <ObservationEntry
                          obs={e.item}
                          canUpdate={open && canRecord}
                          onResolve={() => {
                            const note = window.prompt("How was it resolved? (optional)") ?? undefined;
                            void act(() => api.PATCH("/farms/{farm}/crop-observations/{observation}", { params: { path: { farm: farmId, observation: e.item.id! } }, body: { status: "resolved", resolution_note: note || null } }));
                          }}
                        />
                      ) : (
                        <HarvestEntry h={e.item} farmId={farmId} />
                      )}
                    </div>
                  </CardContent>
                </Card>
              </li>
            ))}
          </ol>
        </section>

        <aside className="space-y-4">
          <Card>
            <CardContent className="space-y-3 text-sm">
              <h2 className="font-semibold">Yield</h2>
              <p className="text-2xl font-semibold tabular-nums">
                {formatQuantity(c.actual_yield ?? 0)} <span className="text-base font-normal text-muted">/ {formatQuantity(c.expected_yield, c.yield_unit)}</span>
              </p>
              {progress !== null ? (
                <div className="h-2 overflow-hidden rounded-full bg-surface-muted" role="progressbar" aria-valuenow={progress} aria-valuemin={0} aria-valuemax={100} aria-label="Harvested of expected">
                  <div className="h-full bg-primary" style={{ width: `${Math.min(progress, 100)}%` }} />
                </div>
              ) : null}
              <dl className="grid grid-cols-2 gap-x-3 gap-y-2">
                <Fact k="Planted" v={c.planted_on ?? "—"} />
                <Fact k="Expected harvest" v={c.expected_harvest_on ? `${c.expected_harvest_on}` : "—"} />
                {c.sown_on ? <Fact k="Sown (nursery)" v={c.sown_on} /> : null}
                {c.nursery ? <Fact k="Seedlings" v={`${c.nursery.seeds_sown ?? "—"} sown · ${c.nursery.seedlings_transplanted ?? "—"} out`} /> : null}
                {c.closed_on ? <Fact k="Closed" v={`${c.closed_on} (${c.close_reason})`} /> : null}
              </dl>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="space-y-2 text-sm">
              <h2 className="font-semibold">Traceability</h2>
              {[
                ["Seed lot", c.seed_batch],
                ["Nursery", c.nursery_batch],
                ["Crop lot", c.crop_lot],
              ].map(([label, b]) =>
                b && typeof b === "object" ? (
                  <p key={label as string} className="flex items-center justify-between">
                    <span className="text-muted">{label as string}</span>
                    <Link className="font-mono text-primary hover:underline" href={`/farms/${farmId}/traceability/batches/${b.id}`}>
                      {b.batch_code}
                    </Link>
                  </p>
                ) : null,
              )}
              <p className="text-xs text-muted">Every verified operation, finding and harvest is an event on the crop lot.</p>
            </CardContent>
          </Card>
          {c.notes ? (
            <Card>
              <CardContent className="text-sm">
                <h2 className="mb-1 font-semibold">Notes</h2>
                <p className="whitespace-pre-line text-muted">{c.notes}</p>
              </CardContent>
            </Card>
          ) : null}
        </aside>
      </div>

      {dialog === "operation" ? (
        <OperationDialog farmId={farmId} cycle={c} observations={observations.data ?? []} seesMoney={can(perms, "finance.values.view")} onClose={() => setDialog(null)} onDone={refresh} />
      ) : null}
      {dialog === "observation" ? <ObservationDialog farmId={farmId} cycle={c} onClose={() => setDialog(null)} onDone={refresh} /> : null}
      {dialog === "harvest" ? <HarvestDialog farmId={farmId} cycle={c} canOverride={canVerify} onClose={() => setDialog(null)} onDone={refresh} /> : null}
      {dialog === "transplant" ? <TransplantDialog farmId={farmId} cycle={c} onClose={() => setDialog(null)} onDone={refresh} /> : null}
      {dialog === "close" ? <CloseCycleDialog farmId={farmId} cycle={c} onClose={() => setDialog(null)} onDone={refresh} /> : null}
    </>
  );
}

function Fact({ k, v }: { k: string; v: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-muted">{k}</dt>
      <dd>{v}</dd>
    </div>
  );
}

function TimelineIcon({ entry }: { entry: Entry }) {
  const cls = "mt-0.5 size-5 shrink-0";
  if (entry.kind === "harvest") return <Wheat className={cn(cls, "text-amber-600")} aria-hidden />;
  if (entry.kind === "observation") return <Bug className={cn(cls, "text-danger")} aria-hidden />;
  return entry.item.operation === "planting" ? <Sprout className={cn(cls, "text-primary")} aria-hidden /> : <Leaf className={cn(cls, "text-primary")} aria-hidden />;
}

function OperationEntry({ op, canVerify, onVerify, onReject }: { op: CropOperation; canVerify: boolean; onVerify: () => void; onReject: () => void }) {
  return (
    <>
      <p className="flex flex-wrap items-center gap-2">
        <span className="font-medium">{humanize(op.operation ?? "")}</span>
        <Badge tone={op.status === "verified" ? "primary" : op.status === "rejected" ? "danger" : "warning"}>{op.status}</Badge>
        <time className="text-xs text-muted" dateTime={op.occurred_at}>
          {formatDateTime(op.occurred_at)}
        </time>
      </p>
      {op.inputs && op.inputs.length > 0 ? (
        <ul className="mt-1 text-muted">
          {op.inputs.map((i) => (
            <li key={i.id}>
              {i.product_name}: {formatQuantity(i.quantity, i.unit)}
              {i.withholding_days ? <span className="text-warning"> · withholding {i.withholding_days} days</span> : null}
            </li>
          ))}
        </ul>
      ) : null}
      {op.notes ? <p className="mt-1 text-muted">{op.notes}</p> : null}
      <p className="mt-1 text-xs text-muted">
        {op.recorded_by?.name ? `Recorded by ${op.recorded_by.name}` : null}
        {op.labour_hours ? ` · ${op.labour_hours} h` : null}
        {op.cost_amount != null ? ` · cost ${op.cost_amount.toLocaleString()}` : null}
        {op.rejection_reason ? ` · rejected: ${op.rejection_reason}` : null}
      </p>
      {canVerify ? (
        <div className="mt-2 flex gap-2">
          <Button size="sm" variant="secondary" onClick={onVerify}>
            Verify
          </Button>
          <Button size="sm" variant="ghost" className="text-danger" onClick={onReject}>
            Reject
          </Button>
        </div>
      ) : null}
    </>
  );
}

function ObservationEntry({ obs, canUpdate, onResolve }: { obs: CropObservation; canUpdate: boolean; onResolve: () => void }) {
  return (
    <>
      <p className="flex flex-wrap items-center gap-2">
        <span className="font-medium">{obs.title}</span>
        <Badge tone={SEVERITY_TONE[obs.severity ?? "low"]}>{obs.severity}</Badge>
        <Badge tone={obs.status === "resolved" ? "neutral" : "warning"}>{obs.status}</Badge>
        <time className="text-xs text-muted" dateTime={obs.observed_at}>
          {formatDateTime(obs.observed_at)}
        </time>
      </p>
      <p className="mt-1 text-muted">
        {humanize(obs.kind ?? "")}
        {obs.affected_pct != null ? ` · ${obs.affected_pct}% of plants` : ""}
        {obs.treatments ? ` · ${obs.treatments} treatment${obs.treatments > 1 ? "s" : ""}` : ""}
      </p>
      {obs.description ? <p className="mt-1 text-muted">{obs.description}</p> : null}
      {obs.resolution_note ? <p className="mt-1 text-xs text-muted">Resolved: {obs.resolution_note}</p> : null}
      {canUpdate && obs.status !== "resolved" ? (
        <Button size="sm" variant="ghost" className="mt-1" onClick={onResolve}>
          Mark resolved
        </Button>
      ) : null}
    </>
  );
}

function HarvestEntry({ h, farmId }: { h: CropHarvest; farmId: string }) {
  return (
    <>
      <p className="flex flex-wrap items-center gap-2">
        <span className="font-medium">Harvest: {formatQuantity(h.quantity, h.unit)}</span>
        {h.quality_grade ? <Badge tone="neutral">grade {h.quality_grade}</Badge> : null}
        <span className="text-xs text-muted">{h.harvested_on}</span>
      </p>
      <p className="mt-1 text-muted">
        Batch{" "}
        <Link className="font-mono text-primary hover:underline" href={`/farms/${farmId}/traceability/batches/${h.batch?.id}`}>
          {h.batch?.batch_code}
        </Link>
        {h.moisture_pct != null ? ` · moisture ${h.moisture_pct}%` : ""}
      </p>
      {h.withholding_override_reason ? <p className="mt-1 text-xs text-warning">Withholding override: {h.withholding_override_reason}</p> : null}
    </>
  );
}
