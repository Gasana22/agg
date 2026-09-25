"use client";

import { useParams, useRouter } from "next/navigation";
import { useEffect } from "react";

import { AppShell } from "@/components/shell/app-shell";
import { ErrorNotice, Skeleton } from "@/components/ui/misc";
import { useWorkspaces, type Workspace } from "@/lib/api/hooks";
import type { PortalKind } from "@/lib/portal";

const NAV: Record<PortalKind, { key: string; label: string; path: string; icon: string }[]> = {
  supplier: [
    { key: "dashboard", label: "Dashboard", path: "", icon: "dashboard" },
    { key: "orders", label: "Purchase orders", path: "/orders", icon: "orders" },
    { key: "invoices", label: "Invoices & payments", path: "/invoices", icon: "invoices" },
    { key: "company", label: "Company details", path: "/company", icon: "company" },
  ],
  customer: [
    { key: "dashboard", label: "Dashboard", path: "", icon: "dashboard" },
    { key: "shop", label: "Shop", path: "/shop", icon: "shop" },
    { key: "orders", label: "My orders", path: "/orders", icon: "orders" },
    { key: "deliveries", label: "Deliveries", path: "/deliveries", icon: "shipments" },
    { key: "invoices", label: "Invoices", path: "/invoices", icon: "invoices" },
    { key: "purchases", label: "What I bought", path: "/purchases", icon: "trace" },
    { key: "company", label: "Company details", path: "/company", icon: "company" },
  ],
};

/** The portal workspace of a route, or undefined while loading or without access. */
export function usePortalWorkspace(kind: PortalKind, partyId: string) {
  const query = useWorkspaces();
  const workspace: Workspace | undefined = query.data?.workspaces.find((w) => w.type === kind && w.id === partyId);
  return { ...query, workspace };
}

/** Layout of the supplier and customer portals (docs/01 §8: one route group per workspace). */
export function PortalLayout({ kind, children }: { kind: PortalKind; children: React.ReactNode }) {
  const { partyId } = useParams<{ partyId: string }>();
  const router = useRouter();
  const { workspace, data, error, isLoading } = usePortalWorkspace(kind, partyId);

  useEffect(() => {
    if (data?.mfaRequired && !data.mfaEnabled) router.replace("/mfa/setup");
  }, [data, router]);

  if (isLoading) {
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }
  if (error) return <div className="p-6"><ErrorNotice error={error} /></div>;
  if (!workspace) {
    return (
      <div className="grid min-h-dvh place-items-center p-6 text-center">
        <div>
          <p className="text-lg font-medium">Portal not found</p>
          <p className="mt-1 text-sm text-muted">It may not exist, or your access may have been stopped by the farm.</p>
        </div>
      </div>
    );
  }

  const base = `/${kind}/${partyId}`;
  const nav = NAV[kind].map((n) => ({ key: n.key, label: n.label, href: base + n.path, icon: n.icon }));
  const farms = workspace.farms ?? [];

  return (
    <AppShell
      workspaceId={`${kind}:${partyId}`}
      nav={nav}
      banner={
        <div className="border-b border-primary/20 bg-primary-soft px-6 py-2 text-sm">
          {kind === "supplier" ? "Supplier portal" : "Customer portal"} for {workspace.name}
          {farms.length > 0 ? ` · ${farms.length === 1 ? farms[0].name : `${farms.length} farms`}` : ""}
        </div>
      }
    >
      {children}
    </AppShell>
  );
}

/** The KPI tile used on both portal dashboards. */
export function Tile({ label, value, hint }: { label: string; value: React.ReactNode; hint?: string }) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-4">
      <p className="text-sm text-muted">{label}</p>
      <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
      {hint ? <p className="mt-1 text-xs text-muted">{hint}</p> : null}
    </div>
  );
}
