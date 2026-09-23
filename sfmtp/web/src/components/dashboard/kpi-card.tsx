import { ArrowDownRight, ArrowUpRight } from "lucide-react";

import type { components } from "@/lib/api/schema";
import { formatDelta, formatKpiValue } from "@/lib/format";
import { cn } from "@/lib/utils";

type Kpi = components["schemas"]["Kpi"];

export function KpiCard({ kpi }: { kpi: Kpi }) {
  const delta = formatDelta(kpi.delta);
  const direction = kpi.delta && typeof kpi.delta === "object" ? kpi.delta.direction : undefined;
  const status = kpi.format === "status" ? String(kpi.value) : null;

  return (
    <div className="rounded-xl border border-border bg-surface p-4 shadow-sm">
      <p className="text-sm text-muted">{kpi.label}</p>
      <p className={cn("mt-2 text-2xl font-semibold tabular-nums tracking-tight", status === "down" && "text-danger", status === "ok" && "text-success")}>
        {formatKpiValue(kpi)}
      </p>
      {delta ? (
        <p className={cn("mt-1 inline-flex items-center gap-1 text-xs font-medium", direction === "down" ? "text-danger" : "text-success")}>
          {direction === "down" ? <ArrowDownRight className="size-3.5" aria-hidden /> : <ArrowUpRight className="size-3.5" aria-hidden />}
          {delta} <span className="font-normal text-muted">vs previous period</span>
        </p>
      ) : null}
    </div>
  );
}
