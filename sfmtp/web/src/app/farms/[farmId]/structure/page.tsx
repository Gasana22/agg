"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Flame, Plus } from "lucide-react";
import dynamic from "next/dynamic";
import { useParams } from "next/navigation";
import { useCallback, useMemo, useState } from "react";

import type { DrawMode, MapSelection } from "@/components/structure/farm-map";
import { NodeDetails } from "@/components/structure/node-details";
import { NodeDialog, type NodeDialogState } from "@/components/structure/node-dialog";
import { SoilDialog } from "@/components/structure/soil-dialog";
import { StructureTree } from "@/components/structure/structure-tree";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Select, Textarea } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { HEAT_LAYERS } from "@/lib/analytics";
import { ApiError } from "@/lib/api/errors";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { can } from "@/lib/permissions";
import { type AnyNode, buildTree, formatHa, type NodeType, parsePolygon, type Plot, polygonFromPoints, type Warning } from "@/lib/structure";
import { archiveNode, updateNode } from "@/lib/structure-api";

const FarmMap = dynamic(() => import("@/components/structure/farm-map"), { ssr: false, loading: () => <Skeleton className="h-full min-h-[420px] w-full" /> });

type DrawTarget = { type: NodeType; id: string };

export default function StructurePage() {
  const { farmId } = useParams<{ farmId: string }>();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const canManage = can(workspace?.permissions, "structure.manage") && workspace?.type === "farm";
  const canSoil = can(workspace?.permissions, "structure.soil.manage") && workspace?.type === "farm";

  const structure = useQuery({
    queryKey: ["structure", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/structure", { params: { path: { farm: farmId } } })).data!.data!,
  });

  const [selected, setSelected] = useState<MapSelection>(null);
  const [dialog, setDialog] = useState<NodeDialogState>(null);
  const [soilPlot, setSoilPlot] = useState<Plot | null>(null);
  const [draw, setDraw] = useState<DrawMode>(null);
  const [drawTarget, setDrawTarget] = useState<DrawTarget | null>(null);
  const [paste, setPaste] = useState<string | null>(null);
  const [warnings, setWarnings] = useState<Warning[]>([]);
  const [error, setError] = useState<ApiError | null>(null);
  const canHeat = can(workspace?.permissions, "maps.view");
  const [heatOn, setHeatOn] = useState(false);
  const [heatPeriod, setHeatPeriod] = useState<"7d" | "30d" | "90d">("30d");
  const [heatLayers, setHeatLayers] = useState<string[] | null>(null);
  const activity = useQuery({
    queryKey: ["activity-heatmap", farmId, heatPeriod, heatLayers],
    enabled: heatOn && canHeat,
    queryFn: async () =>
      (await api.GET("/farms/{farm}/maps/activity", { params: { path: { farm: farmId }, query: { period: heatPeriod, ...(heatLayers ? { layers: heatLayers.join(",") } : {}) } } })).data!.data!,
  });
  const visibleLayers = HEAT_LAYERS.filter((l) => !(activity.data?.hidden_layers ?? []).includes(l.key));
  function toggleLayer(key: string) {
    const all = visibleLayers.map((l) => l.key);
    const current = heatLayers ?? all;
    const next = current.includes(key) ? current.filter((k) => k !== key) : [...current, key];
    if (next.length > 0) setHeatLayers(next.length === all.length ? null : next);
  }

  const data = structure.data;
  const tree = useMemo(() => buildTree(data ?? {}), [data]);
  const find = useCallback(
    (s: MapSelection): AnyNode | undefined => {
      if (!s || !data) return undefined;
      const list = { block: data.blocks, section: data.sections, plot: data.plots, location: data.locations }[s.type] ?? [];
      return (list as AnyNode[]).find((n) => n.id === s.id);
    },
    [data],
  );
  const selectedNode = find(selected);

  const refresh = () => queryClient.invalidateQueries({ queryKey: ["structure", farmId] });
  const onSelect = useCallback((s: MapSelection) => setSelected(s), []);

  function startDrawing(target: DrawTarget) {
    setError(null);
    setWarnings([]);
    setDrawTarget(target);
    setDraw(target.type === "location" ? { kind: "point", point: null } : { kind: "polygon", points: [] });
  }

  function stopDrawing() {
    setDraw(null);
    setDrawTarget(null);
    setPaste(null);
  }

  async function saveShape(body: Record<string, unknown>) {
    if (!drawTarget) return;
    try {
      const result = await updateNode(farmId, drawTarget.type, drawTarget.id, body);
      setWarnings(result.meta?.warnings ?? []);
      stopDrawing();
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  async function finishDrawing() {
    if (!draw) return;
    if (draw.kind === "point") {
      if (draw.point) await saveShape({ latitude: draw.point[0], longitude: draw.point[1] });
      return;
    }
    const polygon = polygonFromPoints(draw.points);
    if (polygon) await saveShape({ boundary: polygon });
  }

  async function savePasted() {
    const polygon = parsePolygon(paste ?? "");
    if (!polygon) {
      setError(new ApiError({ title: "Paste a GeoJSON Polygon (or a Feature holding one).", status: 422, code: "validation_failed" }));
      return;
    }
    await saveShape({ boundary: polygon });
  }

  async function archive(type: NodeType, node: AnyNode) {
    if (!window.confirm(`Archive ${node.code} · ${node.name}? It disappears from the map; its history is kept.`)) return;
    try {
      await archiveNode(farmId, type, node.id!);
      setSelected(null);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  if (structure.isLoading) return <Skeleton className="h-[540px] w-full" />;
  if (structure.error) return <ErrorNotice error={structure.error} />;
  const totals = data!.totals!;

  return (
    <>
      <PageHeader
        title="Farm map"
        description={`${totals.plots} plots · ${formatHa(totals.mapped_area_ha)} mapped${totals.farm_size_ha ? ` of ${formatHa(totals.farm_size_ha)}` : ""} · ${totals.locations} locations`}
        actions={
          canManage ? (
            <>
              <Button variant="secondary" size="sm" onClick={() => setDialog({ mode: "create", type: "block" })}>
                <Plus /> Block
              </Button>
              <Button variant="secondary" size="sm" onClick={() => setDialog({ mode: "create", type: "plot" })}>
                <Plus /> Plot
              </Button>
              <Button variant="secondary" size="sm" onClick={() => setDialog({ mode: "create", type: "location" })}>
                <Plus /> Location
              </Button>
            </>
          ) : null
        }
      />

      {warnings.length > 0 ? (
        <div className="mb-4 rounded-lg border border-warning/40 bg-warning/10 px-4 py-3 text-sm" role="status">
          <p className="font-medium">Saved, with a note to check:</p>
          <ul className="mt-1 list-disc pl-5">
            {warnings.map((w, i) => (
              <li key={i}>{w.message}</li>
            ))}
          </ul>
        </div>
      ) : null}
      {error ? (
        <div className="mb-4">
          <ErrorNotice error={error} />
          {error.problem.errors?.boundary ? <p className="mt-1 text-sm text-danger">{error.problem.errors.boundary.join(" ")}</p> : null}
        </div>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-[320px_1fr]">
        <div className="space-y-4">
          <Card>
            <CardContent className="max-h-[420px] overflow-y-auto p-2">
              <StructureTree tree={tree} locations={data!.locations ?? []} selected={selected} onSelect={onSelect} />
            </CardContent>
          </Card>
          {selected && selectedNode ? (
            <NodeDetails
              type={selected.type}
              node={selectedNode}
              canManage={canManage}
              canSoil={canSoil}
              onEdit={() => setDialog({ mode: "edit", type: selected.type, node: selectedNode })}
              onDraw={() => startDrawing(selected)}
              onClearShape={() => {
                setDrawTarget(selected);
                void updateNode(farmId, selected.type, selected.id, { boundary: null }).then(refresh, (err) => setError(err instanceof ApiError ? err : null));
              }}
              onSoil={() => setSoilPlot(selectedNode as Plot)}
              onArchive={() => archive(selected.type, selectedNode)}
              onAddChild={() =>
                setDialog({ mode: "create", type: ({ block: "section", section: "plot", plot: "location" } as const)[selected.type as "block"], parentId: selected.id })
              }
            />
          ) : null}
        </div>

        <div className="flex min-h-[540px] flex-col gap-2">
          {canHeat && !draw ? (
            <div className="flex flex-wrap items-center gap-2 text-sm">
              <Button size="sm" variant={heatOn ? "primary" : "secondary"} aria-pressed={heatOn} onClick={() => setHeatOn(!heatOn)}>
                <Flame /> Activity
              </Button>
              {heatOn ? (
                <>
                  <Select aria-label="Activity period" className="h-8 w-36" value={heatPeriod} onChange={(e) => setHeatPeriod(e.target.value as typeof heatPeriod)}>
                    <option value="7d">Last 7 days</option>
                    <option value="30d">Last 30 days</option>
                    <option value="90d">Last 90 days</option>
                  </Select>
                  <span className="flex flex-wrap gap-1" role="group" aria-label="Activity layers">
                    {visibleLayers.map((l) => {
                      const on = heatLayers === null || heatLayers.includes(l.key);
                      const points = activity.data?.layers?.find((x) => x.key === l.key)?.points;
                      return (
                        <button
                          key={l.key}
                          type="button"
                          aria-pressed={on}
                          onClick={() => toggleLayer(l.key)}
                          className={`rounded-full border px-2 py-0.5 text-xs ${on ? "border-primary bg-primary/10 text-foreground" : "border-border text-muted"}`}
                        >
                          {l.label}
                          {points !== undefined ? ` · ${points}` : ""}
                        </button>
                      );
                    })}
                  </span>
                  {activity.isFetching ? <span className="text-xs text-muted">Loading…</span> : null}
                  {activity.data && activity.data.cells?.length === 0 ? <span className="text-xs text-muted">No located activity in this period.</span> : null}
                </>
              ) : null}
            </div>
          ) : null}
          {heatOn && activity.error ? <ErrorNotice error={activity.error} /> : null}
          {draw ? (
            <div className="flex flex-wrap items-center gap-2 rounded-lg border border-accent/40 bg-surface px-3 py-2 text-sm" role="status">
              <span className="flex-1">
                {draw.kind === "point"
                  ? draw.point
                    ? "Pin placed. Save it, or click elsewhere to move it."
                    : "Click the map where this location is."
                  : `Click the map to add corners (${draw.points.length} so far). At least 3 are needed.`}
              </span>
              {draw.kind === "polygon" ? (
                <>
                  <Button size="sm" variant="ghost" disabled={draw.points.length === 0} onClick={() => setDraw({ kind: "polygon", points: draw.points.slice(0, -1) })}>
                    Undo
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setPaste(paste === null ? "" : null)}>
                    Paste GeoJSON
                  </Button>
                </>
              ) : null}
              <Button size="sm" variant="ghost" onClick={stopDrawing}>
                Cancel
              </Button>
              <Button size="sm" disabled={draw.kind === "polygon" ? draw.points.length < 3 : !draw.point} onClick={finishDrawing}>
                Save
              </Button>
              {paste !== null ? (
                <div className="w-full space-y-2">
                  <Textarea aria-label="GeoJSON" rows={4} placeholder='{"type":"Polygon","coordinates":[[[32.5,0.3],…]]}' value={paste} onChange={(e) => setPaste(e.target.value)} />
                  <Button size="sm" variant="secondary" onClick={savePasted}>
                    Use pasted boundary
                  </Button>
                </div>
              ) : null}
            </div>
          ) : null}
          <div className="flex-1">
            <FarmMap
              blocks={data!.blocks ?? []}
              sections={data!.sections ?? []}
              plots={data!.plots ?? []}
              locations={data!.locations ?? []}
              selected={selected}
              onSelect={onSelect}
              draw={draw}
              onDrawChange={setDraw}
              heat={heatOn && activity.data ? { cells: activity.data.cells ?? [], max: activity.data.max ?? 0 } : null}
            />
          </div>
        </div>
      </div>

      <NodeDialog
        farmId={farmId}
        state={dialog}
        blocks={data!.blocks ?? []}
        sections={data!.sections ?? []}
        plots={data!.plots ?? []}
        onClose={() => setDialog(null)}
        onSaved={async (node, saveWarnings, drawNext) => {
          const type = dialog!.type;
          setDialog(null);
          setWarnings(saveWarnings);
          await refresh();
          setSelected({ type, id: node.id! });
          if (drawNext) startDrawing({ type, id: node.id! });
        }}
      />
      <SoilDialog
        farmId={farmId}
        plot={soilPlot}
        onClose={() => setSoilPlot(null)}
        onSaved={async () => {
          setSoilPlot(null);
          await refresh();
        }}
      />
    </>
  );
}
