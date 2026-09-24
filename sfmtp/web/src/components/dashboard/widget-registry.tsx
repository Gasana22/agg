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
  trace_alerts: "Traceability alerts",
  trace_activity: "Traceability events per day",
  farm_approvals: "Farms awaiting approval",
  expiring_subscriptions: "Subscriptions ending within 14 days",
  support_queue: "Open support tickets",
  pest_disease_alerts: "Pest & disease alerts",
  operations_to_verify: "Field work to verify",
  upcoming_harvests: "Upcoming harvests",
  expected_vs_actual_yield: "Expected vs harvested (kg)",
  vaccinations_due: "Vaccinations & dewormings due",
  withdrawal_alerts: "Withdrawal periods running",
  expected_births: "Expected births",
  weight_loss_alerts: "Weight loss",
  livestock_sale_requests: "Sale requests to decide",
  milk_production: "Milk kept per day (litres)",
  verification_queue: "Work to verify",
  schedule: "Today's schedule",
  overdue_tasks: "Overdue tasks",
  leave_requests: "Leave requests",
  worker_activity: "Tasks verified per day",
  today_tasks: "My tasks today",
  attendance_week: "My hours this week",
  pending_requests: "Stock requests",
  deliveries_to_receive: "Deliveries to receive",
  expiring_lots: "Lots expiring or expired",
  low_stock: "Low stock",
  recent_movements: "Recent stock movements",
  purchase_requests_to_approve: "Purchase requests to approve",
  orders_to_approve: "Purchase orders to approve",
  invoices_due: "Supplier invoices due",
  inventory_value: "Stock value by category",
  expenses_to_approve: "Expenses to approve",
  customer_invoices_overdue: "Customer invoices due",
  payroll_pending: "Payroll to approve or pay",
  recent_transactions: "Recent transactions",
  income_vs_expenses: "Income and expenses by month",
  budget_vs_actual: "Budgets: planned and spent",
  cash_flow_forecast: "Cash expected, next 13 weeks",
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
