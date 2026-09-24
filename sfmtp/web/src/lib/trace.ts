import type { components } from "@/lib/api/client";

export type Batch = components["schemas"]["Batch"];
export type Graph = components["schemas"]["Graph"];
export type GraphNode = components["schemas"]["GraphNode"];
export type Journey = components["schemas"]["Journey"];
export type TimelineEvent = components["schemas"]["TimelineEvent"];
export type TraceAlert = components["schemas"]["TraceAlert"];
export type Shipment = components["schemas"]["Shipment"];

/** "1,020 kg", or "—" without a quantity. */
export function formatQty(q: { value?: string | number | null; unit?: string | null } | null | undefined): string {
  if (!q || q.value === null || q.value === undefined) return "—";
  return `${Number(q.value).toLocaleString(undefined, { maximumFractionDigits: 3 })} ${q.unit ?? ""}`.trim();
}

export const LINK_LABELS: Record<string, string> = {
  derived: "grew into",
  split: "split",
  merge: "merged",
  process: "processed",
  package: "packed",
  ship: "shipped",
};

export const ALERT_TONE = { critical: "danger", warning: "warning", info: "info" } as const;

/** Batches that can be split, processed, packed or shipped. */
export function isUsable(batch: Pick<Batch, "status" | "kind">): boolean {
  return batch.status === "open" && batch.kind !== "shipment";
}

export type PlacedNode = { id: string; col: number; row: number; x: number; y: number; node: GraphNode; center: boolean };
export type PlacedEdge = { id: string; from: PlacedNode; to: PlacedNode; label: string };

export const NODE_W = 172;
export const NODE_H = 64;
const GAP_X = 96;
const GAP_Y = 20;

/**
 * Columns for the journey graph: sources to the left (by how far upstream
 * they are), the batch in the middle, destinations to the right.
 */
export function layoutJourney(batch: Batch, backward: Graph | null | undefined, forward: Graph | null | undefined) {
  const cols = new Map<number, GraphNode[]>();
  const center: GraphNode = { id: batch.id, batch_code: batch.batch_code, kind: batch.kind, name: batch.name, status: batch.status, quantity: batch.quantity, depth: 0 };
  const put = (col: number, n: GraphNode) => cols.set(col, [...(cols.get(col) ?? []), n]);
  put(0, center);
  for (const n of backward?.nodes ?? []) put(-(n.depth ?? 1), n);
  for (const n of forward?.nodes ?? []) if (n.id !== batch.id) put(n.depth ?? 1, n);

  const keys = [...cols.keys()].sort((a, b) => a - b);
  const minCol = keys[0] ?? 0;
  const tallest = Math.max(...[...cols.values()].map((c) => c.length));
  const height = tallest * NODE_H + (tallest - 1) * GAP_Y;
  const placed = new Map<string, PlacedNode>();
  for (const col of keys) {
    const list = [...cols.get(col)!].sort((a, b) => String(a.kind).localeCompare(String(b.kind)) || String(a.batch_code).localeCompare(String(b.batch_code)));
    const colHeight = list.length * NODE_H + (list.length - 1) * GAP_Y;
    list.forEach((node, row) => {
      if (placed.has(node.id!)) return;
      placed.set(node.id!, {
        id: node.id!,
        col,
        row,
        x: (col - minCol) * (NODE_W + GAP_X),
        y: (height - colHeight) / 2 + row * (NODE_H + GAP_Y),
        node,
        center: col === 0,
      });
    });
  }

  const edges: PlacedEdge[] = [];
  const seen = new Set<string>();
  for (const e of [...(backward?.edges ?? []), ...(forward?.edges ?? [])]) {
    const from = placed.get(e.from!);
    const to = placed.get(e.to!);
    if (!from || !to || seen.has(e.id!)) continue;
    seen.add(e.id!);
    const qty = e.quantity ? ` ${formatQty({ value: e.quantity, unit: e.unit })}` : "";
    edges.push({ id: e.id!, from, to, label: `${LINK_LABELS[e.link_type ?? ""] ?? e.link_type}${qty}` });
  }

  return { nodes: [...placed.values()], edges, width: (keys.length ? keys[keys.length - 1] - minCol + 1 : 1) * (NODE_W + GAP_X) - GAP_X, height };
}
