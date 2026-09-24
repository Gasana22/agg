"use client";

import { Select } from "@/components/ui/input";

import { useUnits } from "./queries";

/** Units from the global catalogue, optionally limited to some dimensions. */
export function UnitSelect({ dimensions, ...props }: React.SelectHTMLAttributes<HTMLSelectElement> & { dimensions?: string[] }) {
  const units = useUnits();
  const list = (units.data ?? []).filter((u) => !dimensions || dimensions.includes(u.dimension ?? ""));
  return (
    <Select {...props}>
      {list.length === 0 ? <option value={String(props.defaultValue ?? "kg")}>{String(props.defaultValue ?? "kg")}</option> : null}
      {list.map((u) => (
        <option key={u.code} value={u.code}>
          {u.name} ({u.code})
        </option>
      ))}
    </Select>
  );
}
