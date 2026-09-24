"use client";

import { useQueryClient } from "@tanstack/react-query";
import { MapPin } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { ReviewDialog, SubmitDialog } from "@/components/workforce/dialogs";
import { useTask } from "@/components/workforce/queries";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ErrorNotice, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { formatDateTime, humanize } from "@/lib/format";
import { can } from "@/lib/permissions";
import { currentPosition, EVENT_LABEL, formatMinutes, TASK_STATUS, workerActions } from "@/lib/workforce";

export default function TaskPage() {
  const { farmId, taskId } = useParams<{ farmId: string; taskId: string }>();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const writable = workspace?.type === "farm";
  const task = useTask(farmId, taskId);
  const [dialog, setDialog] = useState<"verify" | "reject" | "cancel" | "submit" | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const refresh = async () => {
    setDialog(null);
    await queryClient.invalidateQueries({ queryKey: ["task", farmId, taskId] });
    await queryClient.invalidateQueries({ queryKey: ["tasks", farmId] });
    await queryClient.invalidateQueries({ queryKey: ["my-day", farmId] });
  };

  if (task.isLoading) return <Skeleton className="h-64" />;
  if (task.error || !task.data) return <ErrorNotice error={task.error} />;
  const t = task.data;
  const status = TASK_STATUS[t.status ?? "assigned"];
  // Worker steps are offered only on the member's own task; the server checks it again.
  const own = writable && can(perms, "tasks.execute") && t.is_mine === true;

  async function step(event: "start" | "pause" | "resume") {
    setBusy(true);
    setError(null);
    try {
      const where = await currentPosition();
      await api.POST(`/farms/{farm}/tasks/{task}/${event}`, { params: { path: { farm: farmId, task: taskId } }, body: { ...(where ?? {}) } });
      await refresh();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <nav className="mb-2 text-sm text-muted">
        <Link href={`/farms/${farmId}/tasks`} className="hover:underline">
          Tasks
        </Link>{" "}
        / {t.code}
      </nav>
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex flex-wrap items-center gap-2 text-2xl font-semibold">
            {t.activity?.title} <Badge tone={status.tone}>{status.label}</Badge>
            {t.overdue ? <Badge tone="danger">Overdue</Badge> : null}
          </h1>
          <p className="text-sm text-muted">
            {t.code} · {t.worker?.full_name} · {humanize(t.activity?.module ?? "general")}
            {t.activity?.subject?.label ? ` · ${t.activity.subject.label}` : ""} · due {t.due_on ?? "—"}
          </p>
        </div>
        {writable ? (
          <div className="flex flex-wrap gap-2">
            {own
              ? workerActions(t.status ?? "").map((a) =>
                  a === "submit" ? (
                    <Button key={a} size="sm" onClick={() => setDialog("submit")}>
                      Submit
                    </Button>
                  ) : (
                    <Button key={a} size="sm" variant={a === "pause" ? "secondary" : "primary"} disabled={busy} onClick={() => step(a)}>
                      {a === "start" ? (t.status === "rejected" ? "Start again" : "Start") : humanize(a)}
                    </Button>
                  ),
                )
              : null}
            {t.status === "submitted" && can(perms, "tasks.verify") && !own ? (
              <>
                <Button size="sm" onClick={() => setDialog("verify")}>
                  Verify
                </Button>
                <Button size="sm" variant="secondary" onClick={() => setDialog("reject")}>
                  Send back
                </Button>
              </>
            ) : null}
            {["assigned", "in_progress", "paused", "rejected"].includes(t.status ?? "") && can(perms, "tasks.manage") ? (
              <Button size="sm" variant="ghost" className="text-danger" onClick={() => setDialog("cancel")}>
                Cancel task
              </Button>
            ) : null}
          </div>
        ) : null}
      </div>
      {error ? (
        <div className="mb-4">
          <ErrorNotice error={error} />
        </div>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
        <section>
          {t.activity?.instructions ? (
            <div className="mb-4 rounded-lg border border-border bg-surface-muted/50 px-4 py-3 text-sm">
              <p className="mb-1 font-medium">Instructions</p>
              <p className="whitespace-pre-line">{t.activity.instructions}</p>
            </div>
          ) : null}
          {t.review_note ? (
            <div className="mb-4 rounded-lg border border-warning/40 bg-warning/10 px-4 py-3 text-sm" role="status">
              <p className="font-medium">{t.status === "rejected" ? "Sent back" : t.status === "cancelled" ? "Cancelled" : "Supervisor's note"}</p>
              <p>{t.review_note}</p>
            </div>
          ) : null}

          <h2 className="mb-3 text-lg font-semibold">History</h2>
          <ol className="space-y-2">
            {t.logs?.map((l) => (
              <li key={l.id} className={`rounded-lg border px-4 py-2.5 text-sm ${l.applied ? "border-border" : "border-danger/40 bg-danger-soft/40"}`}>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="font-medium">
                    {EVENT_LABEL[l.event ?? ""] ?? l.event}
                    {!l.applied ? <Badge tone="danger" className="ml-2">Refused offline step</Badge> : null}
                    {l.quantity !== null && l.quantity !== undefined ? <span className="font-normal text-muted"> · {l.quantity} {l.unit}</span> : null}
                  </p>
                  <time className="text-xs text-muted" dateTime={l.occurred_at}>
                    {formatDateTime(l.occurred_at)}
                  </time>
                </div>
                {l.note ? <p className="mt-1">{l.note}</p> : null}
                <p className="mt-1 flex items-center gap-2 text-xs text-muted">
                  {l.recorded_by?.name}
                  {l.point ? (
                    <a
                      className="inline-flex items-center gap-1 text-primary hover:underline"
                      href={`https://www.openstreetmap.org/?mlat=${l.point.lat}&mlon=${l.point.lng}#map=17/${l.point.lat}/${l.point.lng}`}
                      target="_blank"
                      rel="noreferrer"
                    >
                      <MapPin className="size-3" /> {l.point.lat?.toFixed(5)}, {l.point.lng?.toFixed(5)}
                      {l.point.accuracy_m ? ` (±${Math.round(l.point.accuracy_m)} m)` : ""}
                    </a>
                  ) : null}
                </p>
              </li>
            ))}
            {t.logs?.length === 0 ? <p className="text-sm text-muted">Not started yet.</p> : null}
          </ol>

          {t.photos && t.photos.length > 0 ? (
            <>
              <h2 className="mb-3 mt-6 text-lg font-semibold">Photos</h2>
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {t.photos.map((p) => (
                  <figure key={p.id} className="overflow-hidden rounded-lg border border-border">
                    {/* eslint-disable-next-line @next/next/no-img-element -- private media through the BFF */}
                    <img src={`/api/proxy/farms/${farmId}/media/${p.media_id}/content`} alt={p.caption ?? `Photo for ${t.code}`} className="aspect-square w-full object-cover" />
                    <figcaption className="px-2 py-1 text-xs text-muted">{p.caption ?? formatDateTime(p.taken_at ?? null)}</figcaption>
                  </figure>
                ))}
              </div>
            </>
          ) : null}
        </section>

        <aside className="space-y-4">
          <div className="rounded-xl border border-border bg-surface p-4 text-sm">
            <h2 className="mb-2 font-semibold">Work</h2>
            <dl className="grid grid-cols-2 gap-y-2">
              <dt className="text-muted">Planned</dt>
              <dd>{t.activity?.planned_on}</dd>
              <dt className="text-muted">Target</dt>
              <dd>{t.activity?.target_quantity ? `${t.activity.target_quantity} ${t.activity.target_unit ?? ""}` : "—"}</dd>
              <dt className="text-muted">Done</dt>
              <dd>{t.quantity !== null && t.quantity !== undefined ? `${t.quantity} ${t.unit ?? ""}` : "—"}</dd>
              <dt className="text-muted">Time worked</dt>
              <dd>{formatMinutes(t.worked_minutes)}</dd>
              <dt className="text-muted">Started</dt>
              <dd>{formatDateTime(t.started_at ?? null)}</dd>
              <dt className="text-muted">Submitted</dt>
              <dd>{formatDateTime(t.submitted_at ?? null)}</dd>
              {t.verified_by ? (
                <>
                  <dt className="text-muted">Verified by</dt>
                  <dd>{t.verified_by.name}</dd>
                </>
              ) : null}
            </dl>
            {t.submit_note ? <p className="mt-3 border-t border-border pt-3">“{t.submit_note}”</p> : null}
          </div>
          <p className="text-xs text-muted">Every step is kept with its time and place. Verified work on a crop or an animal appears in its traceability history.</p>
        </aside>
      </div>

      {dialog === "submit" ? <SubmitDialog farmId={farmId} task={t} point={currentPosition} onClose={() => setDialog(null)} onDone={refresh} /> : null}
      {dialog === "verify" || dialog === "reject" || dialog === "cancel" ? (
        <ReviewDialog farmId={farmId} task={t} action={dialog} onClose={() => setDialog(null)} onDone={refresh} />
      ) : null}
    </>
  );
}
