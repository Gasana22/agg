import { describe, expect, it } from "vitest";

import { daysUntil, formatMoney, formatQty, linesTotal, outstanding, toInvoice } from "./inventory";

describe("inventory helpers", () => {
  it("formats quantities and money", () => {
    expect(formatQty(1250.5, "kg")).toBe("1,250.5 kg");
    expect(formatQty(0.125)).toBe("0.125");
    expect(formatQty(null, "kg")).toBe("—");
    expect(formatMoney(2300000, "UGX")).toBe("UGX 2,300,000");
    expect(formatMoney(-50000.5, "UGX")).toBe("−UGX 50,000.5");
    expect(formatMoney(undefined, "UGX")).toBe("—");
  });

  it("works out what is still to receive and to invoice", () => {
    expect(outstanding({ quantity: 500, received_quantity: 300 })).toBe(200);
    expect(outstanding({ quantity: 5, received_quantity: 6 })).toBe(0);
    expect(outstanding({ quantity: 0.3, received_quantity: 0.1 })).toBe(0.2);
    expect(toInvoice({ received_quantity: 500, invoiced_quantity: 0 })).toBe(500);
  });

  it("totals order lines to the cent", () => {
    expect(linesTotal([{ quantity: 500, unit_price: 3000 }, { quantity: "100", unit_price: "8000" }])).toBe(2300000);
    expect(linesTotal([{ quantity: 0.1, unit_price: 0.2 }, { quantity: 3, unit_price: 0.1 }])).toBe(0.32);
    expect(linesTotal([{ quantity: "", unit_price: 5 }])).toBe(0);
  });

  it("counts days to a date", () => {
    const today = new Date(2026, 8, 24);
    expect(daysUntil("2026-10-12", today)).toBe(18);
    expect(daysUntil("2026-09-20", today)).toBe(-4);
    expect(daysUntil("2026-09-24", today)).toBe(0);
  });
});
