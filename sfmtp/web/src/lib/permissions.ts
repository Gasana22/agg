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
  { key: "structure", label: "Farm map", href: (id) => `/farms/${id}/structure`, permission: "structure.view", icon: "map" },
  { key: "crops", label: "Crops", href: (id) => `/farms/${id}/crops`, permission: "crops.plans.view", icon: "crops" },
  { key: "livestock", label: "Livestock", href: (id) => `/farms/${id}/livestock`, permission: "livestock.animals.view", icon: "livestock" },
  { key: "traceability", label: "Traceability", href: (id) => `/farms/${id}/traceability`, permission: "trace.batches.view", icon: "trace" },
  { key: "members", label: "Members", href: (id) => `/farms/${id}/members`, permission: "members.view", icon: "members" },
  { key: "roles", label: "Roles & permissions", href: (id) => `/farms/${id}/roles`, permission: "roles.view", icon: "roles" },
  { key: "audit", label: "Audit log", href: (id) => `/farms/${id}/audit-log`, permission: "audit.view", icon: "audit" },
  { key: "settings", label: "Farm settings", href: (id) => `/farms/${id}/settings`, permission: "farm.profile.manage", icon: "settings" },
  { key: "billing", label: "Subscription", href: (id) => `/farms/${id}/billing`, permission: "billing.manage", icon: "billing" },
  { key: "support", label: "Help & support", href: (id) => `/farms/${id}/support`, permission: null, icon: "support" },
];

/** Platform administration navigation, filtered by platform capability (docs/04 §5). */
export const ADMIN_NAV: { key: string; label: string; href: string; capability: string; icon: string }[] = [
  { key: "dashboard", label: "Dashboard", href: "/admin", capability: "dashboard.view", icon: "dashboard" },
  { key: "farms", label: "Farms", href: "/admin/farms", capability: "farms.view", icon: "farms" },
  { key: "subscriptions", label: "Subscriptions", href: "/admin/subscriptions", capability: "subscriptions.view", icon: "billing" },
  { key: "plans", label: "Plans & pricing", href: "/admin/plans", capability: "plans.manage", icon: "plans" },
  { key: "support", label: "Support", href: "/admin/support", capability: "support.view", icon: "support" },
  { key: "users", label: "Users & staff", href: "/admin/users", capability: "users.view", icon: "users" },
  { key: "catalog", label: "Catalogues", href: "/admin/catalog", capability: "catalog.manage", icon: "catalog" },
  { key: "integrations", label: "Integrations", href: "/admin/integrations", capability: "integrations.manage", icon: "integrations" },
  { key: "settings", label: "Settings", href: "/admin/settings", capability: "settings.manage", icon: "settings" },
  { key: "system", label: "System", href: "/admin/system", capability: "system.view", icon: "system" },
];

export function visibleAdminNav(capabilities: Permissions | undefined) {
  return ADMIN_NAV.filter((item) => can(capabilities, item.capability));
}

export function can(permissions: Permissions | undefined, permission: string | null): boolean {
  return permission === null || Boolean(permissions && permission in permissions);
}

export function visibleNav(workspace: Pick<Workspace, "type" | "permissions" | "dashboards"> | undefined): NavItem[] {
  if (!workspace) return [];
  const readOnlySupport = workspace.type === "support";
  return FARM_NAV.filter((item) => {
    if (item.key === "dashboard") return (workspace.dashboards?.length ?? 0) > 0;
    if (item.key === "support" && readOnlySupport) return false;
    return can(workspace.permissions, item.permission);
  });
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
  if (first.type === "farm" || first.type === "support") {
    const dashboard = first.dashboards[0];
    return dashboard ? `/farms/${first.id}/dashboard/${dashboard}` : `/farms/${first.id}/traceability`;
  }
  return "/onboarding";
}
