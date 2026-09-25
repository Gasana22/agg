import type { components } from "@/lib/api/schema";
import { formatMoney, formatQty } from "@/lib/inventory";

type Tone = "primary" | "warning" | "neutral" | "danger" | "success" | "info";
export type ReportColumn =
  components["schemas"]["StandardReport"]["columns"] extends
    | (infer C)[]
    | undefined
    ? C
    : never;
export type ReportExport = components["schemas"]["ReportExport"];
export type StandardReportDefinition =
  components["schemas"]["StandardReportDefinition"];

export const REPORT_GROUPS: { key: string; label: string }[] = [
  { key: "inventory", label: "Inventory" },
  { key: "purchasing", label: "Purchasing" },
  { key: "sales", label: "Sales" },
  { key: "crops", label: "Crops" },
  { key: "livestock", label: "Livestock" },
  { key: "workforce", label: "Workforce" },
  { key: "finance", label: "Finance" },
  { key: "traceability", label: "Traceability" },
];

export const EXPORT_FORMATS = [
  { key: "csv", label: "CSV" },
  { key: "xlsx", label: "Excel" },
  { key: "pdf", label: "PDF" },
] as const;

export const LABEL_TEMPLATES = [
  { key: "a4_3x8", label: "A4, 24 labels (3 × 8)" },
  { key: "a4_2x7", label: "A4, 14 large labels (2 × 7)" },
  { key: "a4_4x10", label: "A4, 40 small labels (4 × 10)" },
] as const;

export const EXPORT_STATUS: Record<string, { label: string; tone: Tone }> = {
  queued: { label: "Queued", tone: "neutral" },
  running: { label: "Building", tone: "info" },
  ready: { label: "Ready", tone: "success" },
  failed: { label: "Failed", tone: "danger" },
  expired: { label: "Expired", tone: "neutral" },
};

export const BAND: Record<
  string,
  { label: string; tone: Tone; color: string }
> = {
  good: { label: "Good", tone: "success", color: "#16a34a" },
  watch: { label: "Watch", tone: "warning", color: "#d97706" },
  poor: { label: "Poor", tone: "danger", color: "#dc2626" },
};

/** Reports grouped in a fixed order, groups without reports left out. */
export function groupReports<T extends { group?: string }>(
  reports: T[],
): { key: string; label: string; reports: T[] }[] {
  return REPORT_GROUPS.map((g) => ({
    ...g,
    reports: reports.filter((r) => r.group === g.key),
  })).filter((g) => g.reports.length > 0);
}

/** A report cell for the screen: money with the currency, percent from a fraction, numbers grouped. */
export function formatReportCell(
  value: unknown,
  type: string | undefined,
  currency?: string | null,
): string {
  if (value === null || value === undefined || value === "") return "—";
  switch (type) {
    case "money":
      return formatMoney(Number(value), currency);
    case "percent":
      return `${(Number(value) * 100).toFixed(1)}%`;
    case "number":
    case "integer":
      return formatQty(Number(value));
    default:
      return String(value);
  }
}

export function isNumericColumn(type: string | undefined): boolean {
  return (
    type === "money" ||
    type === "number" ||
    type === "integer" ||
    type === "percent"
  );
}

/** True while any export is still being built (the list keeps polling). */
export function hasPendingExports(
  exports: Pick<ReportExport, "status">[],
): boolean {
  return exports.some((e) => e.status === "queued" || e.status === "running");
}

export function formatBytes(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined) return "—";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/**
 * Heat map shading: opacity grows with the square root of the count, so a
 * few busy cells do not wash out the rest; the colour runs from amber to red.
 */
export function heatStyle(
  count: number,
  max: number,
): { color: string; fillOpacity: number } {
  const t = max > 0 ? Math.sqrt(Math.max(0, count) / max) : 0;
  const color = t > 0.66 ? "#dc2626" : t > 0.33 ? "#f97316" : "#f59e0b";
  return { color, fillOpacity: Math.round((0.15 + 0.6 * t) * 100) / 100 };
}

export const HEAT_LAYERS: { key: string; label: string }[] = [
  { key: "gps", label: "GPS trails" },
  { key: "tasks", label: "Task check-ins" },
  { key: "attendance", label: "Attendance" },
  { key: "operations", label: "Field work" },
  { key: "observations", label: "Pest reports" },
  { key: "trace", label: "Traceability" },
];
