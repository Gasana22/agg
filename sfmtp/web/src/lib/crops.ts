import type { components } from "@/lib/api/schema";

export type FarmCrop = components["schemas"]["FarmCrop"];
export type Season = components["schemas"]["Season"];
export type CropPlan = components["schemas"]["CropPlan"];
export type CropCycle = components["schemas"]["CropCycle"];
export type CropOperation = components["schemas"]["CropOperation"];
export type CropObservation = components["schemas"]["CropObservation"];
export type CropHarvest = components["schemas"]["CropHarvest"];

export const OPERATION_TYPES = [
  "land_preparation", "planting", "weeding", "fertilizing", "spraying", "irrigation", "scouting", "pruning", "thinning", "other",
] as const;
export const OBSERVATION_KINDS = ["pest", "disease", "weed", "nutrient", "water", "growth", "weather", "other"] as const;
export const SEVERITIES = ["low", "medium", "high", "critical"] as const;
export const CLOSE_REASONS = ["harvested", "failed", "abandoned", "other"] as const;

/** Stage order for progress displays. */
export const STAGES = ["nursery", "planted", "growing", "harvesting", "closed"] as const;

export const STAGE_TONE: Record<string, "primary" | "warning" | "neutral" | "danger"> = {
  nursery: "neutral",
  planted: "primary",
  growing: "primary",
  harvesting: "warning",
  closed: "neutral",
};

export const SEVERITY_TONE: Record<string, "primary" | "warning" | "neutral" | "danger"> = {
  low: "neutral",
  medium: "warning",
  high: "danger",
  critical: "danger",
};

/** Days from today (local) to an ISO date; negative when past. */
export function daysUntil(isoDate: string | null | undefined, today: Date = new Date()): number | null {
  if (!isoDate) return null;
  const [y, m, d] = isoDate.split("-").map(Number);
  const target = Date.UTC(y, m - 1, d);
  const base = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
  return Math.round((target - base) / 86_400_000);
}

/** "in 5 days", "today", "3 days ago". */
export function relativeDays(isoDate: string | null | undefined, today: Date = new Date()): string {
  const n = daysUntil(isoDate, today);
  if (n === null) return "—";
  if (n === 0) return "today";
  if (n === 1) return "tomorrow";
  if (n === -1) return "yesterday";
  return n > 0 ? `in ${n} days` : `${-n} days ago`;
}

/** Harvest is safe on or after the date; returns whether a withholding period is still running. */
export function underWithholding(cycle: Pick<CropCycle, "safe_harvest_on">, today: Date = new Date()): boolean {
  const n = daysUntil(cycle.safe_harvest_on, today);
  return n !== null && n > 0;
}

export function formatQuantity(value: number | null | undefined, unit?: string | null): string {
  if (value === null || value === undefined) return "—";
  return `${value.toLocaleString(undefined, { maximumFractionDigits: 2 })}${unit ? ` ${unit}` : ""}`;
}

/** Share of the expected yield harvested so far, 0–100+ (null when unknown). */
export function yieldProgress(cycle: Pick<CropCycle, "expected_yield" | "actual_yield">): number | null {
  if (!cycle.expected_yield || cycle.actual_yield === undefined) return null;
  return Math.round((cycle.actual_yield / cycle.expected_yield) * 100);
}
