"use client";

import { useQuery } from "@tanstack/react-query";
import { useMemo, useState } from "react";

import { FormDialog, num, text } from "@/components/forms/form-dialog";
import { UnitSelect } from "@/components/forms/unit-select";
import { useActiveAnimals, useGroups } from "@/components/livestock/queries";
import { Checkbox, FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { api } from "@/lib/api/client";
import { useCatalog } from "@/lib/api/catalog";
import { humanize } from "@/lib/format";
import { can, type Permissions } from "@/lib/permissions";
import { EMPLOYMENT_TYPES, LEAVE_KINDS, type Task, type Worker } from "@/lib/workforce";

import { useOpenCycles, useWorkers } from "./queries";

type Base = { farmId: string; onClose: () => void; onDone: () => void };
const today = () => new Date().toISOString().slice(0, 10);

/** Which modules' work the member may plan: the same rule as the server. */
function plannableModules(perms: Permissions | undefined): string[] {
  const modules = ["general"];
  if (["crops.plans.view", "crops.operations.view", "crops.harvest.view"].some((p) => can(perms, p))) modules.push("crops");
  if (can(perms, "livestock.animals.view")) modules.push("livestock");
  if (can(perms, "assets.view")) modules.push("assets");
  if (can(perms, "inventory.view")) modules.push("inventory");
  return modules;
}

const SUBJECTS_FOR: Record<string, string[]> = {
  crops: ["crop_cycle", "plot", "general"],
  livestock: ["animal_group", "animal", "location", "general"],
  assets: ["location", "plot", "general"],
  inventory: ["location", "general"],
  general: ["general", "plot", "location", "crop_cycle", "animal_group", "animal"],
};

export function AssignWorkDialog({ farmId, perms, onClose, onDone }: Base & { perms: Permissions | undefined }) {
  const modules = plannableModules(perms);
  const types = useCatalog("activity-types");
  const workers = useWorkers(farmId);
  const [typeId, setTypeId] = useState("");
  const [subjectType, setSubjectType] = useState("general");
  const [chosen, setChosen] = useState<string[]>([]);

  const available = (types.data ?? []).filter((t) => t.is_active !== false && modules.includes(t.module ?? "general"));
  const type = available.find((t) => t.id === typeId);
  const typeModule = type?.module ?? "general";
  const subjects = SUBJECTS_FOR[typeModule].filter(
    (s) => (s !== "crop_cycle" || modules.includes("crops")) && ((s !== "animal" && s !== "animal_group") || modules.includes("livestock")),
  );

  const cycles = useOpenCycles(farmId, subjectType === "crop_cycle");
  const groups = useGroups(farmId, subjectType === "animal_group");
  const animals = useActiveAnimals(farmId, subjectType === "animal");
  const structure = useQuery({
    queryKey: ["structure", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/structure", { params: { path: { farm: farmId } } })).data!.data!,
    enabled: subjectType === "plot" || subjectType === "location",
  });

  const options = useMemo((): { id: string; label: string }[] => {
    switch (subjectType) {
      case "crop_cycle":
        return (cycles.data ?? []).filter((c) => c.stage !== "closed").map((c) => ({ id: c.id!, label: `${c.code} ${c.crop?.label ?? ""} · ${c.plot?.code ?? ""}` }));
      case "animal_group":
        return (groups.data ?? []).map((g) => ({ id: g.id!, label: `${g.code} ${g.name}` }));
      case "animal":
        return (animals.data ?? []).map((a) => ({ id: a.id!, label: a.label ?? a.animal_code ?? "" }));
      case "plot":
        return (structure.data?.plots ?? []).map((p) => ({ id: p.id!, label: `${p.code} ${p.name ?? ""}` }));
      case "location":
        return (structure.data?.locations ?? []).map((l) => ({ id: l.id!, label: `${l.code} ${l.name ?? ""}` }));
      default:
        return [];
    }
  }, [subjectType, cycles.data, groups.data, animals.data, structure.data]);

  return (
    <FormDialog
      title="Assign work"
      description="Each worker gets their own task and sees it on their phone."
      submitLabel="Assign"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/activities", {
          params: { path: { farm: farmId } },
          body: {
            activity_type_id: typeId,
            title: text(f, "title"),
            instructions: text(f, "instructions"),
            subject_type: subjectType as "general",
            subject_id: subjectType === "general" ? null : text(f, "subject_id"),
            planned_on: String(f.get("planned_on")),
            due_on: text(f, "due_on"),
            priority: String(f.get("priority")) as "normal",
            target_quantity: num(f, "target_quantity"),
            target_unit: num(f, "target_quantity") !== null ? text(f, "target_unit") : null,
            worker_ids: chosen,
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="activity_type_id">Work</Label>
              <Select id="activity_type_id" value={typeId} required onChange={(e) => { setTypeId(e.target.value); setSubjectType("general"); }} aria-invalid={!!error?.fieldError("activity_type_id")}>
                <option value="">Choose…</option>
                {modules.map((m) => {
                  const inModule = available.filter((t) => t.module === m);
                  return inModule.length ? (
                    <optgroup key={m} label={humanize(m)}>
                      {inModule.map((t) => (
                        <option key={t.id} value={t.id}>
                          {t.name}
                        </option>
                      ))}
                    </optgroup>
                  ) : null;
                })}
              </Select>
              <FieldError>{error?.fieldError("activity_type_id")}</FieldError>
            </div>
            <div>
              <Label htmlFor="subject_type">On</Label>
              <Select id="subject_type" value={subjectType} onChange={(e) => setSubjectType(e.target.value)} disabled={!type}>
                {subjects.map((s) => (
                  <option key={s} value={s}>
                    {s === "general" ? "General work" : humanize(s)}
                  </option>
                ))}
              </Select>
            </div>
          </div>
          {subjectType !== "general" ? (
            <div>
              <Label htmlFor="subject_id">{humanize(subjectType)}</Label>
              <Select id="subject_id" name="subject_id" required aria-invalid={!!error?.fieldError("subject_id")}>
                <option value="">Choose…</option>
                {options.map((o) => (
                  <option key={o.id} value={o.id}>
                    {o.label}
                  </option>
                ))}
              </Select>
              <FieldError>{error?.fieldError("subject_id")}</FieldError>
            </div>
          ) : null}
          <div>
            <Label htmlFor="title">Title (optional)</Label>
            <Input id="title" name="title" maxLength={150} placeholder={type ? `${type.name}…` : undefined} />
          </div>
          <div>
            <Label htmlFor="instructions">Instructions</Label>
            <Textarea id="instructions" name="instructions" rows={2} maxLength={2000} />
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label htmlFor="planned_on">Day</Label>
              <Input id="planned_on" name="planned_on" type="date" defaultValue={today()} required />
            </div>
            <div>
              <Label htmlFor="due_on">Due (optional)</Label>
              <Input id="due_on" name="due_on" type="date" />
              <FieldError>{error?.fieldError("due_on")}</FieldError>
            </div>
            <div>
              <Label htmlFor="priority">Priority</Label>
              <Select id="priority" name="priority" defaultValue="normal">
                <option value="low">Low</option>
                <option value="normal">Normal</option>
                <option value="high">High</option>
              </Select>
            </div>
          </div>
          <div className="grid grid-cols-[1fr_8rem] gap-3">
            <div>
              <Label htmlFor="target_quantity">Target (optional)</Label>
              <Input id="target_quantity" name="target_quantity" type="number" min="0" step="0.001" />
            </div>
            <div>
              <Label htmlFor="target_unit">Unit</Label>
              <UnitSelect id="target_unit" name="target_unit" defaultValue="ha" />
            </div>
          </div>
          <fieldset>
            <legend className="mb-1 text-sm font-medium">Workers</legend>
            <div className="grid max-h-40 grid-cols-2 gap-1 overflow-y-auto rounded-lg border border-border p-2">
              {(workers.data ?? []).map((w) => (
                <Checkbox
                  key={w.id}
                  label={`${w.full_name} (${w.worker_code})`}
                  checked={chosen.includes(w.id!)}
                  onChange={(e) => setChosen((c) => (e.target.checked ? [...c, w.id!] : c.filter((x) => x !== w.id)))}
                />
              ))}
              {workers.data?.length === 0 ? <p className="text-sm text-muted">No active workers yet. Add them under Workers.</p> : null}
            </div>
            <FieldError>{error?.fieldError("worker_ids") ?? error?.fieldError("worker_ids.0")}</FieldError>
          </fieldset>
        </>
      )}
    </FormDialog>
  );
}

export function WorkerDialog({ farmId, worker, seesMoney, onClose, onDone }: Base & { worker?: Worker; seesMoney: boolean }) {
  const members = useQuery({
    queryKey: ["members", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/members", { params: { path: { farm: farmId } } })).data!.data!,
  });
  return (
    <FormDialog
      title={worker ? `Edit ${worker.full_name}` : "Add a worker"}
      description="Link a farm member so they see their tasks in the app. Casual workers can be added without a login."
      submitLabel={worker ? "Save" : "Add worker"}
      onClose={onClose}
      onSubmit={async (f) => {
        const body = {
          full_name: String(f.get("full_name")),
          farm_user_id: text(f, "farm_user_id"),
          phone: text(f, "phone"),
          national_id: text(f, "national_id"),
          job_title: text(f, "job_title"),
          employment_type: String(f.get("employment_type")) as "casual",
          started_on: text(f, "started_on"),
          notes: text(f, "notes"),
          ...(seesMoney ? { daily_rate: num(f, "daily_rate") } : {}),
          ...(worker ? { status: String(f.get("status")) as "active", version: worker.version } : {}),
        };
        if (worker) await api.PATCH("/farms/{farm}/workers/{worker}", { params: { path: { farm: farmId, worker: worker.id! } }, body });
        else await api.POST("/farms/{farm}/workers", { params: { path: { farm: farmId } }, body });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="full_name">Full name</Label>
            <Input id="full_name" name="full_name" required minLength={2} maxLength={150} defaultValue={worker?.full_name} aria-invalid={!!error?.fieldError("full_name")} />
            <FieldError>{error?.fieldError("full_name")}</FieldError>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="employment_type">Employment</Label>
              <Select id="employment_type" name="employment_type" defaultValue={worker?.employment_type ?? "casual"}>
                {EMPLOYMENT_TYPES.map((t) => (
                  <option key={t} value={t}>
                    {humanize(t)}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="job_title">Job title</Label>
              <Input id="job_title" name="job_title" maxLength={80} defaultValue={worker?.job_title ?? ""} />
            </div>
          </div>
          <div>
            <Label htmlFor="farm_user_id">App login</Label>
            <Select id="farm_user_id" name="farm_user_id" defaultValue={worker?.member?.id ?? ""} aria-invalid={!!error?.fieldError("farm_user_id")}>
              <option value="">No login (casual worker)</option>
              {(members.data ?? [])
                .filter((m) => m.status === "active")
                .map((m) => (
                  <option key={m.id} value={m.id}>
                    {m.user?.name} · {m.user?.email}
                  </option>
                ))}
            </Select>
            <FieldError>{error?.fieldError("farm_user_id")}</FieldError>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="phone">Phone</Label>
              <Input id="phone" name="phone" maxLength={30} defaultValue={worker?.phone ?? ""} />
              <FieldError>{error?.fieldError("phone")}</FieldError>
            </div>
            <div>
              <Label htmlFor="national_id">National ID</Label>
              <Input id="national_id" name="national_id" maxLength={40} defaultValue={worker?.national_id ?? ""} />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="started_on">Started on</Label>
              <Input id="started_on" name="started_on" type="date" defaultValue={worker?.started_on ?? ""} />
            </div>
            {seesMoney ? (
              <div>
                <Label htmlFor="daily_rate">Daily rate</Label>
                <Input id="daily_rate" name="daily_rate" type="number" min="0" step="1" defaultValue={worker?.daily_rate ?? ""} />
              </div>
            ) : null}
          </div>
          {worker ? (
            <div>
              <Label htmlFor="status">Status</Label>
              <Select id="status" name="status" defaultValue={worker.status}>
                <option value="active">Active</option>
                <option value="inactive">Inactive (keeps history, no new tasks)</option>
              </Select>
            </div>
          ) : null}
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" rows={2} defaultValue={worker?.notes ?? ""} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function LeaveDialog({ farmId, forWorkers, onClose, onDone }: Base & { forWorkers?: Worker[] }) {
  return (
    <FormDialog
      title="Request leave"
      submitLabel="Send request"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/leave", {
          params: { path: { farm: farmId } },
          body: {
            worker_id: text(f, "worker_id") ?? undefined,
            kind: String(f.get("kind")) as "annual",
            from_on: String(f.get("from_on")),
            to_on: String(f.get("to_on")),
            reason: text(f, "reason"),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          {forWorkers ? (
            <div>
              <Label htmlFor="worker_id">Worker</Label>
              <Select id="worker_id" name="worker_id" required>
                <option value="">Choose…</option>
                {forWorkers.map((w) => (
                  <option key={w.id} value={w.id}>
                    {w.full_name}
                  </option>
                ))}
              </Select>
            </div>
          ) : null}
          <div>
            <Label htmlFor="kind">Kind</Label>
            <Select id="kind" name="kind" defaultValue="annual">
              {LEAVE_KINDS.map((k) => (
                <option key={k} value={k}>
                  {humanize(k)}
                </option>
              ))}
            </Select>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="from_on">From</Label>
              <Input id="from_on" name="from_on" type="date" required defaultValue={today()} />
            </div>
            <div>
              <Label htmlFor="to_on">To</Label>
              <Input id="to_on" name="to_on" type="date" required defaultValue={today()} />
              <FieldError>{error?.fieldError("to_on")}</FieldError>
            </div>
          </div>
          <div>
            <Label htmlFor="reason">Reason</Label>
            <Input id="reason" name="reason" maxLength={500} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

/** A manager's attendance entry for a worker without a phone. */
export function AttendanceEntryDialog({ farmId, workers, onClose, onDone }: Base & { workers: Worker[] }) {
  return (
    <FormDialog
      title="Enter attendance"
      description="For workers who could not check in themselves. The reason is kept in the audit log."
      submitLabel="Save"
      onClose={onClose}
      onSubmit={async (f) => {
        const day = String(f.get("day"));
        const at = (time: string | null) => (time ? new Date(`${day}T${time}`).toISOString() : null);
        await api.POST("/farms/{farm}/attendance", {
          params: { path: { farm: farmId } },
          body: { worker_id: String(f.get("worker_id")), check_in_at: at(String(f.get("in")))!, check_out_at: at(text(f, "out")), note: String(f.get("note")) },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="worker_id">Worker</Label>
            <Select id="worker_id" name="worker_id" required>
              <option value="">Choose…</option>
              {workers.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.full_name}
                </option>
              ))}
            </Select>
            <FieldError>{error?.fieldError("worker_id")}</FieldError>
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label htmlFor="day">Day</Label>
              <Input id="day" name="day" type="date" max={today()} defaultValue={today()} required />
            </div>
            <div>
              <Label htmlFor="in">In</Label>
              <Input id="in" name="in" type="time" defaultValue="07:00" required />
            </div>
            <div>
              <Label htmlFor="out">Out</Label>
              <Input id="out" name="out" type="time" />
              <FieldError>{error?.fieldError("check_out_at") ?? error?.fieldError("check_in_at")}</FieldError>
            </div>
          </div>
          <div>
            <Label htmlFor="note">Reason</Label>
            <Input id="note" name="note" required minLength={3} maxLength={500} placeholder="e.g. Signed the paper register" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

/** Verify, send back or cancel a task. */
export function ReviewDialog({ farmId, task, action, onClose, onDone }: Base & { task: Task; action: "verify" | "reject" | "cancel" }) {
  const titles = { verify: "Verify work", reject: "Send back for rework", cancel: "Cancel task" };
  return (
    <FormDialog
      title={`${titles[action]} · ${task.code}`}
      submitLabel={titles[action]}
      onClose={onClose}
      onSubmit={async (f) => {
        const path = { farm: farmId, task: task.id! };
        if (action === "verify") await api.POST("/farms/{farm}/tasks/{task}/verify", { params: { path }, body: { note: text(f, "note") } });
        else if (action === "reject") await api.POST("/farms/{farm}/tasks/{task}/reject", { params: { path }, body: { reason: String(f.get("note")) } });
        else await api.POST("/farms/{farm}/tasks/{task}/cancel", { params: { path }, body: { reason: String(f.get("note")) } });
        onDone();
      }}
    >
      {(error) => (
        <div>
          <Label htmlFor="note">{action === "verify" ? "Note (optional)" : "Reason"}</Label>
          <Textarea id="note" name="note" rows={2} required={action !== "verify"} minLength={action === "verify" ? undefined : 3} maxLength={500} />
          <FieldError>{error?.fieldError("reason") ?? error?.fieldError("note")}</FieldError>
        </div>
      )}
    </FormDialog>
  );
}

/** Submit a task with the work done. */
export function SubmitDialog({ farmId, task, point, onClose, onDone }: Base & { task: Task; point: () => Promise<{ lat: number; lng: number; accuracy_m: number } | null> }) {
  return (
    <FormDialog
      title={`Submit ${task.code}`}
      description="Say how much you did. Your supervisor verifies it."
      submitLabel="Submit"
      onClose={onClose}
      onSubmit={async (f) => {
        const where = await point();
        await api.POST("/farms/{farm}/tasks/{task}/submit", {
          params: { path: { farm: farmId, task: task.id! } },
          body: { quantity: num(f, "quantity"), unit: num(f, "quantity") !== null ? text(f, "unit") : null, note: text(f, "note"), ...(where ?? {}) },
        });
        onDone();
      }}
    >
      {() => (
        <>
          <div className="grid grid-cols-[1fr_8rem] gap-3">
            <div>
              <Label htmlFor="quantity">Done</Label>
              <Input id="quantity" name="quantity" type="number" min="0" step="0.001" defaultValue={task.activity?.target_quantity ?? undefined} />
            </div>
            <div>
              <Label htmlFor="unit">Unit</Label>
              <UnitSelect id="unit" name="unit" defaultValue={task.activity?.target_unit ?? "ha"} />
            </div>
          </div>
          <div>
            <Label htmlFor="note">Note</Label>
            <Textarea id="note" name="note" rows={2} maxLength={2000} />
          </div>
        </>
      )}
    </FormDialog>
  );
}
