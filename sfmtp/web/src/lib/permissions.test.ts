import { describe, expect, it } from "vitest";

import type { Workspace } from "@/lib/api/hooks";

import { can, homePath, visibleAdminNav, visibleNav } from "./permissions";

const farm = (permissions: Record<string, "all" | "assigned" | "own">, dashboards: Workspace["dashboards"] = ["owner"]): Workspace => ({
  type: "farm",
  id: "f1",
  name: "Farm",
  permissions,
  dashboards,
});

describe("visibleNav", () => {
  it("shows sales to fulfilment staff and portal access to those who manage suppliers or customers", () => {
    expect(visibleNav(farm({ "sales.fulfil": "all" }, ["store"])).map((i) => i.key)).toEqual(["dashboard", "sales", "shipments", "support"]);
    expect(visibleNav(farm({ "suppliers.manage": "all" }, [])).map((i) => i.key)).toEqual(["portal-access", "support"]);
  });

  it("shows only items the member holds a permission for", () => {
    const keys = visibleNav(farm({ "trace.batches.view": "all" }, ["agronomist"])).map((i) => i.key);
    expect(keys).toEqual(["dashboard", "traceability", "support"]);
  });

  it("hides the dashboard link when the role has no dashboard", () => {
    expect(visibleNav(farm({}, [])).map((i) => i.key)).toEqual(["support"]);
  });

  it("gives owners everything, including their subscription", () => {
    const all = { "structure.view": "all", "crops.plans.view": "all", "livestock.animals.view": "all", "tasks.execute": "assigned", "tasks.view": "all", "workers.view": "all", "inventory.view": "all", "procurement.orders.approve": "all", "finance.view": "all", "trace.batches.view": "all", "sales.view": "all", "customers.manage": "all", "members.view": "all", "roles.view": "all", "audit.view": "all", "farm.profile.manage": "all", "billing.manage": "all" } as const;
    expect(visibleNav(farm(all)).map((i) => i.key)).toEqual(["dashboard", "my-day", "tasks", "structure", "crops", "livestock", "inventory", "procurement", "workers", "finance", "reports", "ledger", "traceability", "sales", "shipments", "portal-access", "members", "roles", "audit", "settings", "billing", "support"]);
  });

  it("shows purchasing to anyone with one of its permissions", () => {
    expect(visibleNav(farm({ "procurement.deliveries.receive": "all" }, ["store"])).map((i) => i.key)).toEqual(["dashboard", "procurement", "support"]);
    expect(can({ "finance.view": "all" }, "procurement.orders.manage|finance.view")).toBe(true);
    expect(can({ "finance.view": "all" }, "procurement.orders.manage")).toBe(false);
  });

  it("gives the manager expense requests but not the books", () => {
    const manager = { "finance.expenses.request": "all", "finance.payroll.view_hours": "all", "tasks.view": "all" } as const;
    expect(visibleNav(farm(manager, ["manager"])).map((i) => i.key)).toEqual(["dashboard", "tasks", "finance", "support"]);
  });

  it("gives field workers their day, not the supervisors' task and worker lists", () => {
    const worker = { "tasks.execute": "assigned", "tasks.view": "assigned", "workers.view": "own", "structure.view": "assigned" } as const;
    expect(visibleNav(farm(worker, ["worker"])).map((i) => i.key)).toEqual(["dashboard", "my-day", "structure", "support"]);
  });

  it("offers read-only support sessions no support tickets or billing", () => {
    const support: Workspace = { ...farm({ "trace.batches.view": "all" }), type: "support" };
    expect(visibleNav(support).map((i) => i.key)).toEqual(["dashboard", "traceability"]);
  });
});

describe("can", () => {
  it("treats a null permission as always allowed", () => {
    expect(can(undefined, null)).toBe(true);
    expect(can({}, "audit.view")).toBe(false);
  });
});

describe("homePath", () => {
  const meta = { mfaRequired: false, mfaEnabled: false };

  it("forces MFA enrolment first when the role requires it", () => {
    expect(homePath([farm({})], { mfaRequired: true, mfaEnabled: false })).toBe("/mfa/setup");
  });

  it("opens the first farm's highest-precedence dashboard", () => {
    expect(homePath([farm({}, ["manager", "worker"])], meta)).toBe("/farms/f1/dashboard/manager");
  });

  it("sends platform admins to /admin and new users to onboarding", () => {
    expect(homePath([{ type: "platform", id: "platform", name: "Admin", dashboards: ["admin"] }], meta)).toBe("/admin");
    expect(homePath([], meta)).toBe("/onboarding");
  });

  it("sends a portal-only account to its portal", () => {
    expect(homePath([{ type: "supplier", id: "p1", name: "Kakiri Agro", dashboards: ["supplier"], farms: [{ id: "f1", name: "Farm" }] }], meta)).toBe("/supplier/p1");
    expect(homePath([{ type: "customer", id: "p2", name: "Millers", dashboards: ["customer"] }], meta)).toBe("/customer/p2");
  });
});

describe("visibleAdminNav", () => {
  it("filters platform navigation by capability", () => {
    expect(visibleAdminNav({ "dashboard.view": "all", "plans.manage": "all", "subscriptions.view": "all" }).map((i) => i.key)).toEqual([
      "dashboard",
      "subscriptions",
      "plans",
    ]);
    expect(visibleAdminNav(undefined)).toEqual([]);
  });
});
