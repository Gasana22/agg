import { describe, expect, it } from "vitest";

import { daysUntil, formatQuantity, relativeDays, underWithholding, yieldProgress } from "./crops";

const today = new Date(2026, 8, 24); // 24 Sept 2026, local

describe("crop date helpers", () => {
  it("counts days between local dates", () => {
    expect(daysUntil("2026-09-24", today)).toBe(0);
    expect(daysUntil("2026-10-08", today)).toBe(14);
    expect(daysUntil("2026-09-20", today)).toBe(-4);
    expect(daysUntil(null, today)).toBeNull();
  });

  it("describes relative days", () => {
    expect(relativeDays("2026-09-24", today)).toBe("today");
    expect(relativeDays("2026-09-25", today)).toBe("tomorrow");
    expect(relativeDays("2026-09-29", today)).toBe("in 5 days");
    expect(relativeDays("2026-09-21", today)).toBe("3 days ago");
  });

  it("knows when a withholding period is running", () => {
    expect(underWithholding({ safe_harvest_on: "2026-10-01" }, today)).toBe(true);
    expect(underWithholding({ safe_harvest_on: "2026-09-24" }, today)).toBe(false);
    expect(underWithholding({ safe_harvest_on: null }, today)).toBe(false);
  });
});

describe("yield helpers", () => {
  it("formats quantities and progress", () => {
    expect(formatQuantity(1020, "kg")).toBe("1,020 kg");
    expect(formatQuantity(null, "kg")).toBe("—");
    expect(yieldProgress({ expected_yield: 1000, actual_yield: 1020 })).toBe(102);
    expect(yieldProgress({ expected_yield: null, actual_yield: 5 })).toBeNull();
  });
});
