"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { money, UsageMeters } from "@/components/billing/usage";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { FieldError, Input, Label, Select } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status";
import { api, idempotencyKey } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { usePlatformWorkspace } from "@/lib/api/hooks";
import { formatDateTime, humanize } from "@/lib/format";
import { can } from "@/lib/permissions";

type Action = "payment" | "plan" | "extend" | "cancel" | null;

export default function AdminSubscriptionPage() {
  const { subscriptionId } = useParams<{ subscriptionId: string }>();
  const queryClient = useQueryClient();
  const { workspace } = usePlatformWorkspace();
  const manage = can(workspace?.permissions, "subscriptions.manage");
  const [action, setAction] = useState<Action>(null);

  const sub = useQuery({
    queryKey: ["admin-subscription", subscriptionId],
    queryFn: async () => (await api.GET("/admin/subscriptions/{subscription}", { params: { path: { subscription: subscriptionId } } })).data!.data!,
  });

  if (sub.isLoading) return <Skeleton className="h-64 w-full" />;
  if (sub.error) return <ErrorNotice error={sub.error} />;
  const s = sub.data!;
  const done = () => {
    setAction(null);
    queryClient.invalidateQueries({ queryKey: ["admin-subscription", subscriptionId] });
    queryClient.invalidateQueries({ queryKey: ["admin-subscriptions"] });
  };

  return (
    <>
      <Link href="/admin/subscriptions" className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden /> All subscriptions
      </Link>
      <PageHeader
        title={s.organization?.name ?? "Subscription"}
        description={`${s.organization?.owner?.name ?? ""} · ${s.organization?.owner?.email ?? ""}`}
        actions={
          manage ? (
            <div className="flex flex-wrap gap-2">
              <Button onClick={() => setAction("payment")}>Record payment</Button>
              <Button variant="secondary" onClick={() => setAction("plan")}>Change plan</Button>
              <Button variant="secondary" onClick={() => setAction("extend")}>Extend</Button>
              {s.status !== "cancelled" ? <Button variant="danger" onClick={() => setAction("cancel")}>Cancel</Button> : null}
            </div>
          ) : null
        }
      />

      <div className="grid gap-4 md:grid-cols-3">
        <Card>
          <CardHeader><CardTitle>Plan & status</CardTitle></CardHeader>
          <CardContent className="space-y-2 text-sm">
            <p className="text-lg font-semibold">{s.plan?.name} <span className="text-sm font-normal text-muted">{money(s.plan?.price)} / {s.plan?.billing_period === "yearly" ? "year" : "month"}</span></p>
            <p className="flex items-center gap-2"><StatusBadge status={s.status} />{s.cancel_at_period_end ? <span className="text-xs text-muted">cancels at period end</span> : null}</p>
            <p className="text-muted">Period {s.current_period_start} → {s.current_period_end}</p>
            {s.grace_until ? <p className="text-muted">Grace until {s.grace_until}</p> : null}
          </CardContent>
        </Card>
        <Card className="md:col-span-2">
          <CardHeader><CardTitle>Usage</CardTitle></CardHeader>
          <CardContent><UsageMeters usage={s.usage} /></CardContent>
        </Card>
      </div>

      <Card className="mt-4">
        <CardHeader><CardTitle>Payments</CardTitle></CardHeader>
        <CardContent>
          {(s.payments ?? []).length === 0 ? (
            <p className="text-sm text-muted">No payments yet.</p>
          ) : (
            <Table>
              <thead><tr><Th>Paid</Th><Th>Amount</Th><Th>Provider</Th><Th>Reference</Th><Th>Status</Th><Th>Covers</Th></tr></thead>
              <tbody>
                {s.payments!.map((p) => (
                  <tr key={p.id}>
                    <Td className="text-muted">{formatDateTime(p.paid_at)}</Td>
                    <Td className="tabular-nums">{money(p.amount)}</Td>
                    <Td>{humanize(p.provider ?? "")}</Td>
                    <Td className="font-mono text-xs">{p.provider_ref}</Td>
                    <Td><StatusBadge status={p.status} /></Td>
                    <Td className="text-muted">{p.period_start ? `${p.period_start} → ${p.period_end}` : "—"}</Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Card className="mt-4">
        <CardHeader><CardTitle>History</CardTitle></CardHeader>
        <CardContent>
          <ol className="space-y-2 text-sm">
            {(s.history ?? []).map((h, i) => (
              <li key={i}>
                <span className="font-medium">{humanize(h.event ?? "")}</span>
                <span className="text-muted"> · {h.from_status ? `${h.from_status} → ` : ""}{h.to_status} · {formatDateTime(h.at)}</span>
              </li>
            ))}
          </ol>
        </CardContent>
      </Card>

      <PaymentDialog open={action === "payment"} onClose={() => setAction(null)} id={subscriptionId} price={s.plan?.price?.amount} currency={s.plan?.price?.currency} onDone={done} />
      <PlanDialog open={action === "plan"} onClose={() => setAction(null)} id={subscriptionId} current={s.plan?.code} onDone={done} />
      <SimpleDialog open={action === "extend"} kind="extend" onClose={() => setAction(null)} id={subscriptionId} onDone={done} />
      <SimpleDialog open={action === "cancel"} kind="cancel" onClose={() => setAction(null)} id={subscriptionId} onDone={done} />
    </>
  );
}

function useSubmit(onDone: () => void) {
  const [error, setError] = useState<ApiError | null>(null);
  const [pending, setPending] = useState(false);
  const run = async (fn: () => Promise<unknown>) => {
    setPending(true);
    setError(null);
    try {
      await fn();
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    } finally {
      setPending(false);
    }
  };
  return { error, pending, run };
}

function PaymentDialog({ open, onClose, id, price, currency, onDone }: { open: boolean; onClose: () => void; id: string; price?: string; currency?: string; onDone: () => void }) {
  const { error, pending, run } = useSubmit(onDone);
  const [key] = useState(idempotencyKey);

  return (
    <Dialog open={open} onClose={onClose} title="Record payment" description="A successful payment of at least the plan price starts or extends a paid period.">
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          const f = new FormData(e.currentTarget);
          run(() =>
            api.POST("/admin/subscriptions/{subscription}/payments", {
              params: { path: { subscription: id }, header: { "Idempotency-Key": key } },
              body: {
                amount: Number(f.get("amount")),
                currency: currency ?? "UGX",
                provider: f.get("provider") as "mobile_money",
                provider_ref: String(f.get("provider_ref")),
                status: f.get("status") as "succeeded",
                notes: String(f.get("notes") || "") || undefined,
              },
            }),
          );
        }}
      >
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label htmlFor="amount">Amount ({currency})</Label>
            <Input id="amount" name="amount" type="number" min="0" step="0.01" defaultValue={price ? Number(price) : undefined} required aria-invalid={!!error?.fieldError("amount")} />
          </div>
          <div>
            <Label htmlFor="provider">Method</Label>
            <Select id="provider" name="provider" defaultValue="mobile_money">
              <option value="mobile_money">Mobile money</option>
              <option value="bank_transfer">Bank transfer</option>
              <option value="cash">Cash</option>
              <option value="manual">Other</option>
            </Select>
          </div>
        </div>
        <div>
          <Label htmlFor="provider_ref">Reference</Label>
          <Input id="provider_ref" name="provider_ref" required placeholder="Transaction ID" aria-invalid={!!error?.fieldError("provider_ref")} />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label htmlFor="status">Outcome</Label>
            <Select id="status" name="status" defaultValue="succeeded">
              <option value="succeeded">Succeeded</option>
              <option value="failed">Failed</option>
            </Select>
          </div>
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Input id="notes" name="notes" />
          </div>
        </div>
        <FieldError>{error?.fieldError("amount") ?? error?.fieldError("currency") ?? (error ? error.problem.title : null)}</FieldError>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={pending}>Record</Button>
        </div>
      </form>
    </Dialog>
  );
}

function PlanDialog({ open, onClose, id, current, onDone }: { open: boolean; onClose: () => void; id: string; current?: string; onDone: () => void }) {
  const { error, pending, run } = useSubmit(onDone);
  const plans = useQuery({ queryKey: ["admin-plans"], queryFn: async () => (await api.GET("/admin/plans")).data!.data!, enabled: open });

  return (
    <Dialog open={open} onClose={onClose} title="Change plan" description="Usage must fit the new plan's limits.">
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          const code = String(new FormData(e.currentTarget).get("plan_code"));
          run(() => api.POST("/admin/subscriptions/{subscription}/change-plan", { params: { path: { subscription: id } }, body: { plan_code: code } }));
        }}
      >
        <Select name="plan_code" aria-label="Plan" defaultValue={current}>
          {(plans.data ?? []).filter((p) => p.is_active).map((p) => (
            <option key={p.id} value={p.code}>{p.name} ({money(p.price)}){p.is_public ? "" : " · private"}</option>
          ))}
        </Select>
        <FieldError>{error?.fieldError("plan_code") ?? (error ? error.problem.title : null)}</FieldError>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={pending}>Change plan</Button>
        </div>
      </form>
    </Dialog>
  );
}

function SimpleDialog({ open, kind, onClose, id, onDone }: { open: boolean; kind: "extend" | "cancel"; onClose: () => void; id: string; onDone: () => void }) {
  const { error, pending, run } = useSubmit(onDone);
  const path = { params: { path: { subscription: id } } };

  return (
    <Dialog open={open} onClose={onClose} title={kind === "extend" ? "Extend period" : "Cancel subscription"} description={kind === "extend" ? "Adds days to the current trial or paid period." : "Cancelling now blocks the organization's farms immediately."}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          const f = new FormData(e.currentTarget);
          const reason = String(f.get("reason"));
          run(() =>
            kind === "extend"
              ? api.POST("/admin/subscriptions/{subscription}/extend", { ...path, body: { days: Number(f.get("days")), reason } })
              : api.POST("/admin/subscriptions/{subscription}/cancel", { ...path, body: { at_period_end: f.get("when") === "end", reason } }),
          );
        }}
      >
        {kind === "extend" ? (
          <div>
            <Label htmlFor="days">Days</Label>
            <Input id="days" name="days" type="number" min="1" max="90" defaultValue={14} required />
          </div>
        ) : (
          <div>
            <Label htmlFor="when">When</Label>
            <Select id="when" name="when" defaultValue="end">
              <option value="end">At the end of the current period</option>
              <option value="now">Immediately</option>
            </Select>
          </div>
        )}
        <div>
          <Label htmlFor="reason">Reason</Label>
          <Input id="reason" name="reason" required maxLength={500} />
        </div>
        <FieldError>{error ? error.problem.title : null}</FieldError>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Close</Button>
          <Button type="submit" variant={kind === "cancel" ? "danger" : "primary"} disabled={pending}>{kind === "extend" ? "Extend" : "Cancel subscription"}</Button>
        </div>
      </form>
    </Dialog>
  );
}
