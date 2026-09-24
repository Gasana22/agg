"use client";

import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";

const compact = new Intl.NumberFormat("en", { notation: "compact", maximumFractionDigits: 1 });
const COLORS = ["var(--primary)", "var(--muted)", "var(--accent)"];

/** A small bar chart over labelled series (months, weeks). */
export function Bars({ labels, series, height = 240 }: { labels: string[]; series: { key: string; label: string; values: number[] }[]; height?: number }) {
  const rows = labels.map((x, i) => Object.fromEntries([["x", x], ...series.map((s) => [s.key, s.values[i] ?? 0])]));
  return (
    <div style={{ height }} role="img" aria-label={series.map((s) => s.label).join(" and ")}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={rows} margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
          <CartesianGrid vertical={false} stroke="var(--border)" />
          <XAxis dataKey="x" tickLine={false} axisLine={false} tick={{ fill: "var(--muted)", fontSize: 11 }} />
          <YAxis tickLine={false} axisLine={false} tick={{ fill: "var(--muted)", fontSize: 11 }} tickFormatter={(v: number) => compact.format(v)} width={48} />
          <Tooltip cursor={{ fill: "var(--surface-muted)" }} contentStyle={{ background: "var(--surface)", border: "1px solid var(--border)", borderRadius: 8, fontSize: 12 }} formatter={(v) => Number(v ?? 0).toLocaleString("en-UG")} />
          {series.length > 1 ? <Legend wrapperStyle={{ fontSize: 12 }} /> : null}
          {series.map((s, i) => (
            <Bar key={s.key} dataKey={s.key} name={s.label} fill={COLORS[i % COLORS.length]} radius={[3, 3, 0, 0]} />
          ))}
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}
