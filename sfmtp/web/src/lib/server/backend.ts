import "server-only";

import type { NextRequest } from "next/server";

export function apiBase(): string {
  return (process.env.SFMTP_API_URL ?? "http://localhost:8000").replace(/\/$/, "") + "/api/v1";
}

/** Headers forwarded from the browser to the API. */
const FORWARDED = ["accept", "content-type", "idempotency-key", "x-request-id", "user-agent"];

export function forwardHeaders(req: NextRequest, accessToken?: string): Headers {
  const headers = new Headers({ accept: "application/json" });
  for (const name of FORWARDED) {
    const value = req.headers.get(name);
    if (value) headers.set(name, value);
  }
  const clientIp = req.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ?? req.headers.get("x-real-ip");
  if (clientIp) headers.set("x-forwarded-for", clientIp);
  if (accessToken) headers.set("authorization", `Bearer ${accessToken}`);
  return headers;
}

export async function callApi(path: string, init: RequestInit): Promise<Response> {
  return fetch(apiBase() + path, { ...init, cache: "no-store", redirect: "manual" });
}

/** RFC 9457 problem response from the BFF itself. */
export function problem(status: number, code: string, title: string): Response {
  return new Response(JSON.stringify({ type: `https://docs.sfmtp.app/errors/${code.replaceAll("_", "-")}`, title, status, code }), {
    status,
    headers: { "content-type": "application/problem+json" },
  });
}
