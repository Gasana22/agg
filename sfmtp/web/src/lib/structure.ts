import type { components } from "@/lib/api/schema";

export type Block = components["schemas"]["StructureBlock"];
export type Section = components["schemas"]["StructureSection"];
export type Plot = components["schemas"]["StructurePlot"];
export type Location = components["schemas"]["StructureLocation"];
export type FarmStructure = components["schemas"]["FarmStructure"];
export type Polygon = components["schemas"]["GeoJsonPolygon"];
export type Warning = NonNullable<components["schemas"]["StructureWarnings"]["warnings"]>[number];

export type NodeType = "block" | "section" | "plot" | "location";
export type AnyNode = Block | Section | Plot | Location;

export const NODE_LABEL: Record<NodeType, string> = { block: "Block", section: "Section", plot: "Plot", location: "Location" };

export const LAND_USES = ["crop", "pasture", "fallow", "orchard", "forestry", "other"] as const;
export const IRRIGATION = ["rainfed", "drip", "sprinkler", "furrow", "flood", "other"] as const;
export const LOCATION_KINDS = ["store", "building", "paddock", "housing", "water", "gate", "office", "other"] as const;
export const SOIL_TEXTURES = [
  "sand", "loamy_sand", "sandy_loam", "loam", "silt_loam", "silt", "sandy_clay_loam",
  "clay_loam", "silty_clay_loam", "sandy_clay", "silty_clay", "clay",
] as const;

/** Map colours per land use; fills are drawn at low opacity over the tiles. */
export const LAND_USE_COLOR: Record<string, string> = {
  crop: "#16a34a",
  pasture: "#65a30d",
  fallow: "#a16207",
  orchard: "#059669",
  forestry: "#166534",
  other: "#64748b",
};

export type TreeSection = Section & { plots: Plot[] };
export type TreeBlock = Block & { sections: TreeSection[] };
export type Tree = { blocks: TreeBlock[]; loosePlots: Plot[] };

/** Nest the flat structure lists: blocks → sections → plots. */
export function buildTree(data: Pick<FarmStructure, "blocks" | "sections" | "plots">): Tree {
  const plotsBySection = new Map<string, Plot[]>();
  const loosePlots: Plot[] = [];
  const sectionIds = new Set((data.sections ?? []).map((s) => s.id));

  for (const plot of data.plots ?? []) {
    if (plot.section_id && sectionIds.has(plot.section_id)) {
      const list = plotsBySection.get(plot.section_id) ?? [];
      list.push(plot);
      plotsBySection.set(plot.section_id, list);
    } else {
      loosePlots.push(plot);
    }
  }

  const sectionsByBlock = new Map<string, TreeSection[]>();
  for (const section of data.sections ?? []) {
    const list = sectionsByBlock.get(section.block_id ?? "") ?? [];
    list.push({ ...section, plots: plotsBySection.get(section.id ?? "") ?? [] });
    sectionsByBlock.set(section.block_id ?? "", list);
  }

  return {
    blocks: (data.blocks ?? []).map((b) => ({ ...b, sections: sectionsByBlock.get(b.id ?? "") ?? [] })),
    loosePlots,
  };
}

export function formatHa(value: number | null | undefined): string {
  if (value === null || value === undefined) return "—";
  return `${value.toLocaleString(undefined, { maximumFractionDigits: value < 10 ? 2 : 1 })} ha`;
}

/** A closed GeoJSON polygon from clicked [lat, lng] points, or null when too few. */
export function polygonFromPoints(points: [number, number][]): Polygon | null {
  if (points.length < 3) return null;
  const ring = points.map(([lat, lng]) => [round7(lng), round7(lat)]);
  ring.push([...ring[0]]);
  return { type: "Polygon", coordinates: [ring] };
}

/** Parse pasted GeoJSON: a Polygon, or a Feature / FeatureCollection holding one. */
export function parsePolygon(text: string): Polygon | null {
  try {
    let value = JSON.parse(text);
    if (value?.type === "FeatureCollection") value = value.features?.[0];
    if (value?.type === "Feature") value = value.geometry;
    return value?.type === "Polygon" && Array.isArray(value.coordinates) ? (value as Polygon) : null;
  } catch {
    return null;
  }
}

function round7(n: number): number {
  return Math.round(n * 1e7) / 1e7;
}
