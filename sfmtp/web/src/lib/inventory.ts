import type { components } from "@/lib/api/schema";

export type InventoryItem = components["schemas"]["InventoryItem"];
export type StockBalance = components["schemas"]["StockBalance"];
export type StockMovement = components["schemas"]["StockMovement"];
export type StockAdjustment = components["schemas"]["StockAdjustment"];
export type InventoryRequest = components["schemas"]["InventoryRequest"];
export type Supplier = components["schemas"]["Supplier"];
export type PurchaseRequest = components["schemas"]["PurchaseRequest"];
export type PurchaseOrder = components["schemas"]["PurchaseOrder"];
export type SupplierInvoice = components["schemas"]["SupplierInvoice"];
export type LedgerAccount = components["schemas"]["LedgerAccount"];
export type LedgerEntry = components["schemas"]["LedgerEntry"];

type Tone = "primary" | "warning" | "neutral" | "danger" | "success" | "info";

const qtyFormat = new Intl.NumberFormat("en-UG", { maximumFractionDigits: 3 });
const moneyFormat = new Intl.NumberFormat("en-UG", { minimumFractionDigits: 0, maximumFractionDigits: 2 });

/** A quantity with its unit, up to three decimals: "1,250.5 kg". */
export function formatQty(value: number | null | undefined, unit?: string | null): string {
  if (value === null || value === undefined) return "—";
  return unit ? `${qtyFormat.format(value)} ${unit}` : qtyFormat.format(value);
}

/** An amount in the farm's currency: "UGX 1,250,000". Values are hidden (undefined) without money access. */
export function formatMoney(value: number | null | undefined, currency?: string | null): string {
  if (value === null || value === undefined) return "—";
  const sign = value < 0 ? "−" : "";
  return `${sign}${currency ? `${currency} ` : ""}${moneyFormat.format(Math.abs(value))}`;
}

export const MOVEMENT_LABEL: Record<string, string> = {
  receipt: "Received",
  opening: "Opening stock",
  issue: "Issued",
  return: "Returned",
  transfer_out: "Transferred out",
  transfer_in: "Transferred in",
  adjustment: "Count adjustment",
};

export const REQUEST_STATUS: Record<string, { label: string; tone: Tone }> = {
  requested: { label: "To approve", tone: "warning" },
  approved: { label: "To issue", tone: "info" },
  partially_issued: { label: "Part issued", tone: "info" },
  issued: { label: "Issued", tone: "success" },
  rejected: { label: "Rejected", tone: "danger" },
  cancelled: { label: "Cancelled", tone: "neutral" },
};

export const ADJUSTMENT_STATUS: Record<string, { label: string; tone: Tone }> = {
  proposed: { label: "To approve", tone: "warning" },
  approved: { label: "Applied", tone: "success" },
  rejected: { label: "Rejected", tone: "danger" },
  cancelled: { label: "Cancelled", tone: "neutral" },
};

export const PURCHASE_REQUEST_STATUS: Record<string, { label: string; tone: Tone }> = {
  submitted: { label: "To approve", tone: "warning" },
  approved: { label: "Approved", tone: "info" },
  ordered: { label: "Ordered", tone: "success" },
  rejected: { label: "Rejected", tone: "danger" },
  cancelled: { label: "Cancelled", tone: "neutral" },
};

export const ORDER_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft: { label: "Draft", tone: "warning" },
  approved: { label: "Approved", tone: "info" },
  sent: { label: "Sent", tone: "info" },
  partially_received: { label: "Part received", tone: "primary" },
  received: { label: "Received", tone: "success" },
  closed: { label: "Closed", tone: "neutral" },
  cancelled: { label: "Cancelled", tone: "neutral" },
};

/** Orders the store can still receive against. */
export const RECEIVABLE = ["approved", "sent", "partially_received"];

/** What is still to come on an order line. */
export function outstanding(line: { quantity?: number; received_quantity?: number }): number {
  return Math.max(0, Math.round(((line.quantity ?? 0) - (line.received_quantity ?? 0)) * 1000) / 1000);
}

/** What was received but not yet invoiced on an order line. */
export function toInvoice(line: { received_quantity?: number; invoiced_quantity?: number }): number {
  return Math.max(0, Math.round(((line.received_quantity ?? 0) - (line.invoiced_quantity ?? 0)) * 1000) / 1000);
}

/** Sum of quantity × price over lines, rounded to cents. */
export function linesTotal(lines: { quantity: number | string; unit_price: number | string }[]): number {
  const cents = lines.reduce((sum, l) => sum + Math.round(Number(l.quantity || 0) * Number(l.unit_price || 0) * 100), 0);
  return cents / 100;
}

/** Days until a date (negative when past), in whole days from today. */
export function daysUntil(date: string, today: Date = new Date()): number {
  const start = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
  const [y, m, d] = date.split("-").map(Number);
  return Math.round((Date.UTC(y, m - 1, d) - start) / 86_400_000);
}
