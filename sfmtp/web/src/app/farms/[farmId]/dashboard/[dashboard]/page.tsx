"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { DashboardView } from "@/components/dashboard/dashboard-view";
import { PeriodSelect, type PeriodKey } from "@/components/dashboard/period-select";
import { ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { DASHBOARD_LABELS } from "@/lib/permissions";
import { cn } from "@/lib/utils";

type DashboardKey = "owner" | "manager" | "agronomist" | "livestock" | "store" | "accountant" | "worker";

export default function FarmDashboardPage() {
  const { farmId, dashboard } = useParams<{ farmId: string; dashboard: DashboardKey }>();
  const { workspace } = useFarmWorkspace(farmId);
  const [period, setPeriod] = useState<PeriodKey>("30d");

  const { data, error, isLoading } = useQuery({
    queryKey: ["dashboard", farmId, dashboard, period],
    queryFn: async () =>
      (await api.GET("/farms/{farm}/dashboards/{dashboard}", { params: { path: { farm: farmId, dashboard }, query: { period } } })).data!.data!,
  });

  const dashboards = workspace?.dashboards ?? [];

  return (
    <>
      <PageHeader
        title={workspace?.name ?? "Dashboard"}
        description={`${DASHBOARD_LABELS[dashboard] ?? dashboard} dashboard`}
        actions={dashboard === "worker" ? null : <PeriodSelect value={period} onChange={setPeriod} />}
      />

      {dashboards.length > 1 ? (
        <nav aria-label="Dashboards" className="-mt-2 mb-6 flex gap-1 overflow-x-auto border-b border-border">
          {dashboards.map((d) => (
            <Link
              key={d}
              href={`/farms/${farmId}/dashboard/${d}`}
              aria-current={d === dashboard ? "page" : undefined}
              className={cn(
                "whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium",
                d === dashboard ? "border-primary text-primary" : "border-transparent text-muted hover:text-foreground",
              )}
            >
              {DASHBOARD_LABELS[d] ?? d}
            </Link>
          ))}
        </nav>
      ) : null}

      {isLoading ? (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
      ) : error ? (
        <ErrorNotice error={error} />
      ) : data ? (
        <DashboardView dashboard={data} />
      ) : null}
    </>
  );
}
