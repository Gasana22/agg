import { describe, expect, it } from "vitest";

import { formatMinutes, TASK_STATUS, workerActions } from "./workforce";

describe("workforce helpers", () => {
  it("offers the worker the next valid steps", () => {
    expect(workerActions("assigned")).toEqual(["start"]);
    expect(workerActions("rejected")).toEqual(["start"]);
    expect(workerActions("in_progress")).toEqual(["pause", "submit"]);
    expect(workerActions("paused")).toEqual(["resume", "submit"]);
    expect(workerActions("submitted")).toEqual([]);
    expect(workerActions("verified")).toEqual([]);
  });

  it("formats worked time", () => {
    expect(formatMinutes(null)).toBe("—");
    expect(formatMinutes(45)).toBe("45 min");
    expect(formatMinutes(120)).toBe("2 h");
    expect(formatMinutes(125)).toBe("2 h 5 min");
  });

  it("labels every task status", () => {
    for (const s of ["assigned", "in_progress", "paused", "submitted", "verified", "rejected", "cancelled"]) {
      expect(TASK_STATUS[s]?.label).toBeTruthy();
    }
  });
});
