"use client";

import type { ReactNode } from "react";
import { useQuery } from "@tanstack/react-query";

import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { getFarmDashboard, getAdminDashboard } from "@/lib/modules/reports";
import { useAuth } from "@/lib/auth-context";
import { useFarm } from "@/lib/farm-context";
import { formatRole } from "@/lib/utils";

function SummaryRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex items-center justify-between py-1 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  );
}

const ALERT_LABELS: Record<string, string> = {
  low_stock_items_count: "Inventory items low on stock",
  assets_under_maintenance_count: "Assets under maintenance",
  purchase_orders_outstanding_count: "Purchase orders outstanding",
  animal_health_follow_ups_due_count: "Animal health follow-ups due soon",
  asset_services_due_count: "Asset services due soon",
};

export default function ReportsPage() {
  const { platformRoles } = useAuth();
  const { currentFarmId, currentFarm } = useFarm();
  const isSystemAdministrator = platformRoles.includes("system_administrator");

  const { data: dash } = useQuery({
    queryKey: ["farm-dashboard", currentFarmId],
    queryFn: () => getFarmDashboard(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const { data: admin } = useQuery({
    queryKey: ["admin-dashboard"],
    queryFn: () => getAdminDashboard(),
    enabled: isSystemAdministrator,
  });

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Reports &amp; Analytics</h1>
        <p className="text-sm text-muted-foreground">
          A deeper breakdown than the Overview page — full counts, not just what needs attention.
        </p>
      </div>

      {currentFarmId && dash && (
        <Card>
          <CardHeader>
            <CardTitle>{currentFarm?.name ?? "Farm"} breakdown</CardTitle>
          </CardHeader>
          <CardContent className="grid grid-cols-1 gap-6 pb-6 md:grid-cols-4">
            <div>
              <h3 className="mb-2 text-sm font-semibold">Structure</h3>
              <SummaryRow label="Blocks" value={dash.structure.blocks_count} />
              <SummaryRow label="Sections" value={dash.structure.sections_count} />
              <SummaryRow label="Plots" value={dash.structure.plots_count} />
            </div>
            <div>
              <h3 className="mb-2 text-sm font-semibold">Workers</h3>
              <SummaryRow label="Active" value={dash.workers.active_count} />
              <SummaryRow label="Checked in today" value={dash.workers.checked_in_today} />
            </div>
            <div>
              <h3 className="mb-2 text-sm font-semibold">Crops</h3>
              <SummaryRow label="Active seasons" value={dash.crops.active_seasons_count} />
              <SummaryRow label="Harvests this month" value={dash.crops.harvests_this_month_count} />
            </div>
            <div>
              <h3 className="mb-2 text-sm font-semibold">Livestock</h3>
              {Object.entries(dash.livestock.animals_by_status).map(([status, count]) => (
                <SummaryRow key={status} label={formatRole(status)} value={count} />
              ))}
              <SummaryRow label="Production records this month" value={dash.livestock.production_records_this_month_count} />
            </div>
          </CardContent>
        </Card>
      )}

      {currentFarmId && dash && (
        <Card>
          <CardHeader>
            <CardTitle>Alerts</CardTitle>
          </CardHeader>
          <CardContent className="pb-6">
            {Object.values(dash.alerts).every((v) => v === 0) ? (
              <p className="text-sm text-muted-foreground">Nothing needs attention right now.</p>
            ) : (
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {Object.entries(dash.alerts).map(([key, count]) => (
                  <div key={key} className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">{ALERT_LABELS[key] ?? formatRole(key)}</span>
                    <Badge variant={count > 0 ? "warning" : "success"}>{count}</Badge>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {!currentFarmId && !isSystemAdministrator && (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Select a farm to see its reports.
          </CardContent>
        </Card>
      )}

      {isSystemAdministrator && admin && (
        <>
          <Card>
            <CardHeader>
              <CardTitle>Platform overview</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-6 pb-6 md:grid-cols-3">
              <div>
                <h3 className="mb-2 text-sm font-semibold">Farms</h3>
                <SummaryRow label="Total" value={admin.farms.total} />
                <SummaryRow label="Active" value={admin.farms.active} />
              </div>
              <div>
                <h3 className="mb-2 text-sm font-semibold">Users</h3>
                <SummaryRow label="Total" value={admin.users.total} />
                {Object.entries(admin.users.by_platform_role).map(([role, count]) => (
                  <SummaryRow key={role} label={formatRole(role)} value={count} />
                ))}
              </div>
              <div>
                <h3 className="mb-2 text-sm font-semibold">Trace batches</h3>
                {Object.entries(admin.trace_batches).map(([status, count]) => (
                  <SummaryRow key={status} label={formatRole(status)} value={count} />
                ))}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Recent farms</CardTitle>
            </CardHeader>
            <CardContent className="pb-6">
              {admin.recent_farms.length === 0 ? (
                <p className="text-sm text-muted-foreground">No farms yet.</p>
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Name</TableHead>
                      <TableHead>Owner</TableHead>
                      <TableHead>Created</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {admin.recent_farms.map((farm) => (
                      <TableRow key={farm.id}>
                        <TableCell className="font-medium">{farm.name}</TableCell>
                        <TableCell className="text-muted-foreground">{farm.owner ?? "—"}</TableCell>
                        <TableCell className="text-muted-foreground">
                          {new Date(farm.created_at).toLocaleDateString()}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}
