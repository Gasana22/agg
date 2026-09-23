"use client";

import { Select } from "@/components/ui/input";

export const PERIODS = [
  { key: "today", label: "Today" },
  { key: "7d", label: "Last 7 days" },
  { key: "30d", label: "Last 30 days" },
  { key: "90d", label: "Last 90 days" },
  { key: "ytd", label: "Year to date" },
] as const;

export type PeriodKey = (typeof PERIODS)[number]["key"];

export function PeriodSelect({ value, onChange }: { value: PeriodKey; onChange: (p: PeriodKey) => void }) {
  return (
    <Select aria-label="Period" className="h-9 w-40" value={value} onChange={(e) => onChange(e.target.value as PeriodKey)}>
      {PERIODS.map((p) => (
        <option key={p.key} value={p.key}>
          {p.label}
        </option>
      ))}
    </Select>
  );
}
