"use client";

import { useRouter } from "next/navigation";

import { Select } from "@/components/ui/input";
import { useWorkspaces, type Workspace } from "@/lib/api/hooks";

/** The switcher value of a workspace: portals are `supplier:{party}` / `customer:{party}`. */
export const valueOf = (w: Pick<Workspace, "type" | "id">) => (w.type === "supplier" || w.type === "customer" ? `${w.type}:${w.id}` : w.id);

const ALL_FARMS = "__all_farms";

/**
 * Switch between farms, portals and (for admins) the platform workspace.
 * Portal workspaces share their party's id, so their value carries the kind.
 */
export function WorkspaceSwitcher({ current }: { current: string }) {
  const router = useRouter();
  const { data } = useWorkspaces();
  const workspaces = data?.workspaces ?? [];

  const farmCount = workspaces.filter((w) => w.type === "farm").length;

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
        if (e.target.value === ALL_FARMS) {
          router.push("/farms");
          return;
        }
        const ws = workspaces.find((w) => valueOf(w) === e.target.value);
        if (!ws) return;
        if (ws.type === "platform") router.push("/admin");
        else if (ws.type === "supplier" || ws.type === "customer") router.push(`/${ws.type}/${ws.id}`);
        else router.push(`/farms/${ws.id}`);   // farm or read-only support workspace
      }}
    >
      {workspaces.map((w) => (
        <option key={valueOf(w)} value={valueOf(w)}>
          {w.type === "supplier" || w.type === "customer" ? `${w.name} (${w.type} portal)` : w.name}
        </option>
      ))}
      {farmCount > 1 ? <option value={ALL_FARMS}>All my farms…</option> : null}
    </Select>
  );
}
