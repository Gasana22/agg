import { describe, expect, it } from "vitest";

import { formatDelta, formatKpiValue, formatRelative, humanize } from "./format";

describe("formatKpiValue", () => {
  it("formats each KPI format", () => {
    expect(formatKpiValue({ format: "number", value: 12345 })).toBe("12,345");
    expect(formatKpiValue({ format: "quantity", value: { value: "120.0000", unit: "ha" } })).toBe("120 ha");
    expect(formatKpiValue({ format: "percent", value: 0.125 })).toBe("12.5%");
    expect(formatKpiValue({ format: "status", value: "ok" })).toBe("Healthy");
    expect(formatKpiValue({ format: "number", value: null })).toBe("—");
    expect(formatKpiValue({ format: "money", value: { amount: "1500000.00", currency: "UGX" } })).toMatch(/1,500,000/);
  });
});

describe("formatDelta", () => {
  it("signs the change and ignores missing values", () => {
    expect(formatDelta({ value: 0.124, direction: "up", vs: "previous_period" })).toBe("+12.4%");
    expect(formatDelta({ value: -0.05, direction: "down", vs: "previous_period" })).toBe("−5.0%");
    expect(formatDelta({ value: null, direction: "up", vs: "previous_period" })).toBeNull();
    expect(formatDelta(null)).toBeNull();
  });
});

describe("formatRelative / humanize", () => {
  it("renders relative times", () => {
    const now = new Date("2026-09-23T12:00:00Z");
    expect(formatRelative("2026-09-23T10:00:00Z", now)).toBe("2 hours ago");
    expect(formatRelative("2026-09-23T11:59:50Z", now)).toBe("just now");
  });

  it("humanizes snake_case", () => {
    expect(humanize("status_changed")).toBe("Status changed");
  });
});
