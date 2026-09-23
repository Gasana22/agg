import { afterEach, describe, expect, it, vi } from "vitest";

import { refreshSession } from "./refresh";

afterEach(() => vi.unstubAllGlobals());

describe("refreshSession", () => {
  it("rotates a refresh token only once for concurrent requests", async () => {
    const fetchMock = vi.fn(async () =>
      new Response(JSON.stringify({ data: { access_token: "a2", expires_in: 900, refresh_token: "r2", refresh_expires_in: 604800 } }), { status: 200 }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const [a, b, c] = await Promise.all([refreshSession("r1"), refreshSession("r1"), refreshSession("r1")]);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(a).toEqual(b);
    expect(c.ok && c.tokens.refresh_token).toBe("r2");
  });

  it("reports the API's error code", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => new Response(JSON.stringify({ code: "token_reuse_detected" }), { status: 401 })));

    expect(await refreshSession("stolen")).toEqual({ ok: false, status: 401, code: "token_reuse_detected" });
  });
});
