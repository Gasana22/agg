"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { FieldError, Label, Select, Textarea } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { usePlatformWorkspace } from "@/lib/api/hooks";
import { formatDateTime, humanize } from "@/lib/format";
import { can } from "@/lib/permissions";

const REASONS = ["non_payment", "policy_violation", "security", "owner_request", "other"] as const;

export default function AdminFarmPage() {
  const { farmId } = useParams<{ farmId: string }>();
  const queryClient = useQueryClient();
  const { workspace } = usePlatformWorkspace();
  const caps = workspace?.permissions;
  const [suspendOpen, setSuspendOpen] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  const farm = useQuery({
    queryKey: ["admin-farm", farmId],
    queryFn: async () => (await api.GET("/admin/farms/{farm}", { params: { path: { farm: farmId } } })).data!.data!,
  });

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ["admin-farm", farmId] });
    queryClient.invalidateQueries({ queryKey: ["admin-farms"] });
  };
  const path = { params: { path: { farm: farmId } } };

  const approve = useMutation({ mutationFn: () => api.POST("/admin/farms/{farm}/approve", path), onSuccess: refresh });
  const unsuspend = useMutation({ mutationFn: () => api.POST("/admin/farms/{farm}/unsuspend", { ...path, body: {} }), onSuccess: refresh });
  const reset = useMutation({
    mutationFn: () => api.POST("/admin/farms/{farm}/reset-owner-password", path),
    onSuccess: () => setNotice("A password-reset link was emailed to the owner."),
  });
  const actionError = approve.error ?? unsuspend.error ?? reset.error;

  if (farm.isLoading) return <Skeleton className="h-64 w-full" />;
  if (farm.error) return <ErrorNotice error={farm.error} />;
  const f = farm.data!;

  return (
    <>
      <Link href="/admin/farms" className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden /> All farms
      </Link>
      <PageHeader
        title={f.name ?? "Farm"}
        description={`${f.code} · ${f.district ?? "—"} · ${f.organization?.name ?? ""}`}
        actions={
          <div className="flex flex-wrap gap-2">
            {f.status === "pending" && can(caps, "farms.approve") ? <Button onClick={() => approve.mutate()} disabled={approve.isPending}>Approve</Button> : null}
            {(f.status === "pending" || f.status === "active") && can(caps, "farms.suspend") ? (
              <Button variant="danger" onClick={() => setSuspendOpen(true)}>Suspend</Button>
            ) : null}
            {f.status === "suspended" && can(caps, "farms.suspend") ? <Button onClick={() => unsuspend.mutate()} disabled={unsuspend.isPending}>Lift suspension</Button> : null}
            {can(caps, "farms.reset_owner_password") ? (
              <Button variant="secondary" onClick={() => reset.mutate()} disabled={reset.isPending}>Send owner password reset</Button>
            ) : null}
          </div>
        }
      />
      {actionError ? <div className="mb-4"><ErrorNotice error={actionError} /></div> : null}
      {notice ? <p role="status" className="mb-4 rounded-lg bg-primary-soft px-4 py-2 text-sm text-primary">{notice}</p> : null}

      <div className="grid gap-4 md:grid-cols-3">
        <Card>
          <CardHeader><CardTitle>Status</CardTitle></CardHeader>
          <CardContent className="space-y-2 text-sm">
            <StatusBadge status={f.status} />
            {f.suspension_reason ? <p className="text-muted">Reason: {humanize(f.suspension_reason)}</p> : null}
            <p className="text-muted">Approved: {formatDateTime(f.approved_at)}</p>
            <p className="text-muted">Created: {formatDateTime(f.created_at)}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle>Owner</CardTitle></CardHeader>
          <CardContent className="text-sm">
            <p className="font-medium">{f.owner?.name ?? "—"}</p>
            <p className="text-muted">{f.owner?.email}</p>
            <p className="mt-2 text-muted">{f.member_count} active member(s)</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle>Subscription</CardTitle></CardHeader>
          <CardContent className="space-y-2 text-sm">
            {f.subscription ? (
              <>
                <p className="flex items-center gap-2"><StatusBadge status={f.subscription.status} /> <span className="uppercase text-muted">{f.subscription.plan}</span></p>
                <p className="text-muted">Period ends {f.subscription.current_period_end}</p>
                {can(caps, "subscriptions.view") ? (
                  <Link href={`/admin/subscriptions/${f.subscription.id}`} className="text-primary hover:underline">Open subscription</Link>
                ) : null}
              </>
            ) : (
              <p className="text-muted">No subscription</p>
            )}
          </CardContent>
        </Card>
      </div>

      <Card className="mt-4">
        <CardHeader><CardTitle>Status history</CardTitle></CardHeader>
        <CardContent>
          {(f.history ?? []).length === 0 ? (
            <p className="text-sm text-muted">No changes yet.</p>
          ) : (
            <ol className="space-y-3">
              {f.history!.map((h, i) => (
                <li key={i} className="text-sm">
                  <span className="font-medium">{h.from_status} → {h.to_status}</span>
                  {h.reason_code ? <span className="text-muted"> · {humanize(h.reason_code)}</span> : null}
                  <span className="text-muted"> · {formatDateTime(h.at)}</span>
                  {h.note ? <p className="text-muted">{h.note}</p> : null}
                </li>
              ))}
            </ol>
          )}
        </CardContent>
      </Card>

      <SuspendDialog open={suspendOpen} onClose={() => setSuspendOpen(false)} farmId={farmId} onDone={refresh} canAnyReason={can(caps, "farms.approve")} />
    </>
  );
}

function SuspendDialog({ open, onClose, farmId, onDone, canAnyReason }: { open: boolean; onClose: () => void; farmId: string; onDone: () => void; canAnyReason: boolean }) {
  const [error, setError] = useState<ApiError | null>(null);
  const [pending, setPending] = useState(false);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    setPending(true);
    setError(null);
    try {
      await api.POST("/admin/farms/{farm}/suspend", {
        params: { path: { farm: farmId } },
        body: { reason_code: f.get("reason_code") as (typeof REASONS)[number], note: String(f.get("note") || "") || undefined },
      });
      onDone();
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    } finally {
      setPending(false);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title="Suspend farm" description="Members lose access immediately. The owner is emailed. Data is kept.">
      <form onSubmit={onSubmit} className="space-y-4">
        <div>
          <Label htmlFor="reason_code">Reason</Label>
          <Select id="reason_code" name="reason_code" defaultValue={canAnyReason ? "policy_violation" : "non_payment"}>
            {REASONS.filter((r) => canAnyReason || r === "non_payment").map((r) => (
              <option key={r} value={r}>{humanize(r)}</option>
            ))}
          </Select>
        </div>
        <div>
          <Label htmlFor="note">Note to the owner</Label>
          <Textarea id="note" name="note" maxLength={1000} />
        </div>
        {error ? <FieldError>{error.problem.title}</FieldError> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="danger" disabled={pending}>Suspend</Button>
        </div>
      </form>
    </Dialog>
  );
}
