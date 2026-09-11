"use client";

import { useAuth } from "@/lib/auth-context";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { formatRole } from "@/lib/utils";

export default function DashboardPage() {
  const { user, platformRoles } = useAuth();
  const farms = user?.farms ?? [];

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Welcome, {user?.name}</h1>
        <p className="text-sm text-muted-foreground">
          {platformRoles.length > 0
            ? `Platform role: ${platformRoles.map(formatRole).join(", ")}.`
            : farms.length > 0
              ? `Working on ${farms.length} farm${farms.length > 1 ? "s" : ""}.`
              : "No role assigned yet."}
        </p>
      </div>

      {farms.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Your farms</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-2 pb-6 text-sm">
            {farms.map((farm) => (
              <div key={farm.id} className="flex items-center justify-between">
                <span>{farm.name}</span>
                <span className="text-muted-foreground">
                  {formatRole(farm.pivot.role_on_farm)}
                </span>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Platform skeleton</CardTitle>
        </CardHeader>
        <CardContent className="pb-6 text-sm text-muted-foreground">
          Authentication and RBAC are wired end-to-end, with a clear split
          between platform-wide roles (system_administrator, supplier,
          customer) and per-farm roles (farm_owner, farm_manager, agronomist,
          livestock_manager, store_manager, accountant, field_worker) — the
          same person can hold a different role on each farm. Each module in
          the sidebar is a placeholder route ready for its own dashboard,
          forms, and API integration as it is built out.
        </CardContent>
      </Card>
    </div>
  );
}
