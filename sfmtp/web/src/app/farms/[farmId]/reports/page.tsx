"use client";

import { useQuery } from "@tanstack/react-query";
import dynamic from "next/dynamic";
import { useParams, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { TabBar } from "@/components/inventory/common";
import { useFarmCurrency } from "@/components/inventory/queries";
import { Input, Label } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { SOURCE_LABEL } from "@/lib/finance";
import { humanize } from "@/lib/format";
import { formatMoney, formatQty } from "@/lib/inventory";

// Recharts only where a chart is shown.
const Bars = dynamic(() => import("@/components/finance/charts").then((m) => m.Bars), { ssr: false, loading: () => <Skeleton className="h-60 w-full" /> });

const TABS = [
  { key: "pnl", label: "Profit & loss" },
  { key: "cash-flow", label: "Cash flow" },
  { key: "crops", label: "Cost per crop" },
  { key: "animals", label: "Animal groups" },
] as const;

export default function ReportsPage() {
  return (
    <Suspense>
      <Reports />
    </Suspense>
  );
}

function Reports() {
  const { farmId } = useParams<{ farmId: string }>();
  const search = useSearchParams();
  const tab = TABS.find((t) => t.key === search.get("tab"))?.key ?? "pnl";
  const currency = useFarmCurrency(farmId);
  const year = new Date().getFullYear();
  const [from, setFrom] = useState(`${year}-01-01`);
  const [to, setTo] = useState(new Date().toISOString().slice(0, 10));
  const range = { from: from || undefined, to: to || undefined };

  return (
    <>
      <PageHeader title="Reports" description="Read from the books: every figure traces back to its entries." />
      <TabBar base={`/farms/${farmId}/reports`} tabs={TABS} active={tab} label="Report" />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div>
          <Label htmlFor="from">From</Label>
          <Input id="from" type="date" className="h-9 w-40" value={from} onChange={(e) => setFrom(e.target.value)} />
        </div>
        <div>
          <Label htmlFor="to">To</Label>
          <Input id="to" type="date" className="h-9 w-40" value={to} onChange={(e) => setTo(e.target.value)} />
        </div>
      </div>
      {tab === "pnl" ? <ProfitAndLoss farmId={farmId} currency={currency} range={range} /> : null}
      {tab === "cash-flow" ? <CashFlow farmId={farmId} currency={currency} range={range} /> : null}
      {tab === "crops" ? <Crops farmId={farmId} currency={currency} range={range} /> : null}
      {tab === "animals" ? <Animals farmId={farmId} currency={currency} range={range} /> : null}
    </>
  );
}

type R = { farmId: string; currency: string; range: { from?: string; to?: string } };

function ProfitAndLoss({ farmId, currency, range }: R) {
  const pnl = useQuery({
    queryKey: ["report-pnl", farmId, range],
    queryFn: async () => (await api.GET("/farms/{farm}/reports/profit-and-loss", { params: { path: { farm: farmId }, query: range } })).data!.data!,
  });
  if (pnl.isLoading) return <Skeleton className="h-64 w-full" />;
  if (pnl.error) return <ErrorNotice error={pnl.error} />;
  const d = pnl.data!;
  const section = (title: string, rows: { code?: string; name?: string; amount?: number }[], total: number | undefined) => (
    <>
      <tr>
        <Th colSpan={2}>{title}</Th>
      </tr>
      {rows.map((r) => (
        <tr key={r.code}>
          <Td>
            <span className="tabular-nums text-muted">{r.code}</span> {r.name}
          </Td>
          <Td className="text-right tabular-nums">{formatMoney(r.amount, currency)}</Td>
        </tr>
      ))}
      <tr>
        <Td className="font-medium">Total {title.toLowerCase()}</Td>
        <Td className="text-right font-semibold tabular-nums">{formatMoney(total, currency)}</Td>
      </tr>
    </>
  );
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <Table>
        <tbody>
          {section("Income", d.income ?? [], d.totals?.income)}
          {section("Expenses", d.expenses ?? [], d.totals?.expenses)}
          <tr>
            <Td className="text-base font-semibold">Net profit</Td>
            <Td className={`text-right text-base font-semibold tabular-nums ${(d.totals?.net ?? 0) < 0 ? "text-danger" : "text-success"}`}>{formatMoney(d.totals?.net, currency)}</Td>
          </tr>
        </tbody>
      </Table>
      <section className="rounded-xl border border-border bg-surface p-4">
        <h2 className="mb-2 font-medium">Income and expenses, last 12 months</h2>
        <Bars
          labels={d.monthly?.labels ?? []}
          series={[
            { key: "income", label: "Income", values: d.monthly?.income ?? [] },
            { key: "expenses", label: "Expenses", values: d.monthly?.expenses ?? [] },
          ]}
        />
      </section>
    </div>
  );
}

function CashFlow({ farmId, currency, range }: R) {
  const flow = useQuery({
    queryKey: ["report-cash", farmId, range],
    queryFn: async () => (await api.GET("/farms/{farm}/reports/cash-flow", { params: { path: { farm: farmId }, query: range } })).data!.data!,
  });
  if (flow.isLoading) return <Skeleton className="h-64 w-full" />;
  if (flow.error) return <ErrorNotice error={flow.error} />;
  const d = flow.data!;
  const label = (s?: string) => SOURCE_LABEL[s ?? ""] ?? humanize(s ?? "");
  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <Table>
        <tbody>
          <tr>
            <Td className="font-medium">Opening cash</Td>
            <Td className="text-right tabular-nums">{formatMoney(d.opening, currency)}</Td>
          </tr>
          {(d.inflows ?? []).map((r) => (
            <tr key={`in-${r.source}`}>
              <Td className="pl-8">+ {label(r.source)}</Td>
              <Td className="text-right tabular-nums text-success">{formatMoney(r.amount, currency)}</Td>
            </tr>
          ))}
          {(d.outflows ?? []).map((r) => (
            <tr key={`out-${r.source}`}>
              <Td className="pl-8">− {label(r.source)}</Td>
              <Td className="text-right tabular-nums">{formatMoney(r.amount, currency)}</Td>
            </tr>
          ))}
          <tr>
            <Td className="font-semibold">Closing cash</Td>
            <Td className="text-right font-semibold tabular-nums">{formatMoney(d.closing, currency)}</Td>
          </tr>
          {(d.accounts ?? []).map((a) => (
            <tr key={a.id}>
              <Td className="pl-8 text-muted">
                {a.code} {a.name}
              </Td>
              <Td className="text-right tabular-nums text-muted">{formatMoney(a.balance, currency)}</Td>
            </tr>
          ))}
        </tbody>
      </Table>
      <section className="rounded-xl border border-border bg-surface p-4">
        <h2 className="font-medium">Next 13 weeks</h2>
        <p className="mb-2 text-xs text-muted">From open invoices, approved expenses and payroll by due date; overdue items in the first week.</p>
        <Bars
          labels={(d.forecast?.weeks ?? []).map((w) => (w.week_start ?? "").slice(5))}
          series={[{ key: "balance", label: "Expected cash", values: (d.forecast?.weeks ?? []).map((w) => w.balance ?? 0) }]}
        />
      </section>
    </div>
  );
}

function Crops({ farmId, currency, range }: R) {
  const rows = useQuery({
    queryKey: ["report-crops", farmId, range],
    queryFn: async () => (await api.GET("/farms/{farm}/reports/cost-per-crop", { params: { path: { farm: farmId }, query: range } })).data!.data!,
  });
  if (rows.isLoading) return <Skeleton className="h-64 w-full" />;
  if (rows.error) return <ErrorNotice error={rows.error} />;
  if (rows.data!.length === 0) return <EmptyState title="No crop cycles yet">Costs charged to a crop cycle (inputs issued, wages, expenses) show here.</EmptyState>;
  return (
    <Table>
      <thead>
        <tr>
          <Th>Cycle</Th>
          <Th className="text-right">Inputs</Th>
          <Th className="text-right">Labour</Th>
          <Th className="text-right">Other</Th>
          <Th className="text-right">Cost</Th>
          <Th className="text-right">Revenue</Th>
          <Th className="text-right">Margin</Th>
          <Th className="text-right">Per acre</Th>
          <Th className="text-right">Per kg</Th>
        </tr>
      </thead>
      <tbody>
        {rows.data!.map((c) => (
          <tr key={c.id}>
            <Td>
              <span className="font-medium">{c.code}</span> {c.crop}
              <span className="block text-xs text-muted">
                {c.plot} · {formatQty(c.area_ha, "ha")} · {humanize(c.stage ?? "")}
                {c.harvested_kg ? ` · ${formatQty(c.harvested_kg, "kg")} harvested` : ""}
              </span>
            </Td>
            <Td className="text-right tabular-nums">{formatMoney(c.inputs)}</Td>
            <Td className="text-right tabular-nums">{formatMoney(c.labour)}</Td>
            <Td className="text-right tabular-nums">{formatMoney(c.other)}</Td>
            <Td className="text-right font-medium tabular-nums">{formatMoney(c.cost, currency)}</Td>
            <Td className="text-right tabular-nums">{formatMoney(c.revenue, currency)}</Td>
            <Td className={`text-right tabular-nums ${(c.margin ?? 0) < 0 ? "text-danger" : ""}`}>{formatMoney(c.margin, currency)}</Td>
            <Td className="text-right tabular-nums">{formatMoney(c.cost_per_acre)}</Td>
            <Td className="text-right tabular-nums">{formatMoney(c.cost_per_kg)}</Td>
          </tr>
        ))}
      </tbody>
    </Table>
  );
}

function Animals({ farmId, currency, range }: R) {
  const rows = useQuery({
    queryKey: ["report-animals", farmId, range],
    queryFn: async () => (await api.GET("/farms/{farm}/reports/cost-per-animal-group", { params: { path: { farm: farmId }, query: range } })).data!.data!,
  });
  if (rows.isLoading) return <Skeleton className="h-64 w-full" />;
  if (rows.error) return <ErrorNotice error={rows.error} />;
  if (rows.data!.length === 0) return <EmptyState title="No animal groups yet" />;
  return (
    <Table>
      <thead>
        <tr>
          <Th>Group</Th>
          <Th className="text-right">Feed & drugs</Th>
          <Th className="text-right">Labour</Th>
          <Th className="text-right">Other</Th>
          <Th className="text-right">Cost</Th>
          <Th className="text-right">Revenue</Th>
          <Th className="text-right">Margin</Th>
        </tr>
      </thead>
      <tbody>
        {rows.data!.map((g) => (
          <tr key={g.id ?? "none"}>
            <Td className="font-medium">{g.label}</Td>
            <Td className="text-right tabular-nums">{formatMoney(g.inputs)}</Td>
            <Td className="text-right tabular-nums">{formatMoney(g.labour)}</Td>
            <Td className="text-right tabular-nums">{formatMoney(g.other)}</Td>
            <Td className="text-right font-medium tabular-nums">{formatMoney(g.cost, currency)}</Td>
            <Td className="text-right tabular-nums">{formatMoney(g.revenue, currency)}</Td>
            <Td className={`text-right tabular-nums ${(g.margin ?? 0) < 0 ? "text-danger" : ""}`}>{formatMoney(g.margin, currency)}</Td>
          </tr>
        ))}
      </tbody>
    </Table>
  );
}
