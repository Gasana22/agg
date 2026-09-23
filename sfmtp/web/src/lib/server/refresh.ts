import "server-only";

import { callApi } from "./backend";
import type { TokenPair } from "./session";

type Result = { ok: true; tokens: TokenPair } | { ok: false; status: number; code?: string };

/**
 * Concurrent requests carrying the same expired session must not each
 * rotate the refresh token (the second would look like token theft).
 * Share one in-flight rotation per token, and remember the result briefly
 * so late arrivals reuse the new pair.
 */
const inflight = new Map<string, Promise<Result>>();
const RESULT_TTL_MS = 10_000;

export function refreshSession(refreshToken: string): Promise<Result> {
  const existing = inflight.get(refreshToken);
  if (existing) return existing;

  const promise = rotate(refreshToken);
  inflight.set(refreshToken, promise);
  promise.finally(() => setTimeout(() => inflight.delete(refreshToken), RESULT_TTL_MS));
  return promise;
}

async function rotate(refreshToken: string): Promise<Result> {
  try {
    const res = await callApi("/auth/refresh", {
      method: "POST",
      headers: { "content-type": "application/json", accept: "application/json" },
      body: JSON.stringify({ refresh_token: refreshToken }),
    });
    const body = await res.json().catch(() => ({}));
    if (res.ok) return { ok: true, tokens: body.data as TokenPair };
    return { ok: false, status: res.status, code: body.code };
  } catch {
    return { ok: false, status: 503, code: "api_unreachable" };
  }
}
