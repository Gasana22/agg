import { api } from "@/lib/api";

export type Expense = {
  id: number;
  farm_id: number;
  category: "input" | "maintenance" | "utilities" | "rent" | "other";
  description: string;
  amount: string;
  date: string;
  recorder: { id: number; name: string };
  created_at: string;
};

export type PayrollPayment = {
  id: number;
  farm_id: number;
  worker_profile: { id: number; user_id: number; name: string };
  period_start: string;
  period_end: string;
  days_worked: number;
  gross_amount: string;
  deductions: string;
  net_amount: string;
  status: "pending" | "paid";
  paid_date: string | null;
  recorder: { id: number; name: string };
  created_at: string;
};

export type FinanceIncome = {
  from: string;
  to: string;
  crop_sales: number;
  livestock_sales: number;
  total: number;
};

export type FinanceExpensesBreakdown = {
  from: string;
  to: string;
  crop_operations: number;
  livestock_care: number;
  general_tasks: number;
  payroll: number;
  asset_maintenance: number;
  other_expenses: number;
  total: number;
};

export type FinanceProfitAndLoss = {
  from: string;
  to: string;
  income_total: number;
  expenses_total: number;
  net_profit: number;
};

export const EXPENSE_CATEGORIES = ["input", "maintenance", "utilities", "rent", "other"] as const;

// Expenses
export async function listExpenses(farmId: number) {
  const { data } = await api.get<{ data: Expense[] }>(`/farms/${farmId}/expenses`);
  return data.data;
}

export async function createExpense(
  farmId: number,
  payload: { category: string; description: string; amount: number; date: string }
) {
  const { data } = await api.post<{ data: Expense }>(`/farms/${farmId}/expenses`, payload);
  return data.data;
}

export async function updateExpense(
  id: number,
  payload: Partial<{ category: string; description: string; amount: number; date: string }>
) {
  const { data } = await api.patch<{ data: Expense }>(`/expenses/${id}`, payload);
  return data.data;
}

export async function deleteExpense(id: number) {
  await api.delete(`/expenses/${id}`);
}

// Payroll
export async function listPayrollPayments(workerProfileId: number) {
  const { data } = await api.get<{ data: PayrollPayment[] }>(
    `/worker-profiles/${workerProfileId}/payroll-payments`
  );
  return data.data;
}

export async function createPayrollPayment(
  workerProfileId: number,
  payload: { period_start: string; period_end: string; deductions?: number }
) {
  const { data } = await api.post<{ data: PayrollPayment }>(
    `/worker-profiles/${workerProfileId}/payroll-payments`,
    payload
  );
  return data.data;
}

export async function payPayrollPayment(id: number, deductions?: number) {
  const { data } = await api.post<{ data: PayrollPayment }>(`/payroll-payments/${id}/pay`, { deductions });
  return data.data;
}

// Reports
function rangeParams(from?: string, to?: string) {
  const params: Record<string, string> = {};
  if (from) params.from = from;
  if (to) params.to = to;
  return params;
}

export async function getFinanceIncome(farmId: number, from?: string, to?: string) {
  const { data } = await api.get<FinanceIncome>(`/farms/${farmId}/finance/income`, {
    params: rangeParams(from, to),
  });
  return data;
}

export async function getFinanceExpensesBreakdown(farmId: number, from?: string, to?: string) {
  const { data } = await api.get<FinanceExpensesBreakdown>(`/farms/${farmId}/finance/expenses`, {
    params: rangeParams(from, to),
  });
  return data;
}

export async function getFinanceProfitAndLoss(farmId: number, from?: string, to?: string) {
  const { data } = await api.get<FinanceProfitAndLoss>(`/farms/${farmId}/finance/profit-and-loss`, {
    params: rangeParams(from, to),
  });
  return data;
}
