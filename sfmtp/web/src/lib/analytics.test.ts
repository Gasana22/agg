import { describe, expect, it } from "vitest";

import {
  formatBytes,
  formatReportCell,
  groupReports,
  hasPendingExports,
  heatStyle,
  isNumericColumn,
} from "./analytics";

describe("analytics helpers", () => {
  it("groups reports in a fixed order and drops empty groups", () => {
    const groups = groupReports([
      { key: "harvests", group: "crops" },
      { key: "stock_valuation", group: "inventory" },
      { key: "aged_payables", group: "finance" },
    ]);
    expect(groups.map((g) => g.key)).toEqual(["inventory", "crops", "finance"]);
    expect(groups[1].reports.map((r) => r.key)).toEqual(["harvests"]);
  });

  it("formats report cells by column type", () => {
    expect(formatReportCell("1500000.50", "money", "UGX")).toBe(
      "UGX 1,500,000.5",
    );
    expect(formatReportCell("-20.00", "money", "UGX")).toBe("−UGX 20");
    expect(formatReportCell(0.4567, "percent")).toBe("45.7%");
    expect(formatReportCell("1250.5", "number")).toBe("1,250.5");
    expect(formatReportCell(null, "money", "UGX")).toBe("—");
    expect(formatReportCell("PAD-1", "text")).toBe("PAD-1");
    expect(isNumericColumn("money")).toBe(true);
    expect(isNumericColumn("date")).toBe(false);
  });

  it("knows when exports are still being built", () => {
    expect(
      hasPendingExports([{ status: "ready" }, { status: "running" }]),
    ).toBe(true);
    expect(
      hasPendingExports([
        { status: "ready" },
        { status: "expired" },
        { status: "failed" },
      ]),
    ).toBe(false);
  });

  it("formats file sizes", () => {
    expect(formatBytes(512)).toBe("512 B");
    expect(formatBytes(2048)).toBe("2.0 KB");
    expect(formatBytes(3 * 1024 * 1024)).toBe("3.0 MB");
    expect(formatBytes(null)).toBe("—");
  });

  it("shades heat cells by the square root of their share of the busiest cell", () => {
    expect(heatStyle(100, 100)).toEqual({
      color: "#dc2626",
      fillOpacity: 0.75,
    });
    expect(heatStyle(25, 100)).toEqual({ color: "#f97316", fillOpacity: 0.45 });
    expect(heatStyle(1, 100)).toEqual({ color: "#f59e0b", fillOpacity: 0.21 });
    expect(heatStyle(5, 0).fillOpacity).toBe(0.15);
  });
});
