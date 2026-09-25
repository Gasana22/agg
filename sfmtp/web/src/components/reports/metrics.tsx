"use client";

import { useQuery } from "@tanstack/react-query";
import { useState } from "react";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, Skeleton } from "@/components/ui/misc";
import { api, type components } from "@/lib/api/client";
import { formatDelta, formatKpiValue, humanize } from "@/lib/format";
import { cn } from "@/lib/utils";

type Kpi = components["schemas"]["Kpi"];

const PERIODS = [
  { key: "7d", label: "Last 7 days" },
  { key: "30d", label: "Last 30 days" },
  { key: "90d", label: "Last 90 days" },
  { key: "ytd", label: "Year to date" },
] as const;

/** The metric catalogue: what each dashboard number means, with its trend. */
export function MetricCatalogue({ farmId }: { farmId: string }) {
  const catalogue = useQuery({
    queryKey: ["metrics", farmId],
    queryFn: async () =>
      (
        await api.GET("/farms/{farm}/metrics", {
          params: { path: { farm: farmId } },
        })
      ).data!.data ?? [],
  });
  const [selected, setSelected] = useState<string | null>(null);
  if (catalogue.isLoading) return <Skeleton className="h-64 w-full" />;
  if (catalogue.error) return <ErrorNotice error={catalogue.error} />;
  const metrics = catalogue.data ?? [];
  const modules = [...new Set(metrics.map((m) => m.module!))];

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
      <div className="space-y-4">
        {modules.map((mod) => (
          <Card key={mod}>
            <CardHeader>
              <CardTitle>{humanize(mod)}</CardTitle>
            </CardHeader>
            <CardContent>
              <dl className="divide-y divide-border">
                {metrics
                  .filter((m) => m.module === mod)
                  .map((m) => (
                    <button
                      key={m.key}
                      type="button"
                      onClick={() => setSelected(m.key!)}
                      aria-current={selected === m.key ? "true" : undefined}
                      className={cn(
                        "block w-full py-2 text-left hover:bg-surface-muted/60",
                        selected === m.key && "bg-surface-muted/60",
                      )}
                    >
                      <dt className="text-sm font-medium">{m.label}</dt>
                      <dd className="text-xs text-muted">{m.description}</dd>
                    </button>
                  ))}
              </dl>
            </CardContent>
          </Card>
        ))}
      </div>
      <div className="lg:sticky lg:top-4 lg:self-start">
        {selected ? (
          <MetricDetail farmId={farmId} metric={selected} />
        ) : (
          <EmptyState title="Choose a metric to see its trend." />
        )}
      </div>
    </div>
  );
}

function MetricDetail({ farmId, metric }: { farmId: string; metric: string }) {
  const [period, setPeriod] = useState<(typeof PERIODS)[number]["key"]>("30d");
  const detail = useQuery({
    queryKey: ["metric", farmId, metric, period],
    queryFn: async () =>
      (
        await api.GET("/farms/{farm}/metrics/{metric}", {
          params: { path: { farm: farmId, metric }, query: { period } },
        })
      ).data!.data!,
  });
  if (detail.isLoading) return <Skeleton className="h-64 w-full" />;
  if (detail.error) return <ErrorNotice error={detail.error} />;
  const d = detail.data!;
  const kpi = { value: d.value, format: d.format } as Pick<
    Kpi,
    "value" | "format"
  >;
  const delta = formatDelta(d.delta as Kpi["delta"]);
  const series = d.series ?? [];
  const peak = Math.max(1e-9, ...series.map((p) => Math.abs(p.value ?? 0)));

  return (
    <Card>
      <CardHeader className="flex-wrap gap-2">
        <CardTitle>{d.label}</CardTitle>
        {d.period_based ? (
          <Select
            aria-label="Period"
            className="h-8 w-40"
            value={period}
            onChange={(e) => setPeriod(e.target.value as typeof period)}
          >
            {PERIODS.map((p) => (
              <option key={p.key} value={p.key}>
                {p.label}
              </option>
            ))}
          </Select>
        ) : null}
      </CardHeader>
      <CardContent className="space-y-3">
        <p className="text-3xl font-semibold tabular-nums">
          {formatKpiValue(kpi)}
        </p>
        {delta ? (
          <p className="text-sm text-muted">
            {delta} vs the previous period (
            {formatKpiValue({ value: d.previous, format: d.format } as Pick<
              Kpi,
              "value" | "format"
            >)}
            )
          </p>
        ) : null}
        <p className="text-sm">{d.description}</p>
        {series.length > 0 ? (
          <div>
            <div
              className="flex h-32 items-end gap-0.5"
              role="img"
              aria-label={`${d.label} per ${series.length > 13 ? "day" : "period"}`}
            >
              {series.map((p) => (
                <div
                  key={p.label}
                  title={`${p.label}: ${p.value ?? "—"}`}
                  className="flex-1 rounded-t bg-primary/70"
                  style={{
                    height: `${Math.max(2, (Math.abs(p.value ?? 0) / peak) * 100)}%`,
                  }}
                />
              ))}
            </div>
            <p className="mt-1 flex justify-between text-xs text-muted">
              <span>{series[0].label}</span>
              <span>{series[series.length - 1].label}</span>
            </p>
          </div>
        ) : (
          <p className="text-xs text-muted">
            A current figure: it has no history per period.
          </p>
        )}
        <p className="text-xs text-muted">
          Shown on:{" "}
          {(d.dashboards ?? []).map(humanize).join(", ") ||
            "no dashboard (catalogue only)"}
          .
        </p>
      </CardContent>
    </Card>
  );
}
