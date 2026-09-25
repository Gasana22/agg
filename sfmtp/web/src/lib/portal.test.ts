import { describe, expect, it } from "vitest";

import { cartByFarm, pendingSubmitted, portalHome, quantityProblem, stillExpected, stillToInvoice, supplierOrderState, type PortalProduct } from "./portal";

const product = (id: string, farm: string, price: number, min?: number): PortalProduct =>
  ({ id, farm: { id: farm, name: `Farm ${farm}` }, price, currency: "UGX", unit: "kg", min_order_quantity: min ?? null, name: id }) as PortalProduct;

describe("portal helpers", () => {
  it("describes an order from the supplier's side", () => {
    expect(supplierOrderState({ status: "sent", supplier_response: null }).label).toBe("New: needs your answer");
    expect(supplierOrderState({ status: "sent", supplier_response: "accepted" }).tone).toBe("primary");
    expect(supplierOrderState({ status: "sent", supplier_response: "rejected" }).label).toBe("You declined");
    expect(supplierOrderState({ status: "partially_received", supplier_response: "accepted" }).label).toBe("Partly delivered");
    expect(supplierOrderState({ status: "closed", supplier_response: null }).label).toBe("Completed");
    expect(supplierOrderState({ status: "cancelled", supplier_response: "accepted" }).label).toBe("Cancelled by the farm");
  });

  it("works out what is still expected and still to invoice", () => {
    expect(stillExpected({ quantity: 500, received_quantity: 100, on_the_way: 150 })).toBe(250);
    expect(stillExpected({ quantity: 5, received_quantity: 5, on_the_way: 1 })).toBe(0);
    expect(stillToInvoice({ received_quantity: 400, invoiced_quantity: 100 }, 50.5)).toBe(249.5);
    expect(pendingSubmitted({ submissions: [
      { status: "submitted", lines: [{ order_line_id: "a", quantity: 2, unit_price: 1 }] },
      { status: "rejected", lines: [{ order_line_id: "a", quantity: 9, unit_price: 1 }] },
      { status: "submitted", lines: [{ order_line_id: "a", quantity: 1.5, unit_price: 1 }, { order_line_id: "b", quantity: 3, unit_price: 1 }] },
    ] })).toEqual({ a: 3.5, b: 3 });
  });

  it("splits the cart into one order per farm", () => {
    const groups = cartByFarm([
      { product: product("maize", "f1", 1400), quantity: 1000 },
      { product: product("milk", "f2", 1500), quantity: 20 },
      { product: product("bran", "f1", 600.5), quantity: 3 },
    ]);
    expect(groups.map((g) => [g.farm.id, g.lines.length, g.total])).toEqual([["f1", 2, 1401801.5], ["f2", 1, 30000]]);
  });

  it("checks quantities against the minimum order", () => {
    expect(quantityProblem(product("m", "f", 1, 500), 100)).toBe("The minimum order is 500 kg.");
    expect(quantityProblem(product("m", "f", 1, 500), 500)).toBeNull();
    expect(quantityProblem(product("m", "f", 1), 0)).toBe("Enter a quantity.");
    expect(portalHome({ type: "customer", id: "p1" })).toBe("/customer/p1");
  });
});
