"use client";

import { useRouter } from "next/navigation";

import { Select } from "@/components/ui/input";
import { useWorkspaces } from "@/lib/api/hooks";

/** Switch between farms (and the platform workspace for admins). */
export function WorkspaceSwitcher({ current }: { current: string }) {
  const router = useRouter();
  const { data } = useWorkspaces();
  const workspaces = data?.workspaces ?? [];

  if (workspaces.length < 2) {
    const only = workspaces[0];
    return only ? <span className="truncate text-sm font-medium">{only.name}</span> : null;
  }

  return (
    <Select
      aria-label="Workspace"
      className="h-9 max-w-56"
      value={current}
      onChange={(e) => {
        const ws = workspaces.find((w) => w.id === e.target.value);
        if (!ws) return;
        router.push(ws.type === "platform" ? "/admin" : `/farms/${ws.id}`);   // farm or read-only support workspace
      }}
    >
      {workspaces.map((w) => (
        <option key={w.id} value={w.id}>
          {w.name}
        </option>
      ))}
    </Select>
  );
}
