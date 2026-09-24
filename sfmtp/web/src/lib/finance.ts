import type { components } from "@/lib/api/schema";

export type Expense = components["schemas"]["Expense"];
export type Income = components["schemas"]["Income"];
export type Payment = components["schemas"]["Payment"];
export type PayrollRun = components["schemas"]["PayrollRun"];
export type Budget = components["schemas"]["Budget"];
export type Customer = components["schemas"]["Customer"];
export type CustomerInvoice = components["schemas"]["CustomerInvoice"];
export type Account = components["schemas"]["LedgerAccount"];

type Tone = "primary" | "warning" | "neutral" | "danger" | "success" | "info";

export const EXPENSE_STATUS: Record<string, { label: string; tone: Tone }> = {
  requested: { label: "To approve", tone: "warning" },
  approved: { label: "To pay", tone: "info" },
  paid: { label: "Paid", tone: "success" },
  rejected: { label: "Rejected", tone: "danger" },
  cancelled: { label: "Withdrawn", tone: "neutral" },
  void: { label: "Void", tone: "neutral" },
};

export const INVOICE_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft: { label: "Draft", tone: "warning" },
  issued: { label: "Issued", tone: "info" },
  paid: { label: "Paid", tone: "success" },
  void: { label: "Void", tone: "neutral" },
};

export const PAYROLL_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft: { label: "To approve", tone: "warning" },
  approved: { label: "To pay", tone: "info" },
  paid: { label: "Paid", tone: "success" },
  cancelled: { label: "Cancelled", tone: "neutral" },
};

export const PAYMENT_METHODS = [
  { key: "cash", label: "Cash" },
  { key: "mobile_money", label: "Mobile money" },
  { key: "bank", label: "Bank transfer" },
  { key: "cheque", label: "Cheque" },
  { key: "other", label: "Other" },
] as const;

export const PAYABLE_LABEL: Record<string, string> = {
  customer_invoice: "Customer invoice",
  supplier_invoice: "Supplier invoice",
  expense: "Expense",
  payroll_run: "Payroll",
};

export const SOURCE_LABEL: Record<string, string> = {
  income: "Other income",
  payment: "Payments",
  expense: "Expenses paid on the spot",
  manual: "Manual entries",
};

/** Accounts of some types, active, and (unless asked) not control accounts. */
export function accountsOf(accounts: Account[] | undefined, types: string[], opts: { cash?: boolean; control?: boolean } = {}): Account[] {
  return (accounts ?? []).filter(
    (a) => types.includes(a.type ?? "") && a.is_active !== false && (opts.control || !a.is_control) && (opts.cash === undefined || Boolean(a.is_cash) === opts.cash),
  );
}

/** What is still owed on a document. */
export function balanceDue(doc: { amount?: number; paid_amount?: number }): number {
  return Math.round(((doc.amount ?? 0) - (doc.paid_amount ?? 0)) * 100) / 100;
}

/** "1 h 30 min" from minutes. */
export function hours(minutes: number | undefined): string {
  const m = minutes ?? 0;
  const h = Math.floor(m / 60);
  return h > 0 ? `${h} h${m % 60 ? ` ${m % 60} min` : ""}` : `${m} min`;
}
