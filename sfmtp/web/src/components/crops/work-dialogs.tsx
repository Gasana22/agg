"use client";

import { Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { api, idempotencyKey } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { CLOSE_REASONS, type CropCycle, type CropObservation, OBSERVATION_KINDS, OPERATION_TYPES, SEVERITIES } from "@/lib/crops";
import { humanize } from "@/lib/format";

import { FormDialog, num, text } from "@/components/forms/form-dialog";
import { UnitSelect } from "@/components/forms/unit-select";

type Base = { farmId: string; cycle: CropCycle; onClose: () => void; onDone: () => void };

const nowLocal = () => {
  const d = new Date();
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 16);
};
const today = () => new Date().toISOString().slice(0, 10);

type InputRow = { key: number; product_name: string; quantity: string; unit: string; withholding_days: string };

/** Field work with the inputs used; spraying can name the finding it treats. */
export function OperationDialog({ farmId, cycle, onClose, onDone, observations, seesMoney }: Base & { observations: CropObservation[]; seesMoney: boolean }) {
  const [rows, setRows] = useState<InputRow[]>([]);
  const [next, setNext] = useState(1);
  const update = (key: number, patch: Partial<InputRow>) => setRows((r) => r.map((row) => (row.key === key ? { ...row, ...patch } : row)));
  const open = observations.filter((o) => o.status !== "resolved");

  return (
    <FormDialog
      title={`Record field work — ${cycle.code}`}
      submitLabel="Record"
      onClose={onClose}
      onSubmit={async (f) => {
        const occurred = text(f, "occurred_at");
        await api.POST("/farms/{farm}/crop-operations", {
          params: { path: { farm: farmId }, header: { "Idempotency-Key": idempotencyKey() } },
          body: {
            cycle_id: cycle.id!,
            type: String(f.get("type")) as (typeof OPERATION_TYPES)[number],
            occurred_at: occurred ? new Date(occurred).toISOString() : undefined,
            observation_id: text(f, "observation_id"),
            notes: text(f, "notes"),
            labour_hours: num(f, "labour_hours"),
            cost_amount: seesMoney ? num(f, "cost_amount") : undefined,
            inputs: rows
              .filter((r) => r.product_name.trim() !== "")
              .map((r) => ({
                product_name: r.product_name.trim(),
                quantity: Number(r.quantity),
                unit: r.unit,
                withholding_days: r.withholding_days === "" ? null : Number(r.withholding_days),
              })),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="type">Work</Label>
              <Select id="type" name="type" required defaultValue="weeding">
                {OPERATION_TYPES.map((t) => (
                  <option key={t} value={t}>
                    {humanize(t)}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="occurred_at">When</Label>
              <Input id="occurred_at" name="occurred_at" type="datetime-local" max={nowLocal()} defaultValue={nowLocal()} />
              <FieldError>{error?.fieldError("occurred_at")}</FieldError>
            </div>
          </div>
          {open.length > 0 ? (
            <div>
              <Label htmlFor="observation_id">Treats</Label>
              <Select id="observation_id" name="observation_id" defaultValue="">
                <option value="">—</option>
                {open.map((o) => (
                  <option key={o.id} value={o.id}>
                    {o.title} ({o.severity})
                  </option>
                ))}
              </Select>
            </div>
          ) : null}

          <fieldset className="space-y-2">
            <legend className="text-sm font-medium">Inputs used</legend>
            {rows.map((r, i) => (
              <div key={r.key} className="grid grid-cols-[1fr_5rem_7rem_5rem_auto] items-end gap-2">
                <div>
                  <Label htmlFor={`p${r.key}`} className="text-xs">
                    Product
                  </Label>
                  <Input id={`p${r.key}`} value={r.product_name} onChange={(e) => update(r.key, { product_name: e.target.value })} required />
                </div>
                <div>
                  <Label htmlFor={`q${r.key}`} className="text-xs">
                    Qty
                  </Label>
                  <Input id={`q${r.key}`} type="number" min="0" step="0.001" value={r.quantity} onChange={(e) => update(r.key, { quantity: e.target.value })} required />
                </div>
                <div>
                  <Label htmlFor={`u${r.key}`} className="text-xs">
                    Unit
                  </Label>
                  <UnitSelect id={`u${r.key}`} value={r.unit} onChange={(e) => update(r.key, { unit: e.target.value })} dimensions={["mass", "volume", "count"]} />
                </div>
                <div>
                  <Label htmlFor={`w${r.key}`} className="text-xs" title="Withholding (pre-harvest) days">
                    PHI days
                  </Label>
                  <Input id={`w${r.key}`} type="number" min="0" step="1" value={r.withholding_days} onChange={(e) => update(r.key, { withholding_days: e.target.value })} />
                </div>
                <Button type="button" variant="ghost" size="icon" aria-label="Remove input" onClick={() => setRows((all) => all.filter((x) => x.key !== r.key))}>
                  <Trash2 />
                </Button>
                <FieldError>{error?.fieldError(`inputs.${i}.quantity`) ?? error?.fieldError(`inputs.${i}.product_name`)}</FieldError>
              </div>
            ))}
            <Button
              type="button"
              size="sm"
              variant="secondary"
              onClick={() => {
                setRows((r) => [...r, { key: next, product_name: "", quantity: "", unit: "kg", withholding_days: "" }]);
                setNext((n) => n + 1);
              }}
            >
              <Plus /> Add input
            </Button>
            <p className="text-xs text-muted">PHI: days to wait before harvest after this input (from the label). Harvests are blocked until the longest one has passed.</p>
          </fieldset>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="labour_hours">Labour (hours)</Label>
              <Input id="labour_hours" name="labour_hours" type="number" min="0" step="0.25" />
            </div>
            {seesMoney ? (
              <div>
                <Label htmlFor="cost_amount">Cost</Label>
                <Input id="cost_amount" name="cost_amount" type="number" min="0" step="1" />
              </div>
            ) : null}
          </div>
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" rows={2} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function ObservationDialog({ farmId, cycle, onClose, onDone }: Base) {
  return (
    <FormDialog
      title={`Report a finding — ${cycle.code}`}
      submitLabel="Report"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/crop-observations", {
          params: { path: { farm: farmId } },
          body: {
            cycle_id: cycle.id!,
            kind: String(f.get("kind")) as (typeof OBSERVATION_KINDS)[number],
            severity: String(f.get("severity")) as (typeof SEVERITIES)[number],
            title: String(f.get("title")),
            description: text(f, "description"),
            affected_pct: num(f, "affected_pct"),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="kind">Kind</Label>
              <Select id="kind" name="kind" defaultValue="pest">
                {OBSERVATION_KINDS.map((k) => (
                  <option key={k} value={k}>
                    {humanize(k)}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="severity">Severity</Label>
              <Select id="severity" name="severity" defaultValue="medium">
                {SEVERITIES.map((s) => (
                  <option key={s} value={s}>
                    {humanize(s)}
                  </option>
                ))}
              </Select>
            </div>
          </div>
          <div>
            <Label htmlFor="title">What did you see?</Label>
            <Input id="title" name="title" required maxLength={150} placeholder="e.g. Fall armyworm" aria-invalid={!!error?.fieldError("title")} />
            <FieldError>{error?.fieldError("title")}</FieldError>
          </div>
          <div>
            <Label htmlFor="affected_pct">Plants affected (%)</Label>
            <Input id="affected_pct" name="affected_pct" type="number" min="0" max="100" step="1" />
          </div>
          <div>
            <Label htmlFor="description">Details</Label>
            <Textarea id="description" name="description" rows={3} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

/** Harvest; inside a withholding period an approver can override with a reason. */
export function HarvestDialog({ farmId, cycle, onClose, onDone, canOverride }: Base & { canOverride: boolean }) {
  const [blocked, setBlocked] = useState<string | null>(null);
  return (
    <FormDialog
      title={`Record harvest — ${cycle.code}`}
      description="Creates a harvest batch in traceability, derived from this cycle's crop lot."
      submitLabel="Record harvest"
      onClose={onClose}
      onSubmit={async (f) => {
        try {
          await api.POST("/farms/{farm}/harvests", {
            params: { path: { farm: farmId }, header: { "Idempotency-Key": idempotencyKey() } },
            body: {
              cycle_id: cycle.id!,
              harvested_on: String(f.get("harvested_on")),
              quantity: Number(f.get("quantity")),
              unit: String(f.get("unit")),
              quality_grade: text(f, "quality_grade"),
              moisture_pct: num(f, "moisture_pct"),
              notes: text(f, "notes"),
              withholding_override_reason: text(f, "withholding_override_reason"),
            },
          });
          onDone();
        } catch (err) {
          if (err instanceof ApiError && err.code === "withholding_period") setBlocked(err.problem.title);
          throw err;
        }
      }}
    >
      {(error) => (
        <>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label htmlFor="harvested_on">Date</Label>
              <Input id="harvested_on" name="harvested_on" type="date" max={today()} defaultValue={today()} required />
              <FieldError>{error?.fieldError("harvested_on")}</FieldError>
            </div>
            <div>
              <Label htmlFor="quantity">Quantity</Label>
              <Input id="quantity" name="quantity" type="number" min="0" step="0.001" required aria-invalid={!!error?.fieldError("quantity")} />
              <FieldError>{error?.fieldError("quantity")}</FieldError>
            </div>
            <div>
              <Label htmlFor="unit">Unit</Label>
              <UnitSelect id="unit" name="unit" defaultValue={cycle.yield_unit ?? "kg"} dimensions={["mass", "count", "volume"]} />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="quality_grade">Grade</Label>
              <Input id="quality_grade" name="quality_grade" maxLength={20} placeholder="e.g. A" />
            </div>
            <div>
              <Label htmlFor="moisture_pct">Moisture (%)</Label>
              <Input id="moisture_pct" name="moisture_pct" type="number" min="0" max="100" step="0.1" />
            </div>
          </div>
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" rows={2} />
          </div>
          {blocked || cycle.safe_harvest_on ? (
            <div className="rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
              {blocked ?? `A treatment's withholding period runs until ${cycle.safe_harvest_on}.`}
              {canOverride ? (
                <div className="mt-2">
                  <Label htmlFor="withholding_override_reason">Override reason (only if residue-safe, e.g. a lab test)</Label>
                  <Textarea id="withholding_override_reason" name="withholding_override_reason" rows={2} maxLength={500} />
                </div>
              ) : (
                <p className="mt-1 text-xs text-muted">Only someone who verifies crop work can override it.</p>
              )}
            </div>
          ) : null}
        </>
      )}
    </FormDialog>
  );
}

export function TransplantDialog({ farmId, cycle, onClose, onDone }: Base) {
  return (
    <FormDialog
      title={`Transplant — ${cycle.code}`}
      description="Moves the seedlings to the plot and starts the crop lot."
      submitLabel="Transplant"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/crop-cycles/{cycle}/transplant", {
          params: { path: { farm: farmId, cycle: cycle.id! } },
          body: {
            planted_on: String(f.get("planted_on")),
            seedlings_germinated: num(f, "seedlings_germinated"),
            seedlings_transplanted: num(f, "seedlings_transplanted"),
            expected_harvest_on: text(f, "expected_harvest_on"),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="planted_on">Transplanted on</Label>
            <Input id="planted_on" name="planted_on" type="date" max={today()} defaultValue={today()} required />
            <FieldError>{error?.fieldError("planted_on")}</FieldError>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="seedlings_germinated">Seedlings germinated</Label>
              <Input id="seedlings_germinated" name="seedlings_germinated" type="number" min="0" step="1" defaultValue={cycle.nursery?.seedlings_germinated ?? ""} />
            </div>
            <div>
              <Label htmlFor="seedlings_transplanted">Seedlings transplanted</Label>
              <Input id="seedlings_transplanted" name="seedlings_transplanted" type="number" min="0" step="1" />
            </div>
          </div>
          <div>
            <Label htmlFor="expected_harvest_on">Expected harvest</Label>
            <Input id="expected_harvest_on" name="expected_harvest_on" type="date" defaultValue={cycle.expected_harvest_on ?? ""} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function CloseCycleDialog({ farmId, cycle, onClose, onDone }: Base) {
  return (
    <FormDialog
      title={`Close ${cycle.code}`}
      description="Closes the crop lot in traceability. A closed cycle cannot take new records."
      submitLabel="Close cycle"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/crop-cycles/{cycle}/close", {
          params: { path: { farm: farmId, cycle: cycle.id! } },
          body: { reason: String(f.get("reason")) as (typeof CLOSE_REASONS)[number], note: text(f, "note") },
        });
        onDone();
      }}
    >
      {() => (
        <>
          <div>
            <Label htmlFor="reason">Why</Label>
            <Select id="reason" name="reason" defaultValue={(cycle.actual_yield ?? 0) > 0 ? "harvested" : "failed"}>
              {CLOSE_REASONS.map((r) => (
                <option key={r} value={r}>
                  {humanize(r)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label htmlFor="note">Note</Label>
            <Textarea id="note" name="note" rows={2} maxLength={500} />
          </div>
        </>
      )}
    </FormDialog>
  );
}
