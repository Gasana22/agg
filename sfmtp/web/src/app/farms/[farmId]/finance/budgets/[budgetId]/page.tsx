"use client";

import { useQuery } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";

import { useFarmCurrency } from "@/components/inventory/queries";
import { ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatMoney } from "@/lib/inventory";

export default function BudgetPage() {
  const { farmId, budgetId } = useParams<{ farmId: string; budgetId: string }>();
  const currency = useFarmCurrency(farmId);
  const budget = useQuery({
    queryKey: ["budgets", farmId, budgetId],
    queryFn: async () => (await api.GET("/farms/{farm}/budgets/{budget}", { params: { path: { farm: farmId, budget: budgetId } } })).data!.data!,
  });
  if (budget.isLoading) return <Skeleton className="h-64 w-full" />;
  if (budget.error) return <ErrorNotice error={budget.error} />;
  const b = budget.data!;

  return (
    <>
      <Link href={`/farms/${farmId}/finance?tab=budgets`} className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" /> Budgets
      </Link>
      <PageHeader title={b.name ?? ""} description={`${b.code} · ${b.period_start} – ${b.period_end} · ${b.scope?.label ?? "Whole farm"}`} />
      {(["expense", "income"] as const).map((type) => {
        const lines = (b.lines ?? []).filter((l) => l.type === type);
        if (lines.length === 0) return null;
        return (
          <section key={type} className="mb-6">
            <h2 className="mb-2 text-lg font-semibold">{type === "expense" ? "Costs" : "Income"}</h2>
            <Table>
              <thead>
                <tr>
                  <Th>Account</Th>
                  <Th className="text-right">Budget</Th>
                  <Th className="text-right">{type === "expense" ? "Spent" : "Received"}</Th>
                  <Th className="w-1/4">Used</Th>
                  <Th className="text-right">{type === "expense" ? "Left" : "To come"}</Th>
                </tr>
              </thead>
              <tbody>
                {lines.map((l) => {
                  const pct = l.used_pct ?? 0;
                  const over = type === "expense" && pct > 100;
                  return (
                    <tr key={l.line_id}>
                      <Td>
                        <span className="tabular-nums text-muted">{l.code}</span> {l.name}
                        {l.note ? <span className="block text-xs text-muted">{l.note}</span> : null}
                      </Td>
                      <Td className="text-right tabular-nums">{formatMoney(l.budget, currency)}</Td>
                      <Td className="text-right tabular-nums">{formatMoney(l.actual, currency)}</Td>
                      <Td>
                        <div className="h-2 overflow-hidden rounded-full bg-surface-muted" role="meter" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label={`${l.name} used`}>
                          <div className={`h-full ${over ? "bg-danger" : "bg-primary"}`} style={{ width: `${Math.min(100, pct)}%` }} />
                        </div>
                        <span className={`text-xs ${over ? "text-danger" : "text-muted"}`}>{l.used_pct != null ? `${l.used_pct}%` : "—"}</span>
                      </Td>
                      <Td className={`text-right tabular-nums ${over ? "text-danger" : ""}`}>{formatMoney(l.variance, currency)}</Td>
                    </tr>
                  );
                })}
              </tbody>
            </Table>
          </section>
        );
      })}
    </>
  );
}
