import "server-only";

import type { NextRequest } from "next/server";

const SAFE_METHODS = new Set(["GET", "HEAD", "OPTIONS"]);

/**
 * CSRF defence for cookie-authenticated BFF routes: state-changing requests
 * must come from this origin (Origin header, or Referer as a fallback).
 */
export function isSameOriginRequest(req: NextRequest): boolean {
  if (SAFE_METHODS.has(req.method)) return true;

  const expected = expectedOrigin(req);
  const origin = req.headers.get("origin");
  if (origin) return origin === expected;

  const referer = req.headers.get("referer");
  if (referer) {
    try {
      return new URL(referer).origin === expected;
    } catch {
      return false;
    }
  }
  return false;
}

function expectedOrigin(req: NextRequest): string {
  const host = req.headers.get("x-forwarded-host") ?? req.headers.get("host") ?? req.nextUrl.host;
  const proto = req.headers.get("x-forwarded-proto") ?? req.nextUrl.protocol.replace(":", "");
  return `${proto}://${host}`;
}
