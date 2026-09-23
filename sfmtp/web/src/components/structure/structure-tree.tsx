"use client";

import { MapPin, Square, SquareDashed } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatHa, LAND_USE_COLOR, type Location, type NodeType, type Tree } from "@/lib/structure";

import type { MapSelection } from "./farm-map";

/** The farm layout as a list: blocks → sections → plots, then locations. */
export function StructureTree({ tree, locations, selected, onSelect }: { tree: Tree; locations: Location[]; selected: MapSelection; onSelect: (s: MapSelection) => void }) {
  const row = (type: NodeType, id: string | undefined, depth: number, content: React.ReactNode) => (
    <li key={`${type}:${id}`}>
      <button
        type="button"
        onClick={() => onSelect({ type, id: id! })}
        aria-current={selected?.type === type && selected.id === id ? "true" : undefined}
        className={cn(
          "flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-surface-muted",
          selected?.type === type && selected.id === id && "bg-primary-soft text-primary",
        )}
        style={{ paddingLeft: `${0.5 + depth * 1}rem` }}
      >
        {content}
      </button>
    </li>
  );

  const empty = tree.blocks.length === 0 && tree.loosePlots.length === 0 && locations.length === 0;

  return (
    <nav aria-label="Farm structure">
      {empty ? <p className="px-2 py-4 text-sm text-muted">Nothing mapped yet. Start with a block, or add plots directly.</p> : null}
      <ul className="space-y-0.5">
        {tree.blocks.map((b) => (
          <FragmentList key={b.id}>
            {row("block", b.id, 0, <Label icon={<SquareDashed className="size-4 shrink-0" />} code={b.code} name={b.name} area={b.effective_area_ha} />)}
            {b.sections.map((s) => (
              <FragmentList key={s.id}>
                {row("section", s.id, 1, <Label icon={<SquareDashed className="size-3.5 shrink-0 opacity-70" />} code={s.code} name={s.name} area={s.effective_area_ha} />)}
                {s.plots.map((p) => row("plot", p.id, 2, <PlotLabel code={p.code} name={p.name} area={p.effective_area_ha} landUse={p.land_use} mapped={!!p.boundary} />))}
              </FragmentList>
            ))}
          </FragmentList>
        ))}
        {tree.loosePlots.map((p) => row("plot", p.id, 0, <PlotLabel code={p.code} name={p.name} area={p.effective_area_ha} landUse={p.land_use} mapped={!!p.boundary} />))}
      </ul>
      {locations.length > 0 ? (
        <>
          <p className="mt-4 px-2 text-xs font-semibold uppercase tracking-wide text-muted">Locations</p>
          <ul className="mt-1 space-y-0.5">
            {locations.map((l) => row("location", l.id, 0, <Label icon={<MapPin className="size-4 shrink-0 text-blue-600" />} code={l.code} name={l.name} extra={l.kind} />))}
          </ul>
        </>
      ) : null}
    </nav>
  );
}

function FragmentList({ children }: { children: React.ReactNode }) {
  return <>{children}</>;
}

function Label({ icon, code, name, area, extra }: { icon: React.ReactNode; code?: string; name?: string; area?: number | null; extra?: string }) {
  return (
    <>
      {icon}
      <span className="font-mono text-xs text-muted">{code}</span>
      <span className="min-w-0 flex-1 truncate">{name}</span>
      {area !== undefined ? <span className="text-xs text-muted">{formatHa(area)}</span> : null}
      {extra ? <span className="text-xs capitalize text-muted">{extra}</span> : null}
    </>
  );
}

function PlotLabel({ code, name, area, landUse, mapped }: { code?: string; name?: string; area?: number | null; landUse?: string; mapped: boolean }) {
  return (
    <Label
      icon={<Square className="size-3.5 shrink-0" style={{ color: LAND_USE_COLOR[landUse ?? "other"], fill: mapped ? LAND_USE_COLOR[landUse ?? "other"] : "none", fillOpacity: 0.35 }} aria-label={mapped ? "Mapped" : "Not mapped"} />}
      code={code}
      name={name}
      area={area}
    />
  );
}
