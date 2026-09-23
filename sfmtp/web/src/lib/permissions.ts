import type { Workspace } from "@/lib/api/hooks";

export type Permissions = Record<string, string>;

export type NavItem = {
  key: string;
  label: string;
  href: (farmId: string) => string;
  /** Permission required to see the item; the server still enforces it. */
  permission: string | null;
  icon: string;
};

/**
 * Farm navigation, filtered by the member's permissions (docs/01 §8).
 * Later phases add Crops, Livestock, Workers, Inventory … here.
 */
export const FARM_NAV: NavItem[] = [
  { key: "dashboard", label: "Dashboard", href: (id) => `/farms/${id}`, permission: null, icon: "dashboard" },
  { key: "traceability", label: "Traceability", href: (id) => `/farms/${id}/traceability`, permission: "trace.batches.view", icon: "trace" },
  { key: "members", label: "Members", href: (id) => `/farms/${id}/members`, permission: "members.view", icon: "members" },
  { key: "roles", label: "Roles & permissions", href: (id) => `/farms/${id}/roles`, permission: "roles.view", icon: "roles" },
  { key: "audit", label: "Audit log", href: (id) => `/farms/${id}/audit-log`, permission: "audit.view", icon: "audit" },
  { key: "settings", label: "Farm settings", href: (id) => `/farms/${id}/settings`, permission: "farm.profile.manage", icon: "settings" },
];

export function can(permissions: Permissions | undefined, permission: string | null): boolean {
  return permission === null || Boolean(permissions && permission in permissions);
}

export function visibleNav(workspace: Pick<Workspace, "permissions" | "dashboards"> | undefined): NavItem[] {
  if (!workspace) return [];
  return FARM_NAV.filter((item) =>
    item.key === "dashboard" ? (workspace.dashboards?.length ?? 0) > 0 : can(workspace.permissions, item.permission),
  );
}

export const DASHBOARD_LABELS: Record<string, string> = {
  admin: "Platform",
  owner: "Owner",
  manager: "Manager",
  agronomist: "Agronomist",
  livestock: "Livestock",
  store: "Store",
  accountant: "Accountant",
  worker: "My work",
};

/** Where to send a user after sign-in (docs/05 §1: highest-precedence dashboard). */
export function homePath(workspaces: Workspace[], meta: { mfaRequired: boolean; mfaEnabled: boolean }): string {
  if (meta.mfaRequired && !meta.mfaEnabled) return "/mfa/setup";
  const first = workspaces[0];
  if (!first) return "/onboarding";
  if (first.type === "platform") return "/admin";
  if (first.type === "farm") {
    const dashboard = first.dashboards[0];
    return dashboard ? `/farms/${first.id}/dashboard/${dashboard}` : `/farms/${first.id}/traceability`;
  }
  return "/onboarding";
}
