import { describe, expect, it } from "vitest";

import { buildTree, formatHa, parsePolygon, polygonFromPoints } from "./structure";

describe("buildTree", () => {
  it("nests sections under blocks and plots under sections, keeping loose plots apart", () => {
    const tree = buildTree({
      blocks: [{ id: "b1", code: "A", name: "Block A" }],
      sections: [{ id: "s1", block_id: "b1", code: "A-S1", name: "Main" }],
      plots: [
        { id: "p1", section_id: "s1", code: "A-1", name: "Plot 1" },
        { id: "p2", section_id: null, code: "P-2", name: "Loose" },
        { id: "p3", section_id: "hidden", code: "P-3", name: "Section not visible" },
      ],
    });

    expect(tree.blocks[0].sections[0].plots.map((p) => p.id)).toEqual(["p1"]);
    expect(tree.loosePlots.map((p) => p.id)).toEqual(["p2", "p3"]);
  });
});

describe("polygonFromPoints", () => {
  it("closes the ring and swaps clicked [lat, lng] into GeoJSON [lng, lat]", () => {
    expect(polygonFromPoints([[0.1, 32.1], [0.1, 32.2]])).toBeNull();
    expect(polygonFromPoints([[0.1, 32.1], [0.1, 32.2], [0.2, 32.2]])).toEqual({
      type: "Polygon",
      coordinates: [[[32.1, 0.1], [32.2, 0.1], [32.2, 0.2], [32.1, 0.1]]],
    });
  });
});

describe("parsePolygon", () => {
  const polygon = { type: "Polygon", coordinates: [[[32, 0], [32.1, 0], [32.1, 0.1], [32, 0]]] };

  it("accepts a polygon, a feature or a feature collection", () => {
    expect(parsePolygon(JSON.stringify(polygon))).toEqual(polygon);
    expect(parsePolygon(JSON.stringify({ type: "Feature", geometry: polygon, properties: {} }))).toEqual(polygon);
    expect(parsePolygon(JSON.stringify({ type: "FeatureCollection", features: [{ type: "Feature", geometry: polygon }] }))).toEqual(polygon);
  });

  it("rejects anything else", () => {
    expect(parsePolygon("not json")).toBeNull();
    expect(parsePolygon(JSON.stringify({ type: "Point", coordinates: [32, 0] }))).toBeNull();
  });
});

describe("formatHa", () => {
  it("formats hectares", () => {
    expect(formatHa(null)).toBe("—");
    expect(formatHa(6.6134)).toBe("6.61 ha");
    expect(formatHa(123.94)).toBe("123.9 ha");
  });
});
