import type { components } from "@/lib/api/schema";

import type { Workspace } from "./api/hooks";

export type PortalKind = "supplier" | "customer";
export type PortalOrder = components["schemas"]["PortalPurchaseOrder"];
export type PortalSalesOrder = components["schemas"]["PortalSalesOrder"];
export type PortalProduct = components["schemas"]["PortalProduct"];
export type PortalShipment = components["schemas"]["PortalShipment"];
export type PortalPurchase = components["schemas"]["PortalPurchase"];
export type PortalCustomerInvoice = components["schemas"]["PortalCustomerInvoice"];
export type SupplierDashboard = components["schemas"]["SupplierDashboard"];
export type CustomerDashboard = components["schemas"]["CustomerDashboard"];
export type PartyProfile = components["schemas"]["PartyProfile"];
export type SalesOrder = components["schemas"]["SalesOrder"];
export type Product = components["schemas"]["Product"];
export type PortalAccessRow = components["schemas"]["PortalAccessRow"];
export type InvoiceSubmission = components["schemas"]["InvoiceSubmission"];

type Tone = "primary" | "warning" | "neutral" | "danger" | "success" | "info";

/** A purchase order as the supplier sees it: the farm's status plus the supplier's own answer. */
export function supplierOrderState(o: Pick<PortalOrder, "status" | "supplier_response">): { label: string; tone: Tone } {
  if (o.status === "cancelled") return { label: "Cancelled by the farm", tone: "neutral" };
  if (o.status === "closed" || o.status === "received") return { label: "Completed", tone: "success" };
  if (o.status === "partially_received") return { label: "Partly delivered", tone: "info" };
  if (o.supplier_response === "rejected") return { label: "You declined", tone: "danger" };
  if (o.supplier_response === "accepted") return { label: "Accepted", tone: "primary" };
  return { label: "New: needs your answer", tone: "warning" };
}

export const SALES_ORDER_STATUS: Record<string, { label: string; tone: Tone }> = {
  requested: { label: "Waiting for approval", tone: "warning" },
  approved: { label: "Approved", tone: "primary" },
  rejected: { label: "Declined", tone: "danger" },
  invoiced: { label: "Invoiced", tone: "info" },
  dispatched: { label: "On the way", tone: "info" },
  delivered: { label: "Delivered", tone: "success" },
  cancelled: { label: "Cancelled", tone: "neutral" },
};

export const TIMELINE_LABEL: Record<string, string> = {
  placed: "Order placed",
  approved: "Approved by the farm",
  rejected: "Declined by the farm",
  cancelled: "Cancelled",
  invoiced: "Invoice issued",
  dispatched: "Dispatched",
  delivered: "Delivered",
  delivery_failed: "Delivery did not arrive",
};

/** What a supplier may still send or invoice on a line (never below zero). */
export function stillExpected(l: { quantity?: number; received_quantity?: number; on_the_way?: number }): number {
  return Math.max(0, round3((l.quantity ?? 0) - (l.received_quantity ?? 0) - (l.on_the_way ?? 0)));
}

export function stillToInvoice(l: { received_quantity?: number; invoiced_quantity?: number }, pending = 0): number {
  return Math.max(0, round3((l.received_quantity ?? 0) - (l.invoiced_quantity ?? 0) - pending));
}

/** Quantities on invoices the supplier sent that the farm has not recorded yet, per order line. */
export function pendingSubmitted(order: Pick<PortalOrder, "submissions">): Record<string, number> {
  const out: Record<string, number> = {};
  for (const s of order.submissions ?? []) {
    if (s.status !== "submitted") continue;
    for (const l of s.lines ?? []) out[l.order_line_id!] = round3((out[l.order_line_id!] ?? 0) + (l.quantity ?? 0));
  }
  return out;
}

export type CartLine = { product: PortalProduct; quantity: number };

/** The cart split per farm: each farm gets its own order. */
export function cartByFarm(cart: CartLine[]): { farm: { id: string; name: string }; lines: CartLine[]; total: number; currency: string }[] {
  const groups = new Map<string, { farm: { id: string; name: string }; lines: CartLine[]; total: number; currency: string }>();
  for (const line of cart) {
    const farm = { id: line.product.farm?.id ?? "", name: line.product.farm?.name ?? "" };
    const g = groups.get(farm.id) ?? { farm, lines: [], total: 0, currency: line.product.currency ?? "" };
    g.lines.push(line);
    g.total = Math.round((g.total + line.quantity * (line.product.price ?? 0)) * 100) / 100;
    groups.set(farm.id, g);
  }
  return [...groups.values()];
}

/** Why a quantity cannot be ordered, or null. */
export function quantityProblem(product: Pick<PortalProduct, "min_order_quantity" | "unit">, quantity: number): string | null {
  if (!(quantity > 0)) return "Enter a quantity.";
  if (product.min_order_quantity && quantity < product.min_order_quantity) return `The minimum order is ${product.min_order_quantity} ${product.unit}.`;
  return null;
}

/** The portal workspaces of the signed-in person. */
export function portalWorkspaces(workspaces: Workspace[] | undefined): Workspace[] {
  return (workspaces ?? []).filter((w) => w.type === "supplier" || w.type === "customer");
}

export function portalHome(w: Pick<Workspace, "type" | "id">): string {
  return `/${w.type}/${w.id}`;
}

function round3(n: number): number {
  return Math.round(n * 1000) / 1000;
}
