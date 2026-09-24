"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { FormDialog, text } from "@/components/forms/form-dialog";
import { PaymentDialog } from "@/components/finance/dialogs";
import { useActions } from "@/components/inventory/common";
import { ReasonDialog } from "@/components/inventory/dialogs";
import { useFarmCurrency } from "@/components/inventory/queries";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input, Label } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { hours, PAYROLL_STATUS, type PayrollRun } from "@/lib/finance";
import { formatDateTime } from "@/lib/format";
import { formatMoney } from "@/lib/inventory";
import { can } from "@/lib/permissions";

type Line = NonNullable<PayrollRun["lines"]>[number];

export default function PayrollRunPage() {
  const { farmId, runId } = useParams<{ farmId: string; runId: string }>();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const writable = workspace?.type === "farm";
  const canPrepare = writable && can(perms, "finance.payroll.manage");
  const canApprove = writable && can(perms, "finance.payroll.approve");
  const canPay = writable && can(perms, "finance.manage|finance.payroll.manage");
  const money = can(perms, "finance.values.view");
  const currency = useFarmCurrency(farmId);
  const [dialog, setDialog] = useState<string | null>(null);
  const [line, setLine] = useState<Line | null>(null);

  const run = useQuery({
    queryKey: ["payroll-runs", farmId, runId],
    queryFn: async () => (await api.GET("/farms/{farm}/payroll-runs/{payrollRun}", { params: { path: { farm: farmId, payrollRun: runId } } })).data!.data!,
  });
  const refresh = () => Promise.all(["payroll-runs", "payments", "ledger-accounts", "open-documents"].map((k) => queryClient.invalidateQueries({ queryKey: [k, farmId] })));
  const { error, busy, run: act } = useActions(refresh);

  if (run.isLoading) return <Skeleton className="h-64 w-full" />;
  if (run.error) return <ErrorNotice error={run.error} />;
  const r = run.data!;
  const st = PAYROLL_STATUS[r.status ?? ""];
  const path = { path: { farm: farmId, payrollRun: r.id! } };
  const lines = r.lines ?? [];

  return (
    <>
      <Link href={`/farms/${farmId}/finance?tab=payroll`} className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" /> Payroll
      </Link>
      <PageHeader
        title={`${r.code} · ${r.period_start} – ${r.period_end}`}
        description={r.notes ?? "Days from attendance at each worker's daily rate."}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={st?.tone ?? "neutral"}>{st?.label ?? r.status}</Badge>
            {canPrepare && r.status === "draft" ? (
              <Button variant="secondary" disabled={busy === "recalc"} onClick={() => act("recalc", () => api.POST("/farms/{farm}/payroll-runs/{payrollRun}/recalculate", { params: path }))}>
                Recalculate
              </Button>
            ) : null}
            {canApprove && r.status === "draft" ? (
              <Button disabled={busy === "approve"} onClick={() => act("approve", () => api.POST("/farms/{farm}/payroll-runs/{payrollRun}/approve", { params: path }))}>
                Approve
              </Button>
            ) : null}
            {canPay && money && r.status === "approved" ? <Button onClick={() => setDialog("pay")}>Pay</Button> : null}
            {canPrepare && (r.status === "draft" || (r.status === "approved" && (r.paid_amount ?? 0) === 0)) ? (
              <Button variant="ghost" onClick={() => setDialog("cancel")}>
                Cancel
              </Button>
            ) : null}
          </div>
        }
      />
      {error ? <div className="mb-4"><ErrorNotice error={error} /></div> : null}
      {lines.length === 0 ? (
        <EmptyState title="Nobody to pay">Workers need a daily rate and attendance in the period.</EmptyState>
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Worker</Th>
              <Th className="text-right">Days</Th>
              <Th className="text-right">Hours</Th>
              <Th className="text-right">Tasks</Th>
              {money ? (
                <>
                  <Th className="text-right">Rate</Th>
                  <Th className="text-right">Bonus</Th>
                  <Th className="text-right">Deductions</Th>
                  <Th className="text-right">Net</Th>
                  <Th>Charged to</Th>
                </>
              ) : null}
              <Th />
            </tr>
          </thead>
          <tbody>
            {lines.map((l) => (
              <tr key={l.id}>
                <Td>
                  <span className="font-medium">{l.worker?.full_name}</span>
                  <span className="block text-xs text-muted">
                    {l.worker?.worker_code}
                    {l.note ? ` · ${l.note}` : ""}
                  </span>
                </Td>
                <Td className="text-right tabular-nums">{l.days_worked}</Td>
                <Td className="text-right tabular-nums text-muted">{hours(l.minutes_worked)}</Td>
                <Td className="text-right tabular-nums text-muted">{l.tasks_verified}</Td>
                {money ? (
                  <>
                    <Td className="text-right tabular-nums">{formatMoney(l.daily_rate)}</Td>
                    <Td className="text-right tabular-nums">{l.bonus ? formatMoney(l.bonus) : "—"}</Td>
                    <Td className="text-right tabular-nums">{l.deductions ? formatMoney(l.deductions) : "—"}</Td>
                    <Td className="text-right font-medium tabular-nums">{formatMoney(l.net, currency)}</Td>
                    <Td className="text-xs text-muted">
                      {(l.allocation ?? []).map((a, i) => (
                        <span key={i} className="block">
                          {a.label ?? "General work"}: {formatMoney(a.amount)}
                        </span>
                      ))}
                    </Td>
                  </>
                ) : null}
                <Td className="text-right">
                  {canPrepare && money && r.status === "draft" ? (
                    <Button size="sm" variant="ghost" onClick={() => setLine(l)}>
                      Adjust
                    </Button>
                  ) : null}
                </Td>
              </tr>
            ))}
            {money ? (
              <tr>
                <Td colSpan={7} className="text-right font-medium">
                  Gross {formatMoney(r.total_gross, currency)} · deductions {formatMoney(r.total_deductions, currency)} · net
                </Td>
                <Td className="text-right font-semibold tabular-nums">{formatMoney(r.total_net, currency)}</Td>
                <Td colSpan={2} className="text-sm text-muted">
                  {(r.paid_amount ?? 0) > 0 ? `${formatMoney(r.paid_amount, currency)} paid` : ""}
                </Td>
              </tr>
            ) : null}
          </tbody>
        </Table>
      )}
      <p className="mt-6 text-xs text-muted">
        Prepared by {r.prepared_by?.name ?? "—"}
        {r.approved_at ? ` · approved ${formatDateTime(r.approved_at)}${r.approved_by ? ` by ${r.approved_by.name}` : ""}` : ""}
      </p>

      {line ? (
        <FormDialog
          title={`Adjust ${line.worker?.full_name}`}
          submitLabel="Save"
          onClose={() => setLine(null)}
          onSubmit={async (f) => {
            await api.PATCH("/farms/{farm}/payroll-runs/{payrollRun}/lines/{payrollLine}", {
              params: { path: { farm: farmId, payrollRun: r.id!, payrollLine: line.id! } },
              body: { bonus: Number(f.get("bonus") || 0), deductions: Number(f.get("deductions") || 0), note: text(f, "note") },
            });
            setLine(null);
            await refresh();
          }}
        >
          {(error) => (
            <>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <Label htmlFor="bonus">Bonus</Label>
                  <Input id="bonus" name="bonus" type="number" step="any" min="0" defaultValue={line.bonus ?? 0} />
                </div>
                <div>
                  <Label htmlFor="deductions">Deductions</Label>
                  <Input id="deductions" name="deductions" type="number" step="any" min="0" defaultValue={line.deductions ?? 0} aria-invalid={!!error?.fieldError("deductions")} />
                  {error?.fieldError("deductions") ? <p className="mt-1 text-sm text-danger">{error.fieldError("deductions")}</p> : null}
                </div>
              </div>
              <div>
                <Label htmlFor="note">Note</Label>
                <Input id="note" name="note" defaultValue={line.note ?? ""} placeholder="Advance of 8,000 taken back" />
              </div>
            </>
          )}
        </FormDialog>
      ) : null}
      {dialog === "pay" ? (
        <PaymentDialog
          farmId={farmId}
          currency={currency}
          types={["payroll_run"]}
          preset={{ type: "payroll_run", id: r.id!, label: r.code ?? "", due: Math.round(((r.total_net ?? 0) - (r.paid_amount ?? 0)) * 100) / 100 }}
          onClose={() => setDialog(null)}
          onDone={async () => { setDialog(null); await refresh(); }}
        />
      ) : null}
      {dialog === "cancel" ? (
        <ReasonDialog
          title={`Cancel ${r.code}`}
          submitLabel="Cancel payroll"
          field="reason"
          onClose={() => setDialog(null)}
          onSubmit={async (reason) => {
            await api.POST("/farms/{farm}/payroll-runs/{payrollRun}/cancel", { params: path, body: { reason } });
            setDialog(null);
            await refresh();
          }}
        />
      ) : null}
    </>
  );
}
