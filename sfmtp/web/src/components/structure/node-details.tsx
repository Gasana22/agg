"use client";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { formatDateTime, humanize } from "@/lib/format";
import { type AnyNode, formatHa, NODE_LABEL, type NodeType, type Plot } from "@/lib/structure";

type Props = {
  type: NodeType;
  node: AnyNode;
  canManage: boolean;
  canSoil: boolean;
  onEdit: () => void;
  onDraw: () => void;
  onClearShape: () => void;
  onSoil: () => void;
  onArchive: () => void;
  onAddChild?: () => void;
};

const CHILD: Partial<Record<NodeType, string>> = { block: "section", section: "plot", plot: "location" };

export function NodeDetails({ type, node, canManage, canSoil, onEdit, onDraw, onClearShape, onSoil, onArchive, onAddChild }: Props) {
  const isLocation = type === "location";
  const hasShape = Boolean(node.boundary);
  const hasPoint = isLocation && (node as { latitude?: number | null }).latitude != null;
  const soil = type === "plot" ? ((node as Plot).soil_profile as Record<string, unknown> | null) : null;

  const facts: [string, React.ReactNode][] = [
    ["Measured area", formatHa(node.area_ha)],
    ["Declared area", formatHa(node.declared_area_ha)],
  ];
  if (type === "plot") {
    const p = node as Plot;
    facts.push(["Land use", humanize(p.land_use ?? "")], ["Water", humanize(p.irrigation ?? "")]);
  }
  if (isLocation) facts.push(["Kind", humanize((node as { kind?: string }).kind ?? "")]);

  return (
    <Card>
      <CardContent className="space-y-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-muted">
            {NODE_LABEL[type]} · <span className="font-mono">{node.code}</span>
          </p>
          <h2 className="text-lg font-semibold">{node.name}</h2>
          {node.description ? <p className="mt-1 text-sm text-muted">{node.description}</p> : null}
        </div>

        <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
          {facts.map(([k, v]) => (
            <div key={k}>
              <dt className="text-xs text-muted">{k}</dt>
              <dd>{v}</dd>
            </div>
          ))}
        </dl>

        {type === "plot" ? (
          <div className="rounded-lg bg-surface-muted/60 p-3 text-sm">
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">Soil</p>
            {soil && Object.keys(soil).length > 0 ? (
              <p>
                {[
                  soil.texture ? humanize(String(soil.texture)) : null,
                  soil.ph != null ? `pH ${soil.ph}` : null,
                  soil.organic_matter_pct != null ? `OM ${soil.organic_matter_pct}%` : null,
                  soil.drainage ? `${humanize(String(soil.drainage))} drainage` : null,
                ]
                  .filter(Boolean)
                  .join(" · ") || "Recorded"}
                <span className="block text-xs text-muted">
                  {soil.tested_on ? `Tested ${soil.tested_on}` : null}
                  {soil.laboratory ? ` · ${soil.laboratory}` : null}
                  {(node as Plot).soil_updated_at ? ` · updated ${formatDateTime((node as Plot).soil_updated_at)}` : null}
                </span>
              </p>
            ) : (
              <p className="text-muted">No soil test recorded.</p>
            )}
          </div>
        ) : null}

        <div className="flex flex-wrap gap-2">
          {canManage ? (
            <>
              <Button size="sm" variant="secondary" onClick={onEdit}>
                Edit
              </Button>
              <Button size="sm" variant="secondary" onClick={onDraw}>
                {isLocation ? (hasPoint ? "Move pin" : "Place on map") : hasShape ? "Redraw boundary" : "Draw boundary"}
              </Button>
              {hasShape ? (
                <Button size="sm" variant="ghost" onClick={onClearShape}>
                  Clear boundary
                </Button>
              ) : null}
              {onAddChild && CHILD[type] ? (
                <Button size="sm" variant="ghost" onClick={onAddChild}>
                  Add {CHILD[type]}
                </Button>
              ) : null}
            </>
          ) : null}
          {type === "plot" && canSoil ? (
            <Button size="sm" variant="secondary" onClick={onSoil}>
              Record soil test
            </Button>
          ) : null}
          {canManage ? (
            <Button size="sm" variant="ghost" className="text-danger" onClick={onArchive}>
              Archive
            </Button>
          ) : null}
        </div>
      </CardContent>
    </Card>
  );
}
