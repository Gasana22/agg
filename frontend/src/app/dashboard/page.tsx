"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import {
  AlertTriangle,
  Sprout,
  Beef,
  Users,
  Boxes,
  Wallet,
  Truck,
  Wrench,
  Building2,
  ArrowRight,
} from "lucide-react";

import { useAuth } from "@/lib/auth-context";
import { useFarm } from "@/lib/farm-context";
import { getFarmDashboard, getAdminDashboard, type FarmDashboard } from "@/lib/modules/reports";
import { getFinanceProfitAndLoss } from "@/lib/modules/finance";
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

function QuickLinks({ links }: { links: { href: string; label: string }[] }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Quick links</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-1 pb-6">
        {links.map((link) => (
          <Link
            key={link.href}
            href={link.href}
            className="flex items-center justify-between rounded-md px-2 py-1.5 text-sm hover:bg-accent"
          >
            {link.label}
            <ArrowRight className="size-4 text-muted-foreground" />
          </Link>
        ))}
      </CardContent>
    </Card>
  );
}

function AlertsCard({ dash }: { dash: FarmDashboard }) {
  const items = [
    { key: "low_stock_items_count", label: "Inventory items low on stock", href: "/dashboard/inventory" },
    { key: "assets_under_maintenance_count", label: "Asset(s) under maintenance", href: "/dashboard/assets" },
    {
      key: "purchase_orders_outstanding_count",
      label: "Purchase order(s) outstanding",
      href: "/dashboard/procurement",
    },
    {
      key: "animal_health_follow_ups_due_count",
      label: "Animal health follow-up(s) due soon",
      href: "/dashboard/livestock",
    },
    { key: "asset_services_due_count", label: "Asset service(s) due soon", href: "/dashboard/assets" },
  ] as const;

  const active = items.filter((item) => dash.alerts[item.key] > 0);
  if (active.length === 0) return null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <AlertTriangle className="size-4 text-amber-500" />
          Attention needed
        </CardTitle>
      </CardHeader>
      <CardContent className="grid grid-cols-1 gap-2 pb-6 text-sm sm:grid-cols-2">
        {active.map((item) => (
          <Link key={item.key} href={item.href} className="hover:underline">
            {dash.alerts[item.key]} {item.label}
          </Link>
        ))}
      </CardContent>
    </Card>
  );
}

function startOfMonth() {
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

export default function DashboardPage() {
  const { user, platformRoles } = useAuth();
  const { currentFarm, currentFarmId, farms, isLoading: farmsLoading } = useFarm();
  const isSystemAdministrator = platformRoles.includes("system_administrator");
  const myRole = currentFarm?.my_role ?? null;

  const { data: dash, isLoading: dashLoading } = useQuery({
    queryKey: ["farm-dashboard", currentFarmId],
    queryFn: () => getFarmDashboard(currentFarmId!),
    enabled: !!currentFarmId && !isSystemAdministrator,
  });

  const { data: admin, isLoading: adminLoading } = useQuery({
    queryKey: ["admin-dashboard"],
    queryFn: () => getAdminDashboard(),
    enabled: isSystemAdministrator,
  });

  const { data: pnl } = useQuery({
    queryKey: ["finance-profit-loss", currentFarmId, "overview"],
    queryFn: () => getFinanceProfitAndLoss(currentFarmId!, startOfMonth(), today()),
    enabled: !!currentFarmId && myRole === "accountant",
  });

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Welcome, {user?.name}</h1>
        <p className="text-sm text-muted-foreground">
          {isSystemAdministrator
            ? "Platform role: System Administrator."
            : currentFarm
              ? `${formatRole(myRole ?? "")} · ${currentFarm.name}.`
              : "No role assigned yet."}
        </p>
      </div>

      {isSystemAdministrator ? (
        adminLoading || !admin ? (
          <p className="text-sm text-muted-foreground">Loading platform overview…</p>
        ) : (
          <>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <StatCard icon={Building2} label="Farms" value={admin.farms.total} sub={`${admin.farms.active} active`} />
              <StatCard icon={Users} label="Users" value={admin.users.total} />
              {Object.entries(admin.users.by_platform_role)
                .slice(0, 2)
                .map(([role, count]) => (
                  <StatCard key={role} icon={Users} label={formatRole(role)} value={count} />
                ))}
            </div>
            <QuickLinks
              links={[
                { href: "/dashboard/reports", label: "Full platform breakdown" },
                { href: "/dashboard/farms", label: "Manage farms" },
                { href: "/dashboard/traceability", label: "Traceability batches" },
              ]}
            />
          </>
        )
      ) : !farmsLoading && farms.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            You&apos;re not a member of any farm yet.{" "}
            <Link href="/dashboard/farms" className="text-primary underline-offset-4 hover:underline">
              Create one
            </Link>{" "}
            to get started.
          </CardContent>
        </Card>
      ) : currentFarmId && (dashLoading || !dash) ? (
        <p className="text-sm text-muted-foreground">Loading farm overview…</p>
      ) : dash ? (
        <>
          {myRole === "accountant" ? (
            <>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard icon={Wallet} label="Income (this month)" value={pnl ? pnl.income_total : "…"} />
                <StatCard icon={Wallet} label="Expenses (this month)" value={pnl ? pnl.expenses_total : "…"} />
                <StatCard
                  icon={Wallet}
                  label="Net profit (this month)"
                  value={pnl ? pnl.net_profit : "…"}
                  sub={pnl && pnl.net_profit < 0 ? "Running at a loss this month" : undefined}
                />
              </div>
              <QuickLinks
                links={[
                  { href: "/dashboard/finance", label: "Finance" },
                  { href: "/dashboard/procurement", label: "Procurement payments" },
                  { href: "/dashboard/reports", label: "Full financial breakdown" },
                ]}
              />
            </>
          ) : myRole === "agronomist" ? (
            <>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <StatCard icon={Sprout} label="Active crop seasons" value={dash.crops.active_seasons_count} />
                <StatCard
                  icon={Sprout}
                  label="Harvests this month"
                  value={dash.crops.harvests_this_month_count}
                />
              </div>
              <QuickLinks
                links={[
                  { href: "/dashboard/crops", label: "Crop Management" },
                  { href: "/dashboard/activities", label: "Activity Tracking" },
                ]}
              />
            </>
          ) : myRole === "livestock_manager" ? (
            <>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {Object.entries(dash.livestock.animals_by_status).map(([status, count]) => (
                  <StatCard key={status} icon={Beef} label={`${formatRole(status)} animals`} value={count} />
                ))}
                <StatCard
                  icon={Beef}
                  label="Production records this month"
                  value={dash.livestock.production_records_this_month_count}
                />
              </div>
              <AlertsCard dash={dash} />
              <QuickLinks links={[{ href: "/dashboard/livestock", label: "Livestock" }]} />
            </>
          ) : myRole === "store_manager" ? (
            <>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard icon={Boxes} label="Items low on stock" value={dash.alerts.low_stock_items_count} />
                <StatCard
                  icon={Wrench}
                  label="Assets under maintenance"
                  value={dash.alerts.assets_under_maintenance_count}
                />
                <StatCard
                  icon={Truck}
                  label="Purchase orders outstanding"
                  value={dash.alerts.purchase_orders_outstanding_count}
                />
                <StatCard icon={Wrench} label="Asset services due soon" value={dash.alerts.asset_services_due_count} />
              </div>
              <QuickLinks
                links={[
                  { href: "/dashboard/inventory", label: "Inventory" },
                  { href: "/dashboard/procurement", label: "Procurement" },
                  { href: "/dashboard/assets", label: "Assets" },
                ]}
              />
            </>
          ) : myRole === "field_worker" ? (
            <>
              <Card>
                <CardContent className="py-8 text-center text-sm text-muted-foreground">
                  Check in, log your daily tasks, and record farm activity from Activity Tracking.
                </CardContent>
              </Card>
              <QuickLinks
                links={[
                  { href: "/dashboard/activities", label: "Activity Tracking" },
                  { href: "/dashboard/workers", label: "Workers" },
                ]}
              />
            </>
          ) : (
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
              <AlertsCard dash={dash} />
            </>
          )}
        </>
      ) : null}
    </div>
  );
}
