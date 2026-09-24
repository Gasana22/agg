"use client";

import { useState } from "react";

import { Label, Select } from "@/components/ui/input";
import type { Animal } from "@/lib/livestock";

import { useActiveAnimals, useGroups } from "./queries";

/**
 * Who a record is about: fixed to one animal (from its profile), or chosen
 * here, either one animal or a whole group. Posts `animal_id` or `group_id`.
 */
export function SubjectPicker({ farmId, animal, allowGroup = true, error }: { farmId: string; animal?: Animal; allowGroup?: boolean; error?: string }) {
  const [mode, setMode] = useState<"animal" | "group">("animal");
  const animals = useActiveAnimals(farmId);
  const groups = useGroups(farmId);

  if (animal) {
    return (
      <>
        <input type="hidden" name="animal_id" value={animal.id} />
        <p className="text-sm text-muted">
          For <span className="font-medium text-foreground">{animal.label}</span>
        </p>
      </>
    );
  }

  return (
    <fieldset className="space-y-2">
      {allowGroup ? (
        <div className="flex gap-4 text-sm">
          {(["animal", "group"] as const).map((m) => (
            <label key={m} className="flex items-center gap-2">
              <input type="radio" checked={mode === m} onChange={() => setMode(m)} className="accent-[var(--primary)]" />
              {m === "animal" ? "One animal" : "A whole group"}
            </label>
          ))}
        </div>
      ) : null}
      {mode === "animal" ? (
        <div>
          <Label htmlFor="animal_id">Animal</Label>
          <Select id="animal_id" name="animal_id" required defaultValue="">
            <option value="">Choose…</option>
            {animals.data?.map((a) => (
              <option key={a.id} value={a.id}>
                {a.label} · {a.species?.name}
              </option>
            ))}
          </Select>
        </div>
      ) : (
        <div>
          <Label htmlFor="group_id">Group</Label>
          <Select id="group_id" name="group_id" required defaultValue="">
            <option value="">Choose…</option>
            {groups.data?.map((g) => (
              <option key={g.id} value={g.id}>
                {g.name} ({g.head_count ?? 0} animals{g.flock_size ? `, flock of ${g.flock_size}` : ""})
              </option>
            ))}
          </Select>
        </div>
      )}
      {error ? <p className="text-sm text-danger">{error}</p> : null}
    </fieldset>
  );
}

/** animal_id / group_id from a subject picker's form data. */
export function subjectOf(f: FormData): { animal_id?: string; group_id?: string } {
  const animal = String(f.get("animal_id") ?? "");
  const group = String(f.get("group_id") ?? "");
  return animal ? { animal_id: animal } : group ? { group_id: group } : {};
}
