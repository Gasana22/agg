"use client";

import { useInfiniteQuery, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { useActions } from "@/components/inventory/common";
import { ReasonDialog } from "@/components/inventory/dialogs";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { EXPENSE_STATUS, INVOICE_STATUS, PAYABLE_LABEL, PAYMENT_METHODS, PAYROLL_STATUS, type Customer, type Expense } from "@/lib/finance";
import { formatMoney } from "@/lib/inventory";
import type { Permissions } from "@/lib/permissions";
import { can } from "@/lib/permissions";

import { BudgetDialog, CustomerDialog, ExpenseDialog, IncomeDialog, InvoiceDialog, PaymentDialog, PayrollDialog } from "./dialogs";

type P = { farmId: string; currency: string; perms: Permissions | undefined; writable: boolean; startNew?: string | null };

function LoadMore({ q }: { q: { hasNextPage: boolean; fetchNextPage: () => unknown; isFetchingNextPage: boolean } }) {
  return q.hasNextPage ? (
    <div className="mt-3 text-center">
      <Button variant="secondary" size="sm" disabled={q.isFetchingNextPage} onClick={() => q.fetchNextPage()}>
        Load more
      </Button>
    </div>
  ) : null;
}

function Toolbar({ children, action }: { children?: React.ReactNode; action?: React.ReactNode }) {
  return (
    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
      <div className="flex flex-wrap gap-2">{children}</div>
      {action}
    </div>
  );
}

export function ExpensesPanel({ farmId, currency, perms, writable, startNew, initialStatus }: P & { initialStatus?: string | null }) {
  const queryClient = useQueryClient();
  const canRecord = writable && can(perms, "finance.manage");
  const canRequest = writable && can(perms, "finance.expenses.request|finance.manage");
  const canDecide = writable && can(perms, "finance.manage|finance.approve");
  const [status, setStatus] = useState(initialStatus ?? "");
  const [dialog, setDialog] = useState<{ kind: string; expense?: Expense } | null>(startNew && canRequest ? { kind: "new" } : null);
  const expenses = useInfiniteQuery({
    queryKey: ["expenses", farmId, status],
    queryFn: async ({ pageParam }) => (await api.GET("/farms/{farm}/expenses", { params: { path: { farm: farmId }, query: { "filter[status]": status || undefined, cursor: pageParam ?? undefined, per_page: 50 } } })).data!,
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta?.next_cursor ?? null,
  });
  const rows = expenses.data?.pages.flatMap((p) => p.data ?? []) ?? [];
  const refresh = () => Promise.all(["expenses", "ledger-accounts", "open-documents"].map((k) => queryClient.invalidateQueries({ queryKey: [k, farmId] })));
  const { error, busy, run } = useActions(refresh);
  const path = (e: Expense) => ({ path: { farm: farmId, expense: e.id! } });

  return (
    <>
      <Toolbar
        action={
          canRequest ? (
            <Button size="sm" onClick={() => setDialog({ kind: "new" })}>
              <Plus /> {canRecord ? "Expense" : "Request an expense"}
            </Button>
          ) : null
        }
      >
        <Select aria-label="Status" className="h-9 w-40" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All</option>
          {Object.entries(EXPENSE_STATUS).map(([k, v]) => (
            <option key={k} value={k}>
              {v.label}
            </option>
          ))}
        </Select>
      </Toolbar>
      {error ? <div className="mb-3"><ErrorNotice error={error} /></div> : null}
      {expenses.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : expenses.error ? (
        <ErrorNotice error={expenses.error} />
      ) : rows.length === 0 ? (
        <EmptyState title="No expenses here" />
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>Expense</Th>
                <Th>Account · charged to</Th>
                <Th className="text-right">Amount</Th>
                <Th>Status</Th>
                <Th className="text-right">Actions</Th>
              </tr>
            </thead>
            <tbody>
              {rows.map((e) => {
                const st = EXPENSE_STATUS[e.status ?? ""];
                return (
                  <tr key={e.id}>
                    <Td>
                      <span className="font-medium">{e.description}</span>
                      <span className="block text-xs text-muted">
                        {e.code} · {e.spent_on}
                        {e.payee ? ` · ${e.payee}` : ""} · {e.requested_by?.name}
                      </span>
                    </Td>
                    <Td className="text-muted">
                      {e.account?.name}
                      <span className="block text-xs">{e.cost_center?.label ?? "General"}</span>
                    </Td>
                    <Td className="text-right tabular-nums">
                      {formatMoney(e.amount, currency)}
                      {e.status === "approved" && (e.paid_amount ?? 0) > 0 ? <span className="block text-xs text-muted">{formatMoney(e.paid_amount, currency)} paid</span> : null}
                    </Td>
                    <Td>
                      <Badge tone={st?.tone ?? "neutral"}>{st?.label ?? e.status}</Badge>
                      {e.decision_note ? <span className="mt-1 block text-xs text-muted">{e.decision_note}</span> : null}
                    </Td>
                    <Td className="text-right">
                      <div className="flex flex-wrap justify-end gap-2">
                        {canDecide && e.status === "requested" ? (
                          <>
                            <Button size="sm" disabled={busy === e.id} onClick={() => run(e.id!, () => api.POST("/farms/{farm}/expenses/{expense}/approve", { params: path(e), body: {} }))}>
                              Approve
                            </Button>
                            <Button size="sm" variant="secondary" onClick={() => setDialog({ kind: "reject", expense: e })}>
                              Reject
                            </Button>
                          </>
                        ) : null}
                        {canRecord && e.status === "approved" ? (
                          <Button size="sm" onClick={() => setDialog({ kind: "pay", expense: e })}>
                            Pay
                          </Button>
                        ) : null}
                        {e.status === "requested" ? (
                          <Button size="sm" variant="ghost" disabled={busy === e.id} onClick={() => run(e.id!, () => api.POST("/farms/{farm}/expenses/{expense}/cancel", { params: path(e) }))}>
                            Withdraw
                          </Button>
                        ) : null}
                        {canRecord && (e.status === "approved" || e.status === "paid") && !((e.paid_amount ?? 0) > 0 && !e.paid_from) ? (
                          <Button size="sm" variant="ghost" onClick={() => setDialog({ kind: "void", expense: e })}>
                            Void
                          </Button>
                        ) : null}
                      </div>
                    </Td>
                  </tr>
                );
              })}
            </tbody>
          </Table>
          <LoadMore q={expenses} />
        </>
      )}
      {dialog?.kind === "new" ? <ExpenseDialog farmId={farmId} perms={perms} canRecord={canRecord} onClose={() => setDialog(null)} onDone={async () => { setDialog(null); await refresh(); }} /> : null}
      {dialog?.kind === "pay" && dialog.expense ? (
        <PaymentDialog
          farmId={farmId}
          currency={currency}
          types={["expense"]}
          preset={{ type: "expense", id: dialog.expense.id!, label: `${dialog.expense.code} ${dialog.expense.description}`, due: (dialog.expense.amount ?? 0) - (dialog.expense.paid_amount ?? 0) }}
          onClose={() => setDialog(null)}
          onDone={async () => { setDialog(null); await refresh(); }}
        />
      ) : null}
      {dialog?.kind === "reject" && dialog.expense ? (
        <ReasonDialog
          title={`Reject ${dialog.expense.code}`}
          submitLabel="Reject"
          onClose={() => setDialog(null)}
          onSubmit={async (note) => {
            await api.POST("/farms/{farm}/expenses/{expense}/reject", { params: path(dialog.expense!), body: { note } });
            setDialog(null);
            await refresh();
          }}
        />
      ) : null}
      {dialog?.kind === "void" && dialog.expense ? (
        <ReasonDialog
          title={`Void ${dialog.expense.code}`}
          submitLabel="Void"
          field="reason"
          onClose={() => setDialog(null)}
          onSubmit={async (reason) => {
            await api.POST("/farms/{farm}/expenses/{expense}/void", { params: path(dialog.expense!), body: { reason } });
            setDialog(null);
            await refresh();
          }}
        />
      ) : null}
    </>
  );
}

export function IncomePanel({ farmId, currency, perms, writable, startNew }: P) {
  const queryClient = useQueryClient();
  const canRecord = writable && can(perms, "finance.manage");
  const [creating, setCreating] = useState(Boolean(startNew) && canRecord);
  const [voiding, setVoiding] = useState<string | null>(null);
  const income = useInfiniteQuery({
    queryKey: ["income", farmId],
    queryFn: async ({ pageParam }) => (await api.GET("/farms/{farm}/income", { params: { path: { farm: farmId }, query: { cursor: pageParam ?? undefined, per_page: 50 } } })).data!,
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta?.next_cursor ?? null,
  });
  const rows = income.data?.pages.flatMap((p) => p.data ?? []) ?? [];
  const refresh = () => Promise.all(["income", "ledger-accounts"].map((k) => queryClient.invalidateQueries({ queryKey: [k, farmId] })));
  return (
    <>
      <Toolbar
        action={
          canRecord ? (
            <Button size="sm" onClick={() => setCreating(true)}>
              <Plus /> Income
            </Button>
          ) : null
        }
      />
      {income.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : income.error ? (
        <ErrorNotice error={income.error} />
      ) : rows.length === 0 ? (
        <EmptyState title="No other income yet">Money received without an invoice: gate sales, manure, grants.</EmptyState>
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>Income</Th>
                <Th>Account · into</Th>
                <Th className="text-right">Amount</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {rows.map((i) => (
                <tr key={i.id} className={i.status === "void" ? "text-muted line-through" : ""}>
                  <Td>
                    <span className="font-medium">{i.description}</span>
                    <span className="block text-xs text-muted">
                      {i.code} · {i.received_on}
                      {i.payer ? ` · ${i.payer}` : ""}
                      {i.cost_center ? ` · ${i.cost_center.label}` : ""}
                    </span>
                  </Td>
                  <Td className="text-muted">
                    {i.account?.name}
                    <span className="block text-xs">{i.received_into?.name}</span>
                  </Td>
                  <Td className="text-right tabular-nums">{formatMoney(i.amount, currency)}</Td>
                  <Td className="text-right">
                    {canRecord && i.status === "recorded" ? (
                      <Button size="sm" variant="ghost" onClick={() => setVoiding(i.id!)}>
                        Void
                      </Button>
                    ) : i.status === "void" ? (
                      <Badge>Void</Badge>
                    ) : null}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
          <LoadMore q={income} />
        </>
      )}
      {creating ? <IncomeDialog farmId={farmId} perms={perms} onClose={() => setCreating(false)} onDone={async () => { setCreating(false); await refresh(); }} /> : null}
      {voiding ? (
        <ReasonDialog
          title="Void income"
          submitLabel="Void"
          field="reason"
          onClose={() => setVoiding(null)}
          onSubmit={async (reason) => {
            await api.POST("/farms/{farm}/income/{income}/void", { params: { path: { farm: farmId, income: voiding } }, body: { reason } });
            setVoiding(null);
            await refresh();
          }}
        />
      ) : null}
    </>
  );
}

export function InvoicesPanel({ farmId, currency, perms, writable, startNew }: P) {
  const router = useRouter();
  const canInvoice = writable && can(perms, "sales.invoice");
  const [status, setStatus] = useState("open");
  const [creating, setCreating] = useState(Boolean(startNew) && canInvoice);
  const statuses = status === "open" ? "draft,issued" : status === "overdue" ? undefined : status || undefined;
  const invoices = useInfiniteQuery({
    queryKey: ["customer-invoices", farmId, status],
    queryFn: async ({ pageParam }) =>
      (
        await api.GET("/farms/{farm}/customer-invoices", {
          params: { path: { farm: farmId }, query: { "filter[status]": statuses, "filter[overdue]": status === "overdue" || undefined, cursor: pageParam ?? undefined, per_page: 50 } },
        })
      ).data!,
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta?.next_cursor ?? null,
  });
  const rows = invoices.data?.pages.flatMap((p) => p.data ?? []) ?? [];
  return (
    <>
      <Toolbar
        action={
          canInvoice ? (
            <Button size="sm" onClick={() => setCreating(true)}>
              <Plus /> Invoice
            </Button>
          ) : null
        }
      >
        <Select aria-label="Show" className="h-9 w-40" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="open">Open</option>
          <option value="overdue">Overdue</option>
          <option value="paid">Paid</option>
          <option value="void">Void</option>
          <option value="">All</option>
        </Select>
      </Toolbar>
      {invoices.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : invoices.error ? (
        <ErrorNotice error={invoices.error} />
      ) : rows.length === 0 ? (
        <EmptyState title="No invoices here" />
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>Invoice</Th>
                <Th>Customer</Th>
                <Th>Due</Th>
                <Th className="text-right">Amount</Th>
                <Th className="text-right">Outstanding</Th>
                <Th>Status</Th>
              </tr>
            </thead>
            <tbody>
              {rows.map((i) => {
                const st = INVOICE_STATUS[i.status ?? ""];
                return (
                  <tr key={i.id} className="hover:bg-surface-muted/50">
                    <Td>
                      <Link href={`/farms/${farmId}/finance/invoices/${i.id}`} className="font-medium text-primary hover:underline">
                        {i.code}
                      </Link>
                      <span className="block text-xs text-muted">{i.invoice_date}</span>
                    </Td>
                    <Td>{i.customer?.name}</Td>
                    <Td className={i.overdue ? "text-danger" : "text-muted"}>{i.due_on ?? "—"}</Td>
                    <Td className="text-right tabular-nums">{formatMoney(i.amount, currency)}</Td>
                    <Td className="text-right tabular-nums">{i.status === "issued" ? formatMoney((i.amount ?? 0) - (i.paid_amount ?? 0), currency) : "—"}</Td>
                    <Td>
                      <Badge tone={i.overdue ? "danger" : (st?.tone ?? "neutral")}>{i.overdue ? "Overdue" : (st?.label ?? i.status)}</Badge>
                    </Td>
                  </tr>
                );
              })}
            </tbody>
          </Table>
          <LoadMore q={invoices} />
        </>
      )}
      {creating ? <InvoiceDialog farmId={farmId} currency={currency} onClose={() => setCreating(false)} onDone={(id) => router.push(`/farms/${farmId}/finance/invoices/${id}`)} /> : null}
    </>
  );
}

export function PaymentsPanel({ farmId, currency, perms, writable, startNew }: P) {
  const queryClient = useQueryClient();
  const types = [
    ...(can(perms, "sales.invoice|finance.manage") ? ["customer_invoice"] : []),
    ...(can(perms, "finance.manage") ? ["supplier_invoice", "expense"] : []),
    ...(can(perms, "finance.manage|finance.payroll.manage") ? ["payroll_run"] : []),
  ];
  const [direction, setDirection] = useState("");
  const [creating, setCreating] = useState<string | null>(startNew && writable && types.includes(startNew) ? startNew : null);
  const [voiding, setVoiding] = useState<string | null>(null);
  const payments = useInfiniteQuery({
    queryKey: ["payments", farmId, direction],
    queryFn: async ({ pageParam }) => (await api.GET("/farms/{farm}/payments", { params: { path: { farm: farmId }, query: { "filter[direction]": (direction || undefined) as "in", cursor: pageParam ?? undefined, per_page: 50 } } })).data!,
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta?.next_cursor ?? null,
  });
  const rows = payments.data?.pages.flatMap((p) => p.data ?? []) ?? [];
  const refresh = () => Promise.all(["payments", "ledger-accounts", "open-documents", "expenses", "customer-invoices", "payroll-runs"].map((k) => queryClient.invalidateQueries({ queryKey: [k, farmId] })));
  const incoming = types.filter((t) => t === "customer_invoice");
  const outgoing = types.filter((t) => t !== "customer_invoice");
  return (
    <>
      <Toolbar
        action={
          writable ? (
            <div className="flex gap-2">
              {incoming.length ? (
                <Button size="sm" variant="secondary" onClick={() => setCreating("customer_invoice")}>
                  <Plus /> Money in
                </Button>
              ) : null}
              {outgoing.length ? (
                <Button size="sm" onClick={() => setCreating(outgoing[0])}>
                  <Plus /> Money out
                </Button>
              ) : null}
            </div>
          ) : null
        }
      >
        <Select aria-label="Direction" className="h-9 w-40" value={direction} onChange={(e) => setDirection(e.target.value)}>
          <option value="">In and out</option>
          <option value="in">Money in</option>
          <option value="out">Money out</option>
        </Select>
      </Toolbar>
      {payments.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : payments.error ? (
        <ErrorNotice error={payments.error} />
      ) : rows.length === 0 ? (
        <EmptyState title="No payments yet" />
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>Payment</Th>
                <Th>For</Th>
                <Th>Method · account</Th>
                <Th className="text-right">Amount</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {rows.map((p) => (
                <tr key={p.id} className={p.status === "void" ? "text-muted line-through" : ""}>
                  <Td>
                    <span className="font-medium">{p.party ?? "—"}</span>
                    <span className="block text-xs text-muted">
                      {p.code} · {p.paid_on}
                      {p.reference ? ` · ${p.reference}` : ""}
                    </span>
                  </Td>
                  <Td className="text-muted">
                    {PAYABLE_LABEL[p.payable?.type ?? ""]} {p.payable?.code}
                  </Td>
                  <Td className="text-muted">
                    {PAYMENT_METHODS.find((m) => m.key === p.method)?.label}
                    <span className="block text-xs">{p.account?.name}</span>
                  </Td>
                  <Td className={`text-right tabular-nums ${p.direction === "in" ? "text-success" : ""}`}>
                    {p.direction === "in" ? "+" : "−"}
                    {formatMoney(p.amount, currency)}
                  </Td>
                  <Td className="text-right">
                    {writable && p.status === "posted" && types.includes(p.payable?.type ?? "") ? (
                      <Button size="sm" variant="ghost" onClick={() => setVoiding(p.id!)}>
                        Void
                      </Button>
                    ) : p.status === "void" ? (
                      <Badge>Void</Badge>
                    ) : null}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
          <LoadMore q={payments} />
        </>
      )}
      {creating ? (
        <PaymentDialog farmId={farmId} currency={currency} types={creating === "customer_invoice" ? incoming : outgoing} onClose={() => setCreating(null)} onDone={async () => { setCreating(null); await refresh(); }} />
      ) : null}
      {voiding ? (
        <ReasonDialog
          title="Void payment"
          submitLabel="Void"
          field="reason"
          onClose={() => setVoiding(null)}
          onSubmit={async (reason) => {
            await api.POST("/farms/{farm}/payments/{payment}/void", { params: { path: { farm: farmId, payment: voiding } }, body: { reason } });
            setVoiding(null);
            await refresh();
          }}
        />
      ) : null}
    </>
  );
}

export function PayrollPanel({ farmId, currency, perms, writable, startNew }: P) {
  const router = useRouter();
  const canPrepare = writable && can(perms, "finance.payroll.manage");
  const seesMoney = can(perms, "finance.values.view");
  const [creating, setCreating] = useState(Boolean(startNew) && canPrepare);
  const runs = useQuery({
    queryKey: ["payroll-runs", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/payroll-runs", { params: { path: { farm: farmId } } })).data!.data!,
  });
  return (
    <>
      <Toolbar
        action={
          canPrepare ? (
            <Button size="sm" onClick={() => setCreating(true)}>
              <Plus /> Prepare payroll
            </Button>
          ) : null
        }
      />
      {runs.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : runs.error ? (
        <ErrorNotice error={runs.error} />
      ) : runs.data!.length === 0 ? (
        <EmptyState title="No payroll yet">Payroll uses attendance and each worker&apos;s daily rate.</EmptyState>
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Payroll</Th>
              <Th>Period</Th>
              <Th className="text-right">Workers</Th>
              {seesMoney ? <Th className="text-right">Net pay</Th> : null}
              <Th>Status</Th>
            </tr>
          </thead>
          <tbody>
            {runs.data!.map((r) => {
              const st = PAYROLL_STATUS[r.status ?? ""];
              return (
                <tr key={r.id} className="hover:bg-surface-muted/50">
                  <Td>
                    <Link href={`/farms/${farmId}/finance/payroll/${r.id}`} className="font-medium text-primary hover:underline">
                      {r.code}
                    </Link>
                    <span className="block text-xs text-muted">{r.prepared_by?.name}</span>
                  </Td>
                  <Td>
                    {r.period_start} – {r.period_end}
                  </Td>
                  <Td className="text-right tabular-nums">{r.workers}</Td>
                  {seesMoney ? <Td className="text-right tabular-nums">{formatMoney(r.total_net, currency)}</Td> : null}
                  <Td>
                    <Badge tone={st?.tone ?? "neutral"}>{st?.label ?? r.status}</Badge>
                  </Td>
                </tr>
              );
            })}
          </tbody>
        </Table>
      )}
      {creating ? <PayrollDialog farmId={farmId} onClose={() => setCreating(false)} onDone={(id) => router.push(`/farms/${farmId}/finance/payroll/${id}`)} /> : null}
    </>
  );
}

export function BudgetsPanel({ farmId, currency, perms, writable, startNew }: P) {
  const router = useRouter();
  const canManage = writable && can(perms, "finance.budgets.manage");
  const [creating, setCreating] = useState(Boolean(startNew) && canManage);
  const budgets = useQuery({
    queryKey: ["budgets", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/budgets", { params: { path: { farm: farmId } } })).data!.data!,
  });
  return (
    <>
      <Toolbar
        action={
          canManage ? (
            <Button size="sm" onClick={() => setCreating(true)}>
              <Plus /> Budget
            </Button>
          ) : null
        }
      />
      {budgets.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : budgets.error ? (
        <ErrorNotice error={budgets.error} />
      ) : budgets.data!.length === 0 ? (
        <EmptyState title="No budgets yet">Plan the season&apos;s costs and income per crop cycle, animal group or the whole farm.</EmptyState>
      ) : (
        <div className="grid gap-3 md:grid-cols-2">
          {budgets.data!.map((b) => {
            const spent = b.totals?.expense_actual ?? 0;
            const planned = b.totals?.expense_budget ?? 0;
            const pct = planned > 0 ? Math.min(100, Math.round((spent * 100) / planned)) : 0;
            return (
              <Link key={b.id} href={`/farms/${farmId}/finance/budgets/${b.id}`} className="rounded-xl border border-border bg-surface p-4 hover:border-primary">
                <p className="font-medium">{b.name}</p>
                <p className="text-xs text-muted">
                  {b.code} · {b.period_start} – {b.period_end} · {b.scope?.label ?? "Whole farm"}
                </p>
                <div className="mt-3 h-2 overflow-hidden rounded-full bg-surface-muted" aria-hidden>
                  <div className={`h-full ${spent > planned ? "bg-danger" : "bg-primary"}`} style={{ width: `${pct}%` }} />
                </div>
                <p className="mt-1 text-sm tabular-nums">
                  {formatMoney(spent, currency)} of {formatMoney(planned, currency)} spent
                </p>
              </Link>
            );
          })}
        </div>
      )}
      {creating ? <BudgetDialog farmId={farmId} perms={perms} onClose={() => setCreating(false)} onDone={(id) => router.push(`/farms/${farmId}/finance/budgets/${id}`)} /> : null}
    </>
  );
}

export function CustomersPanel({ farmId, perms, writable }: Omit<P, "currency">) {
  const queryClient = useQueryClient();
  const canManage = writable && can(perms, "customers.manage");
  const [editing, setEditing] = useState<Customer | "new" | null>(null);
  const customers = useQuery({
    queryKey: ["customers", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/customers", { params: { path: { farm: farmId } } })).data!.data!,
  });
  return (
    <>
      <Toolbar
        action={
          canManage ? (
            <Button size="sm" onClick={() => setEditing("new")}>
              <Plus /> Customer
            </Button>
          ) : null
        }
      />
      {customers.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : customers.error ? (
        <ErrorNotice error={customers.error} />
      ) : customers.data!.length === 0 ? (
        <EmptyState title="No customers yet" />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Customer</Th>
              <Th>Contact</Th>
              <Th>Terms</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {customers.data!.map((c) => (
              <tr key={c.id}>
                <Td>
                  <span className="font-medium">{c.name}</span>
                  <span className="block text-xs text-muted">
                    {c.code}
                    {c.tax_id ? ` · TIN ${c.tax_id}` : ""}
                  </span>
                </Td>
                <Td className="text-muted">{[c.contact_person, c.phone, c.email].filter(Boolean).join(" · ") || "—"}</Td>
                <Td className="text-muted">{c.payment_terms_days != null ? `${c.payment_terms_days} days` : "—"}</Td>
                <Td className="text-right">
                  {canManage ? (
                    <Button size="sm" variant="ghost" onClick={() => setEditing(c)}>
                      Edit
                    </Button>
                  ) : null}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {editing ? (
        <CustomerDialog
          farmId={farmId}
          customer={editing === "new" ? undefined : editing}
          onClose={() => setEditing(null)}
          onDone={async () => {
            setEditing(null);
            await queryClient.invalidateQueries({ queryKey: ["customers", farmId] });
          }}
        />
      ) : null}
    </>
  );
}
