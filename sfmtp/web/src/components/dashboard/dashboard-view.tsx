import Link from "next/link";

import { buttonVariants } from "@/components/ui/button";
import { EmptyState } from "@/components/ui/misc";
import type { components } from "@/lib/api/schema";

import { KpiCard } from "./kpi-card";
import { Widget } from "./widget-registry";

type Dashboard = components["schemas"]["Dashboard"];

export function DashboardView({ dashboard }: { dashboard: Dashboard }) {
  const hasContent = dashboard.kpis.length > 0 || dashboard.widgets.length > 0;

  return (
    <div className="space-y-6">
      {dashboard.quick_actions.length > 0 ? (
        <div className="flex flex-wrap gap-2">
          {dashboard.quick_actions.map((a) => (
            <Link key={a.key} href={a.target ?? "#"} className={buttonVariants({ variant: "secondary", size: "sm" })}>
              {a.label}
            </Link>
          ))}
        </div>
      ) : null}

      {dashboard.kpis.length > 0 ? (
        <section aria-label="Key figures" className="grid grid-cols-2 gap-3 md:grid-cols-4">
          {dashboard.kpis.map((kpi) => (
            <KpiCard key={kpi.key} kpi={kpi} />
          ))}
        </section>
      ) : null}

      {dashboard.widgets.length > 0 ? (
        <section className="grid gap-4 lg:grid-cols-2">
          {dashboard.widgets.map((w) => (
            <Widget key={w.key} widget={w} />
          ))}
        </section>
      ) : null}

      {!hasContent ? <EmptyState title="You're all caught up">Your tasks and alerts will appear here.</EmptyState> : null}
    </div>
  );
}
