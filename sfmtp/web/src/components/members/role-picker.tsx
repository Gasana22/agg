"use client";

import type { components } from "@/lib/api/schema";

type Role = components["schemas"]["Role"];

/** Checkbox list of roles; the owner role is never offered. */
export function RolePicker({ roles, selected, onChange }: { roles: Role[]; selected: string[]; onChange: (ids: string[]) => void }) {
  const offered = roles.filter((r) => r.key !== "owner");
  return (
    <fieldset className="space-y-1.5">
      <legend className="mb-1 text-sm font-medium">Roles</legend>
      {offered.length === 0 ? <p className="text-sm text-muted">No roles you can assign.</p> : null}
      {offered.map((r) => (
        <label key={r.id} className="flex items-start gap-2 rounded-md px-1 py-1 text-sm hover:bg-surface-muted">
          <input
            type="checkbox"
            className="mt-0.5 size-4 accent-[var(--primary)]"
            checked={selected.includes(r.id!)}
            onChange={(e) => onChange(e.target.checked ? [...selected, r.id!] : selected.filter((id) => id !== r.id))}
          />
          <span>
            <span className="font-medium">{r.name}</span>
            {r.description ? <span className="block text-xs text-muted">{r.description}</span> : null}
          </span>
        </label>
      ))}
    </fieldset>
  );
}

/** Roles a member holding only `members.invite_workers` may hand out: field-worker roles. */
export function assignableRoles(roles: Role[], canManageMembers: boolean): Role[] {
  if (canManageMembers) return roles.filter((r) => r.key !== "owner");
  return roles.filter((r) => r.grants && "worker.self" in r.grants);
}
