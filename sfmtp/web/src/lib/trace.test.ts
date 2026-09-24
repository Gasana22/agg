import { describe, expect, it } from "vitest";

import { formatQty, isUsable, layoutJourney, NODE_W } from "./trace";

const batch = { id: "h", batch_code: "SFM-H", kind: "harvest", status: "open", name: null, quantity: { value: "1020.000", unit: "kg" } } as never;

describe("layoutJourney", () => {
  it("puts sources left and destinations right of the batch", () => {
    const layout = layoutJourney(
      batch,
      {
        nodes: [
          { id: "c", batch_code: "SFM-C", kind: "crop_lot", depth: 1 },
          { id: "s", batch_code: "SFM-S", kind: "seed_lot", depth: 2 },
        ],
        edges: [
          { id: "e1", from: "c", to: "h", link_type: "derived", quantity: "1020.000", unit: "kg" },
          { id: "e2", from: "s", to: "c", link_type: "derived", quantity: null, unit: null },
        ],
      },
      { nodes: [{ id: "p", batch_code: "SFM-P", kind: "processed", depth: 1 }], edges: [{ id: "e3", from: "h", to: "p", link_type: "split", quantity: "520", unit: "kg" }] },
    );
    const x = Object.fromEntries(layout.nodes.map((n) => [n.id, n.x]));
    expect(x.s).toBe(0);
    expect(x.s).toBeLessThan(x.c);
    expect(x.c).toBeLessThan(x.h);
    expect(x.h).toBeLessThan(x.p);
    expect(layout.nodes.find((n) => n.id === "h")?.center).toBe(true);
    expect(layout.edges.map((e) => e.label)).toEqual(["grew into 1,020 kg", "grew into", "split 520 kg"]);
    expect(layout.width).toBeGreaterThan(3 * NODE_W);
  });

  it("stacks siblings and handles a lone batch", () => {
    const layout = layoutJourney(batch, { nodes: [], edges: [] }, null);
    expect(layout.nodes).toHaveLength(1);
    expect(layout.width).toBe(NODE_W);
  });
});

describe("helpers", () => {
  it("formats quantities and knows usable batches", () => {
    expect(formatQty({ value: "500.000", unit: "kg" })).toBe("500 kg");
    expect(formatQty(null)).toBe("—");
    expect(isUsable({ status: "open", kind: "harvest" })).toBe(true);
    expect(isUsable({ status: "open", kind: "shipment" })).toBe(false);
    expect(isUsable({ status: "recalled", kind: "packaged" })).toBe(false);
  });
});
