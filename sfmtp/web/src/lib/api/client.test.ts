import { describe, expect, it } from "vitest";

import { serializeQuery } from "./client";

describe("serializeQuery", () => {
  it("sends booleans as 1 / 0 and drops empty values", () => {
    expect(serializeQuery({ "filter[low_stock]": true, "filter[active]": false, q: "", cursor: undefined, per_page: 50 })).toBe("filter%5Blow_stock%5D=1&filter%5Bactive%5D=0&per_page=50");
  });

  it("repeats arrays", () => {
    expect(serializeQuery({ ids: ["a", "b"] })).toBe("ids=a&ids=b");
  });
});
