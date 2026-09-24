"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { FormDialog } from "@/components/forms/form-dialog";
import { AttendanceEntryDialog, LeaveDialog, WorkerDialog } from "@/components/workforce/dialogs";
import { useWorkers } from "@/components/workforce/queries";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { FieldError, Input, Label } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { humanize } from "@/lib/format";
import { can } from "@/lib/permissions";
import { cn } from "@/lib/utils";
import { formatClock, formatMinutes, LEAVE_TONE, type Leave, type Worker } from "@/lib/workforce";

const TABS = [
  { key: "workers", label: "Workers" },
  { key: "attendance", label: "Attendance" },
  { key: "leave", label: "Leave" },
] as const;

export default function WorkersPage() {
  return (
    <Suspense>
      <Workers />
    </Suspense>
  );
}

function Workers() {
  const { farmId } = useParams<{ farmId: string }>();
  const search = useSearchParams();
  const router = useRouter();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const tab = TABS.find((t) => t.key === search.get("tab"))?.key ?? "workers";
  const visible = TABS.filter((t) => t.key === "workers" || (t.key === "attendance" ? can(perms, "attendance.view") : can(perms, "leave.approve") || can(perms, "leave.request")));

  return (
    <>
      <PageHeader title="Workers" description="Who works on the farm, when they were here, and their leave." />
      <div role="tablist" aria-label="Workforce sections" className="mb-4 flex gap-1 overflow-x-auto border-b border-border">
        {visible.map((t) => (
          <button
            key={t.key}
            role="tab"
            type="button"
            aria-selected={tab === t.key}
            onClick={() => router.replace(`/farms/${farmId}/workers${t.key === "workers" ? "" : `?tab=${t.key}`}`)}
            className={cn("-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm", tab === t.key ? "border-primary font-medium text-foreground" : "border-transparent text-muted hover:text-foreground")}
          >
            {t.label}
          </button>
        ))}
      </div>
      {tab === "workers" ? <WorkersPanel farmId={farmId} /> : tab === "attendance" ? <AttendancePanel farmId={farmId} /> : <LeavePanel farmId={farmId} />}
    </>
  );
}

function WorkersPanel({ farmId }: { farmId: string }) {
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const canManage = workspace?.type === "farm" && can(perms, "workers.manage");
  const seesMoney = can(perms, "finance.values.view");
  const [status, setStatus] = useState<"active" | "inactive">("active");
  const [editing, setEditing] = useState<Worker | "new" | null>(null);
  const workers = useWorkers(farmId, status);
  const done = async () => {
    setEditing(null);
    await queryClient.invalidateQueries({ queryKey: ["workers", farmId] });
  };

  return (
    <>
      <div className="mb-3 flex items-center justify-between gap-3">
        <div className="flex gap-1 text-sm">
          {(["active", "inactive"] as const).map((s) => (
            <Button key={s} size="sm" variant={status === s ? "secondary" : "ghost"} onClick={() => setStatus(s)}>
              {humanize(s)}
            </Button>
          ))}
        </div>
        {canManage ? (
          <Button size="sm" onClick={() => setEditing("new")}>
            <Plus /> Add worker
          </Button>
        ) : null}
      </div>
      {workers.error ? <ErrorNotice error={workers.error} /> : null}
      {workers.isLoading ? (
        <Skeleton className="h-40" />
      ) : workers.data?.length === 0 ? (
        <EmptyState title="No workers yet">Add the people who work on the farm; link members so they get their tasks on the phone.</EmptyState>
      ) : (
        <div className="overflow-x-auto">
          <Table>
            <thead>
              <tr>
                <Th>Code</Th>
                <Th>Name</Th>
                <Th>Role</Th>
                <Th>Phone</Th>
                <Th>App login</Th>
                {seesMoney ? <Th>Daily rate</Th> : null}
                {canManage ? <Th className="sr-only">Edit</Th> : null}
              </tr>
            </thead>
            <tbody>
              {workers.data?.map((w) => (
                <tr key={w.id}>
                  <Td className="font-medium">{w.worker_code}</Td>
                  <Td>{w.full_name}</Td>
                  <Td>
                    {w.job_title ?? "—"} <span className="text-xs text-muted">· {humanize(w.employment_type ?? "")}</span>
                  </Td>
                  <Td>{w.phone ?? "—"}</Td>
                  <Td>{w.member?.user ? <span className="text-sm">{w.member.user.email}</span> : <Badge>No login</Badge>}</Td>
                  {seesMoney ? <Td>{w.daily_rate ?? "—"}</Td> : null}
                  {canManage ? (
                    <Td className="text-right">
                      <Button size="sm" variant="ghost" onClick={() => setEditing(w)}>
                        Edit
                      </Button>
                    </Td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </Table>
        </div>
      )}
      {editing ? <WorkerDialog farmId={farmId} worker={editing === "new" ? undefined : editing} seesMoney={seesMoney} onClose={() => setEditing(null)} onDone={done} /> : null}
    </>
  );
}

function AttendancePanel({ farmId }: { farmId: string }) {
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const canEnter = workspace?.type === "farm" && can(perms, "attendance.approve");
  const [day, setDay] = useState(() => new Date().toISOString().slice(0, 10));
  const [entering, setEntering] = useState(false);
  const workers = useWorkers(farmId, "active", perms?.["workers.view"] === "all");
  const attendance = useQuery({
    queryKey: ["attendance", farmId, day],
    queryFn: async () => (await api.GET("/farms/{farm}/attendance", { params: { path: { farm: farmId }, query: { "filter[from]": day, "filter[to]": day, per_page: 200 } } })).data!.data!,
  });
  const present = new Set((attendance.data ?? []).map((a) => a.worker_id));
  const absent = (workers.data ?? []).filter((w) => !present.has(w.id));

  return (
    <>
      <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
        <div>
          <Label htmlFor="att-day">Day</Label>
          <Input id="att-day" type="date" value={day} max={new Date().toISOString().slice(0, 10)} onChange={(e) => setDay(e.target.value)} />
        </div>
        {canEnter ? (
          <Button size="sm" variant="secondary" onClick={() => setEntering(true)}>
            <Plus /> Enter attendance
          </Button>
        ) : null}
      </div>
      {attendance.error ? <ErrorNotice error={attendance.error} /> : null}
      {attendance.isLoading ? (
        <Skeleton className="h-32" />
      ) : (
        <div className="overflow-x-auto">
          <Table>
            <thead>
              <tr>
                <Th>Worker</Th>
                <Th>In</Th>
                <Th>Out</Th>
                <Th>Hours</Th>
                <Th>Place</Th>
                <Th>Source</Th>
              </tr>
            </thead>
            <tbody>
              {attendance.data?.map((a) => (
                <tr key={a.id}>
                  <Td className="font-medium">{a.worker?.full_name}</Td>
                  <Td>{formatClock(a.check_in_at)}</Td>
                  <Td>{a.check_out_at ? formatClock(a.check_out_at) : <Badge tone="info">On site</Badge>}</Td>
                  <Td>{formatMinutes(a.minutes)}</Td>
                  <Td className="text-xs text-muted">{a.check_in_point ? `${a.check_in_point.lat?.toFixed(4)}, ${a.check_in_point.lng?.toFixed(4)}` : "—"}</Td>
                  <Td>
                    {humanize(a.source ?? "")}
                    {a.note ? <span className="block text-xs text-muted">{a.note}</span> : null}
                  </Td>
                </tr>
              ))}
              {absent.map((w) => (
                <tr key={w.id} className="text-muted">
                  <Td>{w.full_name}</Td>
                  <Td colSpan={5}>Not checked in</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </div>
      )}
      {entering ? (
        <AttendanceEntryDialog
          farmId={farmId}
          workers={workers.data ?? []}
          onClose={() => setEntering(false)}
          onDone={async () => {
            setEntering(false);
            await queryClient.invalidateQueries({ queryKey: ["attendance", farmId] });
          }}
        />
      ) : null}
    </>
  );
}

function LeavePanel({ farmId }: { farmId: string }) {
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const writable = workspace?.type === "farm";
  const canApprove = writable && can(perms, "leave.approve");
  const [requesting, setRequesting] = useState(false);
  const [refusing, setRefusing] = useState<Leave | null>(null);
  const [error, setError] = useState<unknown>(null);
  const workers = useWorkers(farmId, "active", canApprove);
  const leave = useQuery({
    queryKey: ["leave", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/leave", { params: { path: { farm: farmId } } })).data!.data!,
  });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["leave", farmId] });

  async function approve(l: Leave) {
    setError(null);
    try {
      await api.POST("/farms/{farm}/leave/{leave}/approve", { params: { path: { farm: farmId, leave: l.id! } }, body: {} });
      await refresh();
    } catch (e) {
      setError(e);
    }
  }

  return (
    <>
      <div className="mb-3 flex justify-end">
        {writable ? (
          <Button size="sm" variant="secondary" onClick={() => setRequesting(true)}>
            <Plus /> {canApprove ? "Record leave" : "Request leave"}
          </Button>
        ) : null}
      </div>
      {error ? (
        <div className="mb-3">
          <ErrorNotice error={error} />
        </div>
      ) : null}
      {leave.isLoading ? (
        <Skeleton className="h-32" />
      ) : leave.data?.length === 0 ? (
        <EmptyState title="No leave requests" />
      ) : (
        <div className="overflow-x-auto">
          <Table>
            <thead>
              <tr>
                <Th>Worker</Th>
                <Th>Kind</Th>
                <Th>Dates</Th>
                <Th>Reason</Th>
                <Th>Status</Th>
                {canApprove ? <Th className="sr-only">Decide</Th> : null}
              </tr>
            </thead>
            <tbody>
              {leave.data?.map((l) => (
                <tr key={l.id}>
                  <Td className="font-medium">{l.worker?.full_name}</Td>
                  <Td>{humanize(l.kind ?? "")}</Td>
                  <Td className="whitespace-nowrap">
                    {l.from_on} → {l.to_on} <span className="text-xs text-muted">({l.days} d)</span>
                  </Td>
                  <Td>{l.reason ?? "—"}</Td>
                  <Td>
                    <Badge tone={LEAVE_TONE[l.status ?? ""] ?? "neutral"}>{humanize(l.status ?? "")}</Badge>
                    {l.decision_note ? <span className="block text-xs text-muted">{l.decision_note}</span> : null}
                  </Td>
                  {canApprove ? (
                    <Td className="whitespace-nowrap text-right">
                      {l.status === "requested" ? (
                        <>
                          <Button size="sm" variant="secondary" onClick={() => approve(l)}>
                            Approve
                          </Button>{" "}
                          <Button size="sm" variant="ghost" onClick={() => setRefusing(l)}>
                            Refuse
                          </Button>
                        </>
                      ) : null}
                    </Td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </Table>
        </div>
      )}
      {refusing ? (
        <FormDialog
          title={`Refuse leave for ${refusing.worker?.full_name}`}
          submitLabel="Refuse"
          onClose={() => setRefusing(null)}
          onSubmit={async (f) => {
            await api.POST("/farms/{farm}/leave/{leave}/reject", { params: { path: { farm: farmId, leave: refusing.id! } }, body: { note: String(f.get("note")) } });
            setRefusing(null);
            await refresh();
          }}
        >
          {(err) => (
            <div>
              <Label htmlFor="note">Reason</Label>
              <Input id="note" name="note" required minLength={3} maxLength={500} />
              <FieldError>{err?.fieldError("note")}</FieldError>
            </div>
          )}
        </FormDialog>
      ) : null}
      {requesting ? (
        <LeaveDialog
          farmId={farmId}
          forWorkers={canApprove ? workers.data : undefined}
          onClose={() => setRequesting(false)}
          onDone={async () => {
            setRequesting(false);
            await refresh();
          }}
        />
      ) : null}
    </>
  );
}
