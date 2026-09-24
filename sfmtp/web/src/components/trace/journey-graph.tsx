"use client";

import Link from "next/link";
import { useEffect, useRef } from "react";

import { KIND_LABELS } from "@/components/trace/labels";
import { cn } from "@/lib/utils";
import { formatQty, layoutJourney, NODE_H, NODE_W, type Batch, type Graph } from "@/lib/trace";

const STATUS_RING: Record<string, string> = { open: "border-border", closed: "border-border opacity-80", recalled: "border-danger" };

/**
 * The batch graph: where the batch came from (left) and where it went
 * (right), with the link and quantity on each edge. Nodes open that batch.
 */
export function JourneyGraph({ farmId, batch, backward, forward }: { farmId: string; batch: Batch; backward: Graph | null | undefined; forward: Graph | null | undefined }) {
  const { nodes, edges, width, height } = layoutJourney(batch, backward, forward);
  const pad = 8;
  const scroller = useRef<HTMLDivElement>(null);
  const centerX = nodes.find((n) => n.center)?.x ?? 0;

  // Long journeys scroll sideways: start with this batch in view.
  useEffect(() => {
    const el = scroller.current;
    if (el) el.scrollLeft = Math.max(0, pad + centerX + NODE_W / 2 - el.clientWidth / 2);
  }, [centerX]);

  return (
    <div ref={scroller} className="overflow-x-auto pb-2" role="figure" aria-label={`Journey of ${batch.batch_code}: ${nodes.length - 1} connected batches`}>
      <div className="relative" style={{ width: width + pad * 2, height: height + pad * 2 }}>
        <svg className="absolute inset-0" width={width + pad * 2} height={height + pad * 2} aria-hidden>
          <defs>
            <marker id="arrow" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
              <path d="M0,0 L8,4 L0,8 z" className="fill-muted" />
            </marker>
          </defs>
          {edges.map((e) => {
            const x1 = pad + e.from.x + NODE_W;
            const y1 = pad + e.from.y + NODE_H / 2;
            const x2 = pad + e.to.x;
            const y2 = pad + e.to.y + NODE_H / 2;
            const mid = (x1 + x2) / 2;
            return (
              <g key={e.id}>
                <path d={`M${x1},${y1} C${mid},${y1} ${mid},${y2} ${x2 - 2},${y2}`} className="fill-none stroke-muted/70" strokeWidth={1.5} markerEnd="url(#arrow)" />
                <text x={mid} y={(y1 + y2) / 2 - 5} textAnchor="middle" className="fill-muted stroke-surface text-[10px]" strokeWidth={3} paintOrder="stroke">
                  {e.label}
                </text>
              </g>
            );
          })}
        </svg>
        {nodes.map((n) => {
          const body = (
            <>
              <span className="flex items-center justify-between gap-2">
                <span className="truncate text-[11px] uppercase tracking-wide text-muted">{KIND_LABELS[n.node.kind ?? ""] ?? n.node.kind}</span>
                {n.node.status === "recalled" ? <span className="text-[10px] font-semibold uppercase text-danger">recalled</span> : null}
              </span>
              <span className="block truncate font-mono text-xs font-medium">{n.node.batch_code}</span>
              <span className="block truncate text-xs text-muted">{n.node.name ?? formatQty(n.node.quantity)}</span>
            </>
          );
          const className = cn(
            "absolute block rounded-lg border bg-surface px-2.5 py-1.5 shadow-sm",
            STATUS_RING[n.node.status ?? "open"],
            n.center && "border-primary ring-2 ring-primary/30",
          );
          const style = { left: pad + n.x, top: pad + n.y, width: NODE_W, height: NODE_H };
          return n.center ? (
            <div key={n.id} className={className} style={style} aria-current="page">
              {body}
            </div>
          ) : (
            <Link key={n.id} href={`/farms/${farmId}/traceability/batches/${n.id}`} className={cn(className, "hover:border-primary")} style={style}>
              {body}
            </Link>
          );
        })}
      </div>
    </div>
  );
}
