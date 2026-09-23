import type { components } from "@/lib/api/schema";

type Kpi = components["schemas"]["Kpi"];

const numberFormat = new Intl.NumberFormat("en-UG");

export function formatKpiValue(kpi: Pick<Kpi, "value" | "format">): string {
  const v = kpi.value as unknown;
  if (v === null || v === undefined) return "—";

  switch (kpi.format) {
    case "money": {
      const m = v as { amount: string; currency: string };
      return new Intl.NumberFormat("en-UG", { style: "currency", currency: m.currency, maximumFractionDigits: 0 }).format(Number(m.amount));
    }
    case "quantity": {
      const q = v as { value: string; unit: string };
      return `${numberFormat.format(Number(q.value))} ${q.unit}`;
    }
    case "percent":
      return `${(Number(v) * 100).toFixed(1)}%`;
    case "status":
      return String(v) === "ok" ? "Healthy" : "Down";
    default:
      return typeof v === "number" ? numberFormat.format(v) : String(v);
  }
}

export function formatDelta(delta: Kpi["delta"]): string | null {
  if (!delta || typeof delta !== "object" || delta.value === null || delta.value === undefined) return null;
  const pct = Math.abs(delta.value * 100).toFixed(1);
  const sign = delta.direction === "down" ? "−" : delta.direction === "up" ? "+" : "";
  return `${sign}${pct}%`;
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Intl.DateTimeFormat("en-GB", { dateStyle: "medium", timeStyle: "short" }).format(new Date(iso));
}

export function formatRelative(iso: string, now: Date = new Date()): string {
  const seconds = Math.round((new Date(iso).getTime() - now.getTime()) / 1000);
  const rtf = new Intl.RelativeTimeFormat("en", { numeric: "auto" });
  const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ["day", 86400],
    ["hour", 3600],
    ["minute", 60],
  ];
  for (const [unit, size] of units) {
    if (Math.abs(seconds) >= size) return rtf.format(Math.round(seconds / size), unit);
  }
  return "just now";
}

export function humanize(key: string): string {
  const s = key.replaceAll("_", " ");
  return s.charAt(0).toUpperCase() + s.slice(1);
}
