"use client";

import { useQuery } from "@tanstack/react-query";
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";

import { ErrorNotice, Skeleton } from "@/components/ui/misc";
import { fetchHref } from "@/lib/api/client";
import type { components } from "@/lib/api/schema";

type Chart = components["schemas"]["ChartWidget"];

/** First series in the brand colour, the second in a muted neutral (expected vs actual). */
const SERIES_COLORS = ["var(--muted)", "var(--primary)", "var(--accent)"];

/** Heavy widgets load separately from the dashboard summary (docs/05 §1). */
export function ChartWidget({ href }: { href: string }) {
  const { data, error, isLoading } = useQuery({
    queryKey: ["widget", href],
    queryFn: async () => (await fetchHref<{ data: Chart }>(href)).data,
  });

  if (isLoading) return <Skeleton className="h-56 w-full" />;
  if (error || !data) return <ErrorNotice error={error} />;

  const series = data.series ?? [];
  const isDate = data.x?.type === "date";
  const rows = (data.x?.values ?? []).map((x, i) => Object.fromEntries([["x", x], ...series.map((s) => [s.key ?? "value", Number(s.values?.[i] ?? 0)])]));
  if (rows.length === 0) return <p className="py-10 text-center text-sm text-muted">No data for this period.</p>;

  return (
    <div className="h-56" role="img" aria-label={series.map((s) => s.label).join(" and ") + (isDate ? " per day" : "")}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={rows} margin={{ top: 4, right: 4, left: -24, bottom: 0 }}>
          <CartesianGrid vertical={false} stroke="var(--border)" />
          <XAxis dataKey="x" tickLine={false} axisLine={false} tick={{ fill: "var(--muted)", fontSize: 11 }} tickFormatter={isDate ? (v: string) => v.slice(5) : undefined} minTickGap={isDate ? 16 : 4} interval={isDate ? undefined : 0} />
          <YAxis allowDecimals={false} tickLine={false} axisLine={false} tick={{ fill: "var(--muted)", fontSize: 11 }} />
          <Tooltip
            cursor={{ fill: "var(--surface-muted)" }}
            contentStyle={{ background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 8, fontSize: 12 }}
            labelStyle={{ color: "var(--foreground)" }}
          />
          {series.map((s, i) => (
            <Bar key={s.key} dataKey={s.key ?? "value"} name={s.label ?? "Value"} fill={series.length === 1 ? "var(--primary)" : SERIES_COLORS[i % SERIES_COLORS.length]} radius={[4, 4, 0, 0]} maxBarSize={28} />
          ))}
          {series.length > 1 ? <Legend wrapperStyle={{ fontSize: 12 }} /> : null}
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}
