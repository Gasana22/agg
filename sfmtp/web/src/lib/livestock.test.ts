import { describe, expect, it } from "vitest";

import { describeRecord, formatAge } from "./livestock";

describe("formatAge", () => {
  const today = new Date(2026, 8, 24);
  it("formats years, months and days", () => {
    expect(formatAge("2023-07-10", today)).toBe("3 y 2 m");
    expect(formatAge("2026-02-01", today)).toBe("7 m");
    expect(formatAge("2026-09-12", today)).toBe("12 d");
    expect(formatAge(null, today)).toBe("—");
  });
});

describe("describeRecord", () => {
  it("summarises a treatment with its withdrawal", () => {
    const d = describeRecord("health", { kind: "treatment", product_name: "Oxytetracycline", diagnosis: "Mastitis", meat_withdrawal_days: 28, milk_withdrawal_days: 7 });
    expect(d.title).toBe("Treatment: Oxytetracycline");
    expect(d.detail).toBe("Mastitis · withdrawal meat 28 d, milk 7 d");
  });

  it("marks discarded production", () => {
    expect(describeRecord("production", { product: "milk", quantity: 9, unit: "l", session: "am", discarded: true })).toEqual({ title: "Milk: 9 l (am)", detail: "Discarded (withdrawal)" });
  });
});
