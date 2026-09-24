"use client";

import dynamic from "next/dynamic";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/misc";
import type { components } from "@/lib/api/schema";
import { humanize } from "@/lib/format";

import { ActionListWidget } from "./widgets/action-list";
import { ChecklistWidget } from "./widgets/checklist";

// Charts pull in Recharts: load them only when a dashboard has one.
const ChartWidget = dynamic(() => import("./widgets/chart").then((m) => m.ChartWidget), {
  ssr: false,
  loading: () => <Skeleton className="h-56 w-full" />,
});

type WidgetRef = components["schemas"]["WidgetRef"];

const TITLES: Record<string, string> = {
  setup_checklist: "Get your farm ready",
  recent_trace_events: "Recent traceability activity",
  trace_activity: "Traceability events per day",
  farm_approvals: "Farms awaiting approval",
  expiring_subscriptions: "Subscriptions ending within 14 days",
  support_queue: "Open support tickets",
  pest_disease_alerts: "Pest & disease alerts",
  operations_to_verify: "Field work to verify",
  upcoming_harvests: "Upcoming harvests",
  expected_vs_actual_yield: "Expected vs harvested (kg)",
};

/**
 * Maps a server widget `type` to a component. The server decides which
 * widgets a member sees; the client only knows how to draw each type.
 */
export function Widget({ widget }: { widget: WidgetRef }) {
  const title = TITLES[widget.key] ?? humanize(widget.key);
  const data = (widget.data ?? {}) as Record<string, unknown>;

  let body: React.ReactNode;
  switch (widget.type) {
    case "action_list":
      body = <ActionListWidget data={data} />;
      break;
    case "checklist":
      body = <ChecklistWidget data={data} />;
      break;
    case "chart":
      body = widget.href ? <ChartWidget href={widget.href} /> : null;
      break;
    default:
      body = <p className="text-sm text-muted">This widget isn&apos;t supported by this version of the app.</p>;
  }

  return (
    <Card className={widget.type === "chart" ? "lg:col-span-2" : undefined} data-widget={widget.key}>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent>{body}</CardContent>
    </Card>
  );
}
