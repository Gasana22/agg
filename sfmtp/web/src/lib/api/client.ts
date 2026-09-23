import createClient, { type Middleware } from "openapi-fetch";

import { toApiError } from "./errors";
import type { paths } from "./schema";

/**
 * Typed client for the SFMTP API, generated from packages/api-contracts.
 * Calls go through the same-origin BFF (/api/proxy), which holds the tokens.
 */
export const api = createClient<paths>({ baseUrl: "/api/proxy" });

const errors: Middleware = {
  async onResponse({ response }) {
    if (response.ok) return response;
    if (response.status === 401 && typeof window !== "undefined" && !window.location.pathname.startsWith("/login")) {
      // Full reload on purpose: drops all cached data from the expired session.
      // eslint-disable-next-line @next/next/no-location-assign-relative-destination
      window.location.assign(`/login?next=${encodeURIComponent(window.location.pathname)}`);
    }
    throw await toApiError(response);
  },
};
api.use(errors);

/** Fetch an API href returned by the server (e.g. a widget's `href`). */
export async function fetchHref<T>(href: string): Promise<T> {
  const res = await fetch(href.replace(/^\/api\/v1\//, "/api/proxy/"), { headers: { accept: "application/json" } });
  if (!res.ok) throw await toApiError(res);
  return (await res.json()) as T;
}

/** A fresh Idempotency-Key for a POST (docs/06 §1). */
export function idempotencyKey(): string {
  return crypto.randomUUID();
}

export type { components, paths } from "./schema";
