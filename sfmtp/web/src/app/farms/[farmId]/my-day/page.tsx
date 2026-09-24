"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Clock, LogIn, LogOut } from "lucide-react";
import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { LeaveDialog, SubmitDialog } from "@/components/workforce/dialogs";
import { useMyDay } from "@/components/workforce/queries";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState, ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { humanize } from "@/lib/format";
import { can } from "@/lib/permissions";
import { currentPosition, formatClock, formatMinutes, LEAVE_TONE, type Task, TASK_STATUS, workerActions } from "@/lib/workforce";

export default function MyDayPage() {
  return (
    <Suspense>
      <MyDay />
    </Suspense>
  );
}

function MyDay() {
  const { farmId } = useParams<{ farmId: string }>();
  const search = useSearchParams();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const writable = workspace?.type === "farm";
  const day = useMyDay(farmId);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [submitting, setSubmitting] = useState<Task | null>(null);
  const [leave, setLeave] = useState(search.get("leave") === "1");

  const refresh = () => queryClient.invalidateQueries({ queryKey: ["my-day", farmId] });

  async function run(key: string, action: () => Promise<unknown>) {
    setBusy(key);
    setError(null);
    try {
      await action();
      await refresh();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(null);
    }
  }

  const attendance = (inOut: "check-in" | "check-out") =>
    run(inOut, async () => {
      const where = await currentPosition();
      await api.POST(`/farms/{farm}/attendance/${inOut}`, { params: { path: { farm: farmId } }, body: { ...(where ?? {}) } });
    });

  const step = (task: Task, event: "start" | "pause" | "resume") =>
    run(`${task.id}:${event}`, async () => {
      const where = await currentPosition();
      await api.POST(`/farms/{farm}/tasks/{task}/${event}`, { params: { path: { farm: farmId, task: task.id! } }, body: { ...(where ?? {}) } });
    });

  if (day.isLoading) return <Skeleton className="h-64" />;
  if (day.error || !day.data) return <ErrorNotice error={day.error} />;
  const d = day.data;

  if (!d.worker) {
    return (
      <>
        <PageHeader title="My day" />
        <EmptyState title="You don't have a worker profile on this farm">Ask your manager to link your account to your worker profile.</EmptyState>
      </>
    );
  }

  const a = d.attendance;
  const canRecord = writable && can(perms, "attendance.record");
  const canExecute = writable && can(perms, "tasks.execute");

  return (
    <>
      <PageHeader title={`Good day, ${d.worker.full_name?.split(" ")[0]}`} description={`${d.date} · ${d.counts?.open ?? 0} to do, ${d.counts?.done_today ?? 0} done today`} />
      {error ? (
        <div className="mb-4">
          <ErrorNotice error={error} />
        </div>
      ) : null}

      <section className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-surface p-4" aria-label="Attendance">
        <div className="flex items-center gap-3">
          <Clock className="size-5 text-muted" />
          <div>
            <p className="font-medium">{!a ? "Not checked in" : a.check_out_at ? "Checked out" : "On site"}</p>
            <p className="text-sm text-muted">
              {a ? `In ${formatClock(a.check_in_at)}${a.check_out_at ? ` · out ${formatClock(a.check_out_at)} · ${formatMinutes(a.minutes)}` : ""}` : "Check in when you arrive; your location is recorded only while you work."}
            </p>
          </div>
        </div>
        {canRecord ? (
          !a ? (
            <Button onClick={() => attendance("check-in")} disabled={busy !== null}>
              <LogIn /> {busy === "check-in" ? "Checking in…" : "Check in"}
            </Button>
          ) : !a.check_out_at ? (
            <Button variant="secondary" onClick={() => attendance("check-out")} disabled={busy !== null}>
              <LogOut /> {busy === "check-out" ? "Checking out…" : "Check out"}
            </Button>
          ) : null
        ) : null}
      </section>

      <h2 className="mb-3 text-lg font-semibold">Today&apos;s tasks</h2>
      {d.tasks?.length === 0 ? (
        <EmptyState title="Nothing assigned for today" />
      ) : (
        <ul className="mb-8 space-y-3">
          {d.tasks?.map((t) => {
            const s = TASK_STATUS[t.status ?? "assigned"];
            return (
              <li key={t.id} className="rounded-xl border border-border bg-surface p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-2 font-medium">
                      <Link href={`/farms/${farmId}/tasks/${t.id}`} className="hover:underline">
                        {t.activity?.title}
                      </Link>
                      <Badge tone={s.tone}>{s.label}</Badge>
                      {t.overdue ? <Badge tone="danger">Overdue</Badge> : null}
                      {t.activity?.priority === "high" ? <Badge tone="warning">High priority</Badge> : null}
                    </p>
                    <p className="text-sm text-muted">
                      {t.code}
                      {t.activity?.subject?.label ? ` · ${t.activity.subject.label}` : ""}
                      {t.activity?.target_quantity ? ` · target ${t.activity.target_quantity} ${t.activity.target_unit ?? ""}` : ""}
                    </p>
                    {t.activity?.instructions ? <p className="mt-2 text-sm">{t.activity.instructions}</p> : null}
                    {t.status === "rejected" && t.review_note ? <p className="mt-2 text-sm text-danger">Sent back: {t.review_note}</p> : null}
                  </div>
                  {canExecute ? (
                    <div className="flex gap-2">
                      {workerActions(t.status ?? "").map((ev) =>
                        ev === "submit" ? (
                          <Button key={ev} size="sm" onClick={() => setSubmitting(t)}>
                            Done
                          </Button>
                        ) : (
                          <Button key={ev} size="sm" variant={ev === "pause" ? "secondary" : "primary"} disabled={busy !== null} onClick={() => step(t, ev)}>
                            {ev === "start" ? (t.status === "rejected" ? "Start again" : "Start") : humanize(ev)}
                          </Button>
                        ),
                      )}
                    </div>
                  ) : null}
                </div>
              </li>
            );
          })}
        </ul>
      )}

      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-lg font-semibold">Leave</h2>
        {writable && can(perms, "leave.request") ? (
          <Button size="sm" variant="secondary" onClick={() => setLeave(true)}>
            Request leave
          </Button>
        ) : null}
      </div>
      {d.leave?.length === 0 ? (
        <p className="text-sm text-muted">No upcoming leave.</p>
      ) : (
        <ul className="space-y-2">
          {d.leave?.map((l) => (
            <li key={l.id} className="flex items-center justify-between rounded-lg border border-border px-4 py-2 text-sm">
              <span>
                {humanize(l.kind ?? "")} · {l.from_on} → {l.to_on}
              </span>
              <Badge tone={LEAVE_TONE[l.status ?? ""] ?? "neutral"}>{humanize(l.status ?? "")}</Badge>
            </li>
          ))}
        </ul>
      )}

      {submitting ? (
        <SubmitDialog
          farmId={farmId}
          task={submitting}
          point={currentPosition}
          onClose={() => setSubmitting(null)}
          onDone={async () => {
            setSubmitting(null);
            await refresh();
          }}
        />
      ) : null}
      {leave ? (
        <LeaveDialog
          farmId={farmId}
          onClose={() => setLeave(false)}
          onDone={async () => {
            setLeave(false);
            await refresh();
          }}
        />
      ) : null}
    </>
  );
}
