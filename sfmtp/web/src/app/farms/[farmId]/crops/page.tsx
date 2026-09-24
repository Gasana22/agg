"use client";

import { useQuery } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import Link from "next/link";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { PlansPanel } from "@/components/crops/plans-panel";
import { useSeasons } from "@/components/crops/queries";
import { SetupPanel } from "@/components/crops/setup-panel";
import { StageBadge } from "@/components/crops/stage-badge";
import { StartCycleDialog } from "@/components/crops/start-cycle-dialog";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { formatQuantity, relativeDays, underWithholding } from "@/lib/crops";
import { can } from "@/lib/permissions";
import { formatHa } from "@/lib/structure";
import { cn } from "@/lib/utils";

const TABS = [
  { key: "cycles", label: "Crop cycles" },
  { key: "plans", label: "Plans" },
  { key: "setup", label: "Crops & seasons" },
] as const;

const ACTION_TEXT: Record<string, string> = {
  operation: "Choose the crop cycle to record field work for.",
  observation: "Choose the crop cycle where you saw the problem.",
  harvest: "Choose the crop cycle you are harvesting.",
};

export default function CropsPage() {
  return (
    <Suspense>
      <Crops />
    </Suspense>
  );
}

function Crops() {
  const { farmId } = useParams<{ farmId: string }>();
  const search = useSearchParams();
  const router = useRouter();
  const { workspace } = useFarmWorkspace(farmId);
  const writable = workspace?.type === "farm";
  const perms = workspace?.permissions;
  const canManage = writable && can(perms, "crops.plans.manage");

  const tab = (search.get("tab") as (typeof TABS)[number]["key"]) ?? "cycles";
  const action = search.get("action");
  const [starting, setStarting] = useState(search.get("new") === "cycle");
  const [stage, setStage] = useState("open");
  const [season, setSeason] = useState("");
  const seasons = useSeasons(farmId);
  const farm = useQuery({
    queryKey: ["farm", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}", { params: { path: { farm: farmId } } })).data!.data!,
    enabled: tab === "plans",
  });

  const cycles = useQuery({
    queryKey: ["crop-cycles", farmId, stage, season],
    queryFn: async () =>
      (
        await api.GET("/farms/{farm}/crop-cycles", {
          params: {
            path: { farm: farmId },
            query: { "filter[stage]": stage === "all" ? undefined : (stage as "open"), "filter[season_id]": season || undefined, per_page: 100 },
          },
        })
      ).data!.data!,
    enabled: tab === "cycles",
  });

  const setTab = (key: string) => router.replace(`/farms/${farmId}/crops${key === "cycles" ? "" : `?tab=${key}`}`);

  return (
    <>
      <PageHeader
        title="Crops"
        description="What is growing where, from planting to harvest. Every step is kept in the traceability history."
        actions={
          canManage && tab === "cycles" ? (
            <Button onClick={() => setStarting(true)}>
              <Plus /> Start cycle
            </Button>
          ) : null
        }
      />

      <div role="tablist" aria-label="Crop sections" className="mb-4 flex gap-1 border-b border-border">
        {TABS.map((t) => (
          <button
            key={t.key}
            role="tab"
            type="button"
            aria-selected={tab === t.key}
            onClick={() => setTab(t.key)}
            className={cn("-mb-px border-b-2 px-3 py-2 text-sm", tab === t.key ? "border-primary font-medium text-foreground" : "border-transparent text-muted hover:text-foreground")}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === "cycles" ? (
        <>
          {action && ACTION_TEXT[action] ? (
            <div className="mb-3 rounded-lg border border-accent/40 bg-surface px-4 py-2 text-sm" role="status">
              {ACTION_TEXT[action]}
            </div>
          ) : null}
          <div className="mb-3 flex flex-wrap gap-2">
            <Select aria-label="Stage" className="h-9 w-40" value={stage} onChange={(e) => setStage(e.target.value)}>
              <option value="open">Open cycles</option>
              <option value="nursery">In nursery</option>
              <option value="planted">Planted</option>
              <option value="growing">Growing</option>
              <option value="harvesting">Harvesting</option>
              <option value="closed">Closed</option>
              <option value="all">All</option>
            </Select>
            <Select aria-label="Season" className="h-9 w-48" value={season} onChange={(e) => setSeason(e.target.value)}>
              <option value="">All seasons</option>
              {seasons.data?.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </Select>
          </div>
          {cycles.isLoading ? (
            <Skeleton className="h-48 w-full" />
          ) : cycles.error ? (
            <ErrorNotice error={cycles.error} />
          ) : cycles.data!.length === 0 ? (
            <EmptyState title="No crop cycles here">{canManage ? "Start a cycle when you plant or sow." : null}</EmptyState>
          ) : (
            <Table>
              <thead>
                <tr>
                  <Th>Cycle</Th>
                  <Th>Plot</Th>
                  <Th>Stage</Th>
                  <Th>Planted</Th>
                  <Th>Expected harvest</Th>
                  <Th className="text-right">Yield (harvested / expected)</Th>
                  <Th>Alerts</Th>
                </tr>
              </thead>
              <tbody>
                {cycles.data!.map((c) => (
                  <tr key={c.id} className="hover:bg-surface-muted/50">
                    <Td>
                      <Link href={`/farms/${farmId}/crops/cycles/${c.id}${action ? `?action=${action}` : ""}`} className="font-medium text-primary hover:underline">
                        {c.crop?.label}
                      </Link>
                      <span className="block font-mono text-xs text-muted">
                        {c.code}
                        {c.plan ? ` · ${c.plan.code}` : ""}
                      </span>
                    </Td>
                    <Td>
                      {c.plot?.code} <span className="text-xs text-muted">· {formatHa(c.area_ha)}</span>
                    </Td>
                    <Td>
                      <StageBadge stage={c.stage} />
                    </Td>
                    <Td className="text-muted">{c.planted_on ?? (c.sown_on ? `sown ${c.sown_on}` : "—")}</Td>
                    <Td className="text-muted">{c.stage === "closed" ? `closed ${c.closed_on}` : c.expected_harvest_on ? `${c.expected_harvest_on} (${relativeDays(c.expected_harvest_on)})` : "—"}</Td>
                    <Td className="text-right tabular-nums">
                      {formatQuantity(c.actual_yield ?? 0)} / {formatQuantity(c.expected_yield, c.yield_unit)}
                    </Td>
                    <Td>
                      <div className="flex flex-wrap gap-1">
                        {(c.open_observations ?? 0) > 0 ? <Badge tone="danger">{c.open_observations} open</Badge> : null}
                        {underWithholding(c) ? <Badge tone="warning">withholding to {c.safe_harvest_on}</Badge> : null}
                      </div>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </>
      ) : null}

      {tab === "plans" ? (
        <PlansPanel
          farmId={farmId}
          canManage={canManage}
          canApprove={writable && can(perms, "crops.plans.approve")}
          seesMoney={can(perms, "finance.values.view")}
          currency={farm.data?.currency ?? ""}
          startOpen={search.get("new") === "plan"}
        />
      ) : null}
      {tab === "setup" ? <SetupPanel farmId={farmId} canManage={canManage} /> : null}

      {starting ? (
        <StartCycleDialog
          farmId={farmId}
          onClose={() => setStarting(false)}
          onStarted={(cycle, warnings) => {
            setStarting(false);
            const note = warnings.length ? `?warning=${encodeURIComponent(warnings.join(" "))}` : "";
            router.push(`/farms/${farmId}/crops/cycles/${cycle.id}${note}`);
          }}
        />
      ) : null}
    </>
  );
}
