"use client";

import { useParams, useSearchParams } from "next/navigation";
import { Suspense } from "react";

import { BudgetsPanel, CustomersPanel, ExpensesPanel, IncomePanel, InvoicesPanel, PaymentsPanel, PayrollPanel } from "@/components/finance/panels";
import { TabBar } from "@/components/inventory/common";
import { useFarmCurrency } from "@/components/inventory/queries";
import { EmptyState, PageHeader } from "@/components/ui/misc";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { can } from "@/lib/permissions";

const TABS = [
  { key: "expenses", label: "Expenses", permission: "finance.view|finance.expenses.request" },
  { key: "income", label: "Income", permission: "finance.view" },
  { key: "invoices", label: "Invoices", permission: "sales.invoice|finance.view" },
  { key: "payments", label: "Payments", permission: "finance.view|sales.invoice" },
  { key: "payroll", label: "Payroll", permission: "finance.payroll.manage|finance.payroll.approve|finance.payroll.view_hours|finance.view" },
  { key: "budgets", label: "Budgets", permission: "finance.view|finance.budgets.manage" },
  { key: "customers", label: "Customers", permission: "customers.view|sales.invoice" },
] as const;

export default function FinancePage() {
  return (
    <Suspense>
      <Finance />
    </Suspense>
  );
}

function Finance() {
  const { farmId } = useParams<{ farmId: string }>();
  const search = useSearchParams();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const writable = workspace?.type === "farm";
  const currency = useFarmCurrency(farmId);
  const visible = TABS.filter((t) => can(perms, t.permission));
  const tab = visible.find((t) => t.key === search.get("tab"))?.key ?? visible[0]?.key;
  const props = { farmId, currency, perms, writable, startNew: search.get("new") };

  return (
    <>
      <PageHeader title="Finance" description="Money in and out, payroll and budgets. Every figure posts to the books; nothing is edited once posted, only voided." />
      {visible.length === 0 ? (
        <EmptyState title="Nothing here for your role" />
      ) : (
        <>
          <TabBar base={`/farms/${farmId}/finance`} tabs={visible} active={tab ?? ""} label="Finance sections" />
          {tab === "expenses" ? <ExpensesPanel {...props} initialStatus={search.get("status")} /> : null}
          {tab === "income" ? <IncomePanel {...props} /> : null}
          {tab === "invoices" ? <InvoicesPanel {...props} /> : null}
          {tab === "payments" ? <PaymentsPanel {...props} /> : null}
          {tab === "payroll" ? <PayrollPanel {...props} /> : null}
          {tab === "budgets" ? <BudgetsPanel {...props} /> : null}
          {tab === "customers" ? <CustomersPanel farmId={farmId} perms={perms} writable={writable} /> : null}
        </>
      )}
    </>
  );
}
