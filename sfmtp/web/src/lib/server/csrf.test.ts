import { NextRequest } from "next/server";
import { describe, expect, it } from "vitest";

import { isSameOriginRequest } from "./csrf";

const req = (method: string, headers: Record<string, string> = {}) =>
  new NextRequest("http://farm.example/api/proxy/farms", { method, headers: { host: "farm.example", ...headers } });

describe("isSameOriginRequest", () => {
  it("allows safe methods from anywhere", () => {
    expect(isSameOriginRequest(req("GET", { origin: "https://evil.example" }))).toBe(true);
  });

  it("allows same-origin writes and refuses cross-site ones", () => {
    expect(isSameOriginRequest(req("POST", { origin: "http://farm.example" }))).toBe(true);
    expect(isSameOriginRequest(req("POST", { origin: "https://evil.example" }))).toBe(false);
  });

  it("falls back to Referer, and refuses writes with neither header", () => {
    expect(isSameOriginRequest(req("DELETE", { referer: "http://farm.example/farms/1" }))).toBe(true);
    expect(isSameOriginRequest(req("DELETE", { referer: "https://evil.example/x" }))).toBe(false);
    expect(isSameOriginRequest(req("PATCH"))).toBe(false);
  });

  it("respects the proxy's forwarded host and scheme", () => {
    expect(isSameOriginRequest(req("POST", { origin: "https://app.sfmtp.example", "x-forwarded-host": "app.sfmtp.example", "x-forwarded-proto": "https" }))).toBe(true);
  });
});
