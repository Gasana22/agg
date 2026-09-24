"use client";

import { useQuery } from "@tanstack/react-query";
import { useState } from "react";

import { FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { api, idempotencyKey } from "@/lib/api/client";
import type { CropCycle } from "@/lib/crops";

import { FormDialog, num, text } from "@/components/forms/form-dialog";
import { useFarmCrops, usePlots } from "./queries";
import { UnitSelect } from "@/components/forms/unit-select";

/** Start a crop cycle on a plot: direct sowing, or a nursery for transplanting. */
export function StartCycleDialog({ farmId, onClose, onStarted }: { farmId: string; onClose: () => void; onStarted: (cycle: CropCycle, warnings: string[]) => void }) {
  const crops = useFarmCrops(farmId);
  const structure = usePlots(farmId);
  const plans = useQuery({
    queryKey: ["crop-plans", farmId, "usable"],
    queryFn: async () => (await api.GET("/farms/{farm}/crop-plans", { params: { path: { farm: farmId }, query: { per_page: 100 } } })).data!.data!,
  });
  const seeds = useQuery({
    queryKey: ["batches", farmId, "seed_lot"],
    queryFn: async () => (await api.GET("/farms/{farm}/traceability/batches", { params: { path: { farm: farmId }, query: { "filter[kind]": "seed_lot", "filter[status]": "open", per_page: 100 } } })).data!.data!,
  });
  const [method, setMethod] = useState<"direct" | "transplant">("direct");
  const [cropId, setCropId] = useState("");
  const today = new Date().toISOString().slice(0, 10);

  const usablePlans = (plans.data ?? []).filter((p) => (p.status === "approved" || p.status === "active") && (!cropId || p.crop?.id === cropId));
  const crop = crops.data?.find((c) => c.id === cropId);

  return (
    <FormDialog
      title="Start a crop cycle"
      description="Its crop lot starts the traceability history of everything grown on the plot."
      submitLabel="Start cycle"
      onClose={onClose}
      onSubmit={async (f) => {
        const res = await api.POST("/farms/{farm}/crop-cycles", {
          params: { path: { farm: farmId }, header: { "Idempotency-Key": idempotencyKey() } },
          body: {
            plot_id: String(f.get("plot_id")),
            crop_id: String(f.get("crop_id")),
            plan_id: text(f, "plan_id"),
            planting_method: method,
            planted_on: method === "direct" ? text(f, "date") : null,
            sown_on: method === "transplant" ? text(f, "date") : null,
            seeds_sown: method === "transplant" ? num(f, "seeds_sown") : null,
            area_ha: num(f, "area_ha"),
            expected_yield: num(f, "expected_yield"),
            yield_unit: text(f, "yield_unit") ?? undefined,
            seed_batch_id: text(f, "seed_batch_id"),
            notes: text(f, "notes"),
          },
        });
        onStarted(res.data!.data!, (res.data!.meta?.warnings ?? []).map((w) => w.message ?? ""));
      }}
    >
      {(error) => (
        <>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="crop_id">Crop</Label>
              <Select id="crop_id" name="crop_id" required value={cropId} onChange={(e) => setCropId(e.target.value)}>
                <option value="">Choose…</option>
                {crops.data?.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.label}
                  </option>
                ))}
              </Select>
              <FieldError>{error?.fieldError("crop_id")}</FieldError>
            </div>
            <div>
              <Label htmlFor="plot_id">Plot</Label>
              <Select id="plot_id" name="plot_id" required defaultValue="">
                <option value="">Choose…</option>
                {structure.data?.plots?.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.code} · {p.name}
                    {p.effective_area_ha ? ` (${p.effective_area_ha} ha)` : ""}
                  </option>
                ))}
              </Select>
              <FieldError>{error?.fieldError("plot_id")}</FieldError>
            </div>
          </div>
          {crops.data?.length === 0 ? <p className="text-sm text-warning">Add crops to the farm list first (Setup tab).</p> : null}

          <div>
            <Label htmlFor="plan_id">Crop plan (optional)</Label>
            <Select id="plan_id" name="plan_id" defaultValue="">
              <option value="">No plan</option>
              {usablePlans.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.code} · {p.name} ({p.season?.name})
                </option>
              ))}
            </Select>
            <FieldError>{error?.fieldError("plan_id")}</FieldError>
          </div>

          <fieldset>
            <legend className="mb-1 text-sm font-medium">Planting</legend>
            <div className="flex gap-4 text-sm">
              {(["direct", "transplant"] as const).map((m) => (
                <label key={m} className="flex items-center gap-2">
                  <input type="radio" name="method" checked={method === m} onChange={() => setMethod(m)} className="accent-[var(--primary)]" />
                  {m === "direct" ? "Sown or planted directly" : "Raised in a nursery, then transplanted"}
                </label>
              ))}
            </div>
          </fieldset>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="date">{method === "direct" ? "Planted on" : "Sown in nursery on"}</Label>
              <Input id="date" name="date" type="date" max={today} defaultValue={today} required />
              <FieldError>{error?.fieldError(method === "direct" ? "planted_on" : "sown_on")}</FieldError>
            </div>
            {method === "transplant" ? (
              <div>
                <Label htmlFor="seeds_sown">Seeds sown</Label>
                <Input id="seeds_sown" name="seeds_sown" type="number" min="0" step="1" />
              </div>
            ) : (
              <div>
                <Label htmlFor="area_ha">Area (ha)</Label>
                <Input id="area_ha" name="area_ha" type="number" min="0" step="0.0001" placeholder="Plot area" />
                <FieldError>{error?.fieldError("area_ha")}</FieldError>
              </div>
            )}
          </div>
          {method === "transplant" ? (
            <div>
              <Label htmlFor="area_ha">Area to plant (ha)</Label>
              <Input id="area_ha" name="area_ha" type="number" min="0" step="0.0001" placeholder="Plot area" />
              <FieldError>{error?.fieldError("area_ha")}</FieldError>
            </div>
          ) : null}

          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="expected_yield">Expected yield</Label>
              <Input id="expected_yield" name="expected_yield" type="number" min="0" step="0.001" />
            </div>
            <div>
              <Label htmlFor="yield_unit">Unit</Label>
              <UnitSelect id="yield_unit" name="yield_unit" key={crop?.yield_unit ?? "kg"} defaultValue={crop?.yield_unit ?? "kg"} dimensions={["mass", "count", "volume"]} />
            </div>
          </div>

          <div>
            <Label htmlFor="seed_batch_id">Seed lot (traceability)</Label>
            <Select id="seed_batch_id" name="seed_batch_id" defaultValue="">
              <option value="">Not recorded</option>
              {seeds.data?.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.batch_code} · {b.name}
                </option>
              ))}
            </Select>
            <FieldError>{error?.fieldError("seed_batch_id")}</FieldError>
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
