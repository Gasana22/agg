import { describe, expect, it } from "vitest";

import { accountsOf, balanceDue, hours, type Account } from "./finance";

const a = (code: string, type: string, extra: Partial<Account> = {}): Account => ({ id: code, code, name: code, type: type as "asset", is_active: true, is_control: false, is_cash: false, ...extra });

describe("finance helpers", () => {
  it("picks usable accounts by type", () => {
    const chart = [a("1000", "asset", { is_cash: true }), a("1200", "asset", { is_control: true }), a("5400", "expense"), a("5900", "expense", { is_active: false })];
    expect(accountsOf(chart, ["expense"]).map((x) => x.code)).toEqual(["5400"]);
    expect(accountsOf(chart, ["asset"], { cash: true }).map((x) => x.code)).toEqual(["1000"]);
    expect(accountsOf(chart, ["asset"], { control: true }).map((x) => x.code)).toEqual(["1000", "1200"]);
  });

  it("computes what is due and formats hours", () => {
    expect(balanceDue({ amount: 1800000, paid_amount: 1000000.1 })).toBe(799999.9);
    expect(balanceDue({ amount: 100 })).toBe(100);
    expect(hours(90)).toBe("1 h 30 min");
    expect(hours(480)).toBe("8 h");
    expect(hours(25)).toBe("25 min");
  });
});
