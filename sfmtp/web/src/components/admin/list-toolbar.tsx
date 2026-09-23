"use client";

import { Search } from "lucide-react";

import { Input, Select } from "@/components/ui/input";

export function ListToolbar({
  q,
  onQ,
  placeholder,
  status,
  onStatus,
  statuses,
}: {
  q: string;
  onQ: (v: string) => void;
  placeholder: string;
  status?: string;
  onStatus?: (v: string) => void;
  statuses?: string[];
}) {
  return (
    <div className="mb-4 flex flex-wrap gap-2">
      <div className="relative w-full max-w-xs">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden />
        <Input aria-label="Search" placeholder={placeholder} className="pl-9" value={q} onChange={(e) => onQ(e.target.value)} />
      </div>
      {statuses && onStatus ? (
        <Select aria-label="Status" className="w-44" value={status} onChange={(e) => onStatus(e.target.value)}>
          <option value="">All statuses</option>
          {statuses.map((s) => (
            <option key={s} value={s}>
              {s.charAt(0).toUpperCase() + s.slice(1)}
            </option>
          ))}
        </Select>
      ) : null}
    </div>
  );
}
