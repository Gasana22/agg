"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Check } from "lucide-react";
import { useState } from "react";

import { money, UsageMeters } from "@/components/billing/usage";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { formatDateTime, humanize } from "@/lib/format";
import { cn } from "@/lib/utils";

const FEATURES: Record<string, string> = {
  traceability: "Traceability",
  mobile_app: "Mobile app (offline)",
  qr_codes: "QR code traceability",
  supplier_portal: "Supplier portal",
  customer_portal: "Customer portal",
  api_access: "API access",
  priority_support: "Priority support",
};

function limit(n: number | null | undefined, noun: string): string {
  if (n == null) return `Unlimited ${noun}s`;
  return `${n} ${noun}${n === 1 ? "" : "s"}`;
}

/** The owner's subscription for all their farms (ADR-0001). Works while farms are paused. */
export default function BillingPage() {
  const queryClient = useQueryClient();
  const [error, setError] = useState<ApiError | null>(null);
  const sub = useQuery({ queryKey: ["my-subscription"], queryFn: async () => (await api.GET("/billing/subscription")).data!.data! });
  const plans = useQuery({ queryKey: ["public-plans"], queryFn: async () => (await api.GET("/billing/plans")).data!.data! });

  const act = async (fn: () => Promise<unknown>) => {
    setError(null);
    try {
      await fn();
      await queryClient.invalidateQueries({ queryKey: ["my-subscription"] });
      await queryClient.invalidateQueries({ queryKey: ["workspaces"] });
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  };

  if (sub.isLoading) return <Skeleton className="h-64 w-full" />;
  if (sub.error) return <ErrorNotice error={sub.error} />;
  const s = sub.data!;

  return (
    <>
      <PageHeader title="Subscription" description={`Covers every farm in ${s.organization?.name ?? "your organization"}.`} />
      {error ? <div className="mb-4"><ErrorNotice error={error} />{error.problem.errors?.plan_code?.map((m) => <p key={m} className="mt-1 text-sm text-danger">{m}</p>)}</div> : null}

      <div className="grid gap-4 md:grid-cols-3">
        <Card>
          <CardHeader><CardTitle>Current plan</CardTitle></CardHeader>
          <CardContent className="space-y-2 text-sm">
            <p className="text-xl font-semibold">{s.plan?.name}</p>
            <p className="flex items-center gap-2"><StatusBadge status={s.status} /></p>
            <p className="text-muted">
              {s.status === "trialing" ? "Free trial until" : s.status === "grace" ? "Ended on" : "Renews on"} {s.current_period_end}
            </p>
            {s.status === "grace" ? <p className="text-warning">Renew before {s.grace_until} to avoid interruption.</p> : null}
            {s.status === "suspended" || s.status === "cancelled" ? <p className="text-danger">Your farms are paused. Contact SFMTP to renew; data is kept.</p> : null}
            {s.cancel_at_period_end ? (
              <div className="rounded-lg bg-surface-muted p-3">
                <p>Cancels at the end of this period.</p>
                <Button size="sm" variant="secondary" className="mt-2" onClick={() => act(() => api.POST("/billing/subscription/resume"))}>Keep my subscription</Button>
              </div>
            ) : s.status !== "cancelled" ? (
              <Button size="sm" variant="ghost" className="px-0 text-muted" onClick={() => act(() => api.POST("/billing/subscription/cancel", { body: {} }))}>Cancel at period end</Button>
            ) : null}
          </CardContent>
        </Card>
        <Card className="md:col-span-2">
          <CardHeader><CardTitle>Usage</CardTitle></CardHeader>
          <CardContent><UsageMeters usage={s.usage} /></CardContent>
        </Card>
      </div>

      <h2 className="mb-3 mt-8 text-lg font-semibold">Plans</h2>
      <div className="grid gap-4 md:grid-cols-3">
        {(plans.data ?? []).map((p) => {
          const current = p.code === s.plan?.code;
          return (
            <div key={p.id} className={cn("flex flex-col rounded-xl border bg-surface p-5", current ? "border-primary ring-1 ring-primary" : "border-border")}>
              <p className="font-semibold">{p.name}</p>
              <p className="mt-1 text-2xl font-semibold tabular-nums">{money(p.price)}<span className="text-sm font-normal text-muted"> / {p.billing_period === "yearly" ? "year" : "month"}</span></p>
              <p className="mt-1 text-sm text-muted">{p.description}</p>
              <ul className="mt-3 flex-1 space-y-1 text-sm">
                <li className="flex gap-2"><Check className="size-4 text-primary" aria-hidden />{limit(p.limits?.farms, "farm")}</li>
                <li className="flex gap-2"><Check className="size-4 text-primary" aria-hidden />{limit(p.limits?.users, "user")}</li>
                {(p.features ?? []).map((f) => <li key={f} className="flex gap-2"><Check className="size-4 text-primary" aria-hidden />{FEATURES[f] ?? humanize(f)}</li>)}
              </ul>
              <Button className="mt-4" variant={current ? "secondary" : "primary"} disabled={current} onClick={() => act(() => api.POST("/billing/subscription/change-plan", { body: { plan_code: p.code! } }))}>
                {current ? "Current plan" : "Switch to this plan"}
              </Button>
            </div>
          );
        })}
      </div>
      <p className="mt-3 text-xs text-muted">Payments are recorded by SFMTP (mobile money or bank transfer). Online checkout arrives with payment integrations.</p>

      <Card className="mt-8">
        <CardHeader><CardTitle>Payments</CardTitle></CardHeader>
        <CardContent>
          {(s.payments ?? []).length === 0 ? <p className="text-sm text-muted">No payments yet.</p> : (
            <Table>
              <thead><tr><Th>Date</Th><Th>Amount</Th><Th>Reference</Th><Th>Status</Th><Th>Covers</Th></tr></thead>
              <tbody>{s.payments!.map((p) => (<tr key={p.id}><Td className="text-muted">{formatDateTime(p.paid_at)}</Td><Td className="tabular-nums">{money(p.amount)}</Td><Td className="font-mono text-xs">{p.provider_ref}</Td><Td><StatusBadge status={p.status} /></Td><Td className="text-muted">{p.period_start ? `${p.period_start} → ${p.period_end}` : "—"}</Td></tr>))}</tbody>
            </Table>
          )}
        </CardContent>
      </Card>
    </>
  );
}
