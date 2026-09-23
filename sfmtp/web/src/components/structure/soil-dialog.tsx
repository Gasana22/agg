"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { humanize } from "@/lib/format";
import { type Plot, SOIL_TEXTURES } from "@/lib/structure";

const NUMBERS: { key: string; label: string; step: string; max?: number }[] = [
  { key: "ph", label: "pH", step: "0.1", max: 14 },
  { key: "organic_matter_pct", label: "Organic matter (%)", step: "0.1", max: 100 },
  { key: "nitrogen_mg_kg", label: "Nitrogen (mg/kg)", step: "0.1" },
  { key: "phosphorus_mg_kg", label: "Phosphorus (mg/kg)", step: "0.1" },
  { key: "potassium_mg_kg", label: "Potassium (mg/kg)", step: "0.1" },
  { key: "ec_ds_m", label: "EC (dS/m)", step: "0.01" },
];

/** Record a soil test for a plot. */
export function SoilDialog({ farmId, plot, onClose, onSaved }: { farmId: string; plot: Plot | null; onClose: () => void; onSaved: () => void }) {
  const [error, setError] = useState<ApiError | null>(null);
  if (!plot) return null;
  const soil = (plot.soil_profile ?? {}) as Record<string, string | number | null>;

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    const f = new FormData(e.currentTarget);
    const body: Record<string, string | number | null> = {};
    for (const [key, raw] of f.entries()) {
      const v = String(raw).trim();
      body[key] = v === "" ? null : NUMBERS.some((n) => n.key === key) || key === "depth_cm" ? Number(v) : v;
    }
    try {
      await api.PUT("/farms/{farm}/structure/plots/{plot}/soil", { params: { path: { farm: farmId, plot: plot!.id! } }, body });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  return (
    <Dialog open onClose={onClose} title={`Soil — ${plot.code}`} description="Replaces the plot's current soil profile.">
      <form onSubmit={submit} className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label htmlFor="texture">Texture</Label>
            <Select id="texture" name="texture" defaultValue={(soil.texture as string) ?? ""}>
              <option value="">—</option>
              {SOIL_TEXTURES.map((t) => (
                <option key={t} value={t}>
                  {humanize(t)}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label htmlFor="drainage">Drainage</Label>
            <Select id="drainage" name="drainage" defaultValue={(soil.drainage as string) ?? ""}>
              <option value="">—</option>
              {["poor", "moderate", "good", "excessive"].map((d) => (
                <option key={d} value={d}>
                  {humanize(d)}
                </option>
              ))}
            </Select>
          </div>
          {NUMBERS.map((n) => (
            <div key={n.key}>
              <Label htmlFor={n.key}>{n.label}</Label>
              <Input id={n.key} name={n.key} type="number" min="0" max={n.max} step={n.step} defaultValue={soil[n.key] ?? ""} aria-invalid={!!error?.fieldError(n.key)} />
              <FieldError>{error?.fieldError(n.key)}</FieldError>
            </div>
          ))}
          <div>
            <Label htmlFor="depth_cm">Sample depth (cm)</Label>
            <Input id="depth_cm" name="depth_cm" type="number" min="0" step="1" defaultValue={soil.depth_cm ?? ""} />
          </div>
          <div>
            <Label htmlFor="tested_on">Tested on</Label>
            <Input id="tested_on" name="tested_on" type="date" defaultValue={(soil.tested_on as string) ?? ""} aria-invalid={!!error?.fieldError("tested_on")} />
            <FieldError>{error?.fieldError("tested_on")}</FieldError>
          </div>
        </div>
        <div>
          <Label htmlFor="laboratory">Laboratory</Label>
          <Input id="laboratory" name="laboratory" maxLength={150} defaultValue={(soil.laboratory as string) ?? ""} />
        </div>
        <div>
          <Label htmlFor="notes">Notes</Label>
          <Textarea id="notes" name="notes" rows={2} defaultValue={(soil.notes as string) ?? ""} />
        </div>
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit">Save soil test</Button>
        </div>
      </form>
    </Dialog>
  );
}
