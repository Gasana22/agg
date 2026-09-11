"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { AlertTriangle, Sprout, Beef, Users, Boxes } from "lucide-react";

import { useAuth } from "@/lib/auth-context";
import { useFarm } from "@/lib/farm-context";
import { getFarmDashboard } from "@/lib/modules/reports";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { formatRole } from "@/lib/utils";

function StatCard({
  icon: Icon,
  label,
  value,
  sub,
}: {
  icon: React.ElementType;
  label: string;
  value: React.ReactNode;
  sub?: string;
}) {
  return (
    <Card>
      <CardContent className="flex items-center gap-4 py-6">
        <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
          <Icon className="size-5" />
        </div>
        <div>
          <p className="text-2xl font-semibold leading-none">{value}</p>
          <p className="mt-1 text-sm text-muted-foreground">{label}</p>
          {sub && <p className="text-xs text-muted-foreground">{sub}</p>}
        </div>
      </CardContent>
    </Card>
  );
}

export default function DashboardPage() {
  const { user, platformRoles } = useAuth();
  const { currentFarm, currentFarmId, farms, isLoading: farmsLoading } = useFarm();

  const { data: dash, isLoading: dashLoading } = useQuery({
    queryKey: ["farm-dashboard", currentFarmId],
    queryFn: () => getFarmDashboard(currentFarmId!),
    enabled: !!currentFarmId,
  });

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Welcome, {user?.name}</h1>
        <p className="text-sm text-muted-foreground">
          {platformRoles.length > 0
            ? `Platform role: ${platformRoles.map(formatRole).join(", ")}.`
            : currentFarm
              ? `Viewing ${currentFarm.name}.`
              : "No role assigned yet."}
        </p>
      </div>

      {!farmsLoading && farms.length === 0 && (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            You&apos;re not a member of any farm yet.{" "}
            <Link href="/dashboard/farms" className="text-primary underline-offset-4 hover:underline">
              Create one
            </Link>{" "}
            to get started.
          </CardContent>
        </Card>
      )}

      {currentFarmId && (dashLoading || !dash) ? (
        <p className="text-sm text-muted-foreground">Loading farm overview…</p>
      ) : dash ? (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
              icon={Users}
              label="Active workers"
              value={dash.workers.active_count}
              sub={`${dash.workers.checked_in_today} checked in today`}
            />
            <StatCard
              icon={Sprout}
              label="Active crop seasons"
              value={dash.crops.active_seasons_count}
              sub={`${dash.crops.harvests_this_month_count} harvests this month`}
            />
            <StatCard
              icon={Beef}
              label="Animals"
              value={Object.values(dash.livestock.animals_by_status).reduce((a, b) => a + b, 0)}
              sub={`${dash.livestock.production_records_this_month_count} records this month`}
            />
            <StatCard
              icon={Boxes}
              label="Farm structure"
              value={dash.structure.blocks_count}
              sub={`${dash.structure.sections_count} sections, ${dash.structure.plots_count} plots`}
            />
          </div>

          {Object.values(dash.alerts).some((v) => v > 0) && (
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <AlertTriangle className="size-4 text-amber-500" />
                  Attention needed
                </CardTitle>
              </CardHeader>
              <CardContent className="grid grid-cols-1 gap-2 pb-6 text-sm sm:grid-cols-2">
                {dash.alerts.low_stock_items_count > 0 && (
                  <Link href="/dashboard/inventory" className="hover:underline">
                    {dash.alerts.low_stock_items_count} inventory item(s) low on stock
                  </Link>
                )}
                {dash.alerts.assets_under_maintenance_count > 0 && (
                  <Link href="/dashboard/assets" className="hover:underline">
                    {dash.alerts.assets_under_maintenance_count} asset(s) under maintenance
                  </Link>
                )}
                {dash.alerts.purchase_orders_outstanding_count > 0 && (
                  <Link href="/dashboard/procurement" className="hover:underline">
                    {dash.alerts.purchase_orders_outstanding_count} purchase order(s) outstanding
                  </Link>
                )}
                {dash.alerts.animal_health_follow_ups_due_count > 0 && (
                  <Link href="/dashboard/livestock" className="hover:underline">
                    {dash.alerts.animal_health_follow_ups_due_count} animal health follow-up(s) due soon
                  </Link>
                )}
                {dash.alerts.asset_services_due_count > 0 && (
                  <Link href="/dashboard/assets" className="hover:underline">
                    {dash.alerts.asset_services_due_count} asset service(s) due soon
                  </Link>
                )}
              </CardContent>
            </Card>
          )}
        </>
      ) : null}
    </div>
  );
}
