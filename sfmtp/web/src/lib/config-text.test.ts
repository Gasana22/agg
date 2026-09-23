import { describe, expect, it } from "vitest";

import { configText, parseConfig } from "./config-text";

describe("integration config text", () => {
  it("parses KEY=value lines, keeping '=' inside values and nulling empty ones", () => {
    expect(parseConfig("username=sfmtp\napi_key=••••abcd\nurl=https://x.test/?a=b\nsender_id=\n  junk line\n")).toEqual({
      username: "sfmtp",
      api_key: "••••abcd",
      url: "https://x.test/?a=b",
      sender_id: null,
    });
  });

  it("round-trips", () => {
    const config = { a: "1", b: "two" };
    expect(parseConfig(configText(config))).toEqual(config);
  });
});
