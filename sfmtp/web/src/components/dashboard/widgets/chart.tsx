"use client";

import { useQuery } from "@tanstack/react-query";
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";

import { ErrorNotice, Skeleton } from "@/components/ui/misc";
import { fetchHref } from "@/lib/api/client";
import type { components } from "@/lib/api/schema";

type Chart = components["schemas"]["ChartWidget"];

/** Heavy widgets load separately from the dashboard summary (docs/05 §1). */
export function ChartWidget({ href }: { href: string }) {
  const { data, error, isLoading } = useQuery({
    queryKey: ["widget", href],
    queryFn: async () => (await fetchHref<{ data: Chart }>(href)).data,
  });

  if (isLoading) return <Skeleton className="h-56 w-full" />;
  if (error || !data) return <ErrorNotice error={error} />;

  const series = data.series?.[0];
  const rows = (data.x?.values ?? []).map((x, i) => ({ x, value: Number(series?.values?.[i] ?? 0) }));

  return (
    <div className="h-56" role="img" aria-label={`${series?.label ?? "Series"} per day`}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={rows} margin={{ top: 4, right: 4, left: -24, bottom: 0 }}>
          <CartesianGrid vertical={false} stroke="var(--border)" />
          <XAxis dataKey="x" tickLine={false} axisLine={false} tick={{ fill: "var(--muted)", fontSize: 11 }} tickFormatter={(v: string) => v.slice(5)} minTickGap={16} />
          <YAxis allowDecimals={false} tickLine={false} axisLine={false} tick={{ fill: "var(--muted)", fontSize: 11 }} />
          <Tooltip
            cursor={{ fill: "var(--surface-muted)" }}
            contentStyle={{ background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 8, fontSize: 12 }}
            labelStyle={{ color: "var(--foreground)" }}
          />
          <Bar dataKey="value" name={series?.label ?? "Value"} fill="var(--primary)" radius={[4, 4, 0, 0]} maxBarSize={28} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}
