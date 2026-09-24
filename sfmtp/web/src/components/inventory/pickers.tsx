"use client";

import { useQuery } from "@tanstack/react-query";
import { Plus, Trash2 } from "lucide-react";
import { useMemo, useState } from "react";

import { useActiveAnimals, useGroups } from "@/components/livestock/queries";
import { Button } from "@/components/ui/button";
import { FieldError, Input, Label, Select } from "@/components/ui/input";
import { useOpenCycles } from "@/components/workforce/queries";
import { api } from "@/lib/api/client";
import type { ApiError } from "@/lib/api/errors";
import type { InventoryItem } from "@/lib/inventory";
import { can, type Permissions } from "@/lib/permissions";

import { useStores } from "./queries";

export const SUBJECT_LABEL: Record<string, string> = {
  general: "General use",
  crop_cycle: "Crop cycle",
  plot: "Plot",
  location: "Location",
  animal_group: "Animal group",
  animal: "Animal",
};

/** Subject types a member can pick: crop work needs crops access, animals need livestock access. */
export function subjectTypes(perms: Permissions | undefined): string[] {
  const types = ["general", "plot", "location"];
  if (can(perms, "crops.plans.view|crops.operations.view|crops.harvest.view")) types.splice(1, 0, "crop_cycle");
  if (can(perms, "livestock.animals.view")) types.push("animal_group", "animal");
  return types;
}

/** Where stock goes: a type and, unless general, the record. */
export function SubjectPicker({
  farmId,
  perms,
  type,
  onType,
  id,
  onId,
  error,
}: {
  farmId: string;
  perms: Permissions | undefined;
  type: string;
  onType: (t: string) => void;
  id: string;
  onId: (id: string) => void;
  error?: ApiError | null;
}) {
  const cycles = useOpenCycles(farmId, type === "crop_cycle");
  const groups = useGroups(farmId, type === "animal_group");
  const animals = useActiveAnimals(farmId, type === "animal");
  const structure = useQuery({
    queryKey: ["structure", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/structure", { params: { path: { farm: farmId } } })).data!.data!,
    enabled: type === "plot" || type === "location",
  });

  const options = useMemo((): { id: string; label: string }[] => {
    switch (type) {
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
  }, [type, cycles.data, groups.data, animals.data, structure.data]);

  return (
    <div className="grid grid-cols-2 gap-3">
      <div>
        <Label htmlFor="subject_type">For</Label>
        <Select id="subject_type" value={type} onChange={(e) => { onType(e.target.value); onId(""); }}>
          {subjectTypes(perms).map((t) => (
            <option key={t} value={t}>
              {SUBJECT_LABEL[t]}
            </option>
          ))}
        </Select>
      </div>
      {type !== "general" ? (
        <div>
          <Label htmlFor="subject_id">{SUBJECT_LABEL[type]}</Label>
          <Select id="subject_id" value={id} required onChange={(e) => onId(e.target.value)} aria-invalid={!!error?.fieldError("subject_id")}>
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
    </div>
  );
}

/**
 * A store picker. Uncontrolled use keeps its own state, because a
 * `defaultValue` is lost when the options arrive after the first render.
 */
export function StoreSelect({ farmId, id, name, label = "Store", error, defaultValue, value, onChange, ...props }: React.SelectHTMLAttributes<HTMLSelectElement> & { farmId: string; label?: string; error?: string }) {
  const stores = useStores(farmId);
  const [own, setOwn] = useState(String(defaultValue ?? ""));
  return (
    <div>
      <Label htmlFor={id}>{label}</Label>
      <Select
        id={id}
        name={name}
        aria-invalid={!!error}
        value={value ?? own}
        onChange={(e) => {
          setOwn(e.target.value);
          onChange?.(e);
        }}
        {...props}
      >
        <option value="">Choose…</option>
        {(stores.data ?? []).map((l) => (
          <option key={l.id} value={l.id}>
            {l.code} {l.name}
          </option>
        ))}
      </Select>
      <FieldError>{error}</FieldError>
    </div>
  );
}

export type Line = { key: string; item_id: string; quantity: string; unit_price?: string; lot_id?: string };
export const newLine = (): Line => ({ key: crypto.randomUUID(), item_id: "", quantity: "" });

/** Item + quantity rows (and a price, when asked), with add and remove. */
export function LinesEditor({
  items,
  lines,
  onChange,
  withPrice = false,
  error,
}: {
  items: InventoryItem[];
  lines: Line[];
  onChange: (lines: Line[]) => void;
  withPrice?: boolean;
  error?: ApiError | null;
}) {
  const set = (key: string, patch: Partial<Line>) => onChange(lines.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  return (
    <fieldset className="space-y-2">
      <legend className="mb-1.5 text-sm font-medium">Items</legend>
      {lines.map((l, i) => {
        const unit = items.find((it) => it.id === l.item_id)?.unit;
        const err = error?.fieldError(`lines.${i}.item_id`) ?? error?.fieldError(`lines.${i}.quantity`) ?? error?.fieldError(`lines.${i}.unit_price`);
        return (
          <div key={l.key}>
            <div className="flex items-start gap-2">
              <Select aria-label={`Item ${i + 1}`} className="min-w-0 flex-1" value={l.item_id} required onChange={(e) => set(l.key, { item_id: e.target.value })}>
                <option value="">Item…</option>
                {items.map((it) => (
                  <option key={it.id} value={it.id}>
                    {it.name}
                  </option>
                ))}
              </Select>
              <div className="relative w-28">
                <Input aria-label={`Quantity ${i + 1}`} type="number" step="any" min="0" required value={l.quantity} onChange={(e) => set(l.key, { quantity: e.target.value })} className="pr-9" />
                <span className="pointer-events-none absolute right-2 top-2.5 text-xs text-muted">{unit}</span>
              </div>
              {withPrice ? (
                <Input aria-label={`Unit price ${i + 1}`} type="number" step="any" min="0" required placeholder="Price" value={l.unit_price ?? ""} onChange={(e) => set(l.key, { unit_price: e.target.value })} className="w-28" />
              ) : null}
              <Button type="button" variant="ghost" size="icon" aria-label={`Remove item ${i + 1}`} disabled={lines.length === 1} onClick={() => onChange(lines.filter((x) => x.key !== l.key))}>
                <Trash2 />
              </Button>
            </div>
            <FieldError>{err}</FieldError>
          </div>
        );
      })}
      <Button type="button" variant="secondary" size="sm" onClick={() => onChange([...lines, newLine()])}>
        <Plus /> Add item
      </Button>
      <FieldError>{error?.fieldError("lines")}</FieldError>
    </fieldset>
  );
}
