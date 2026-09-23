import "server-only";

import type { NextResponse } from "next/server";

/**
 * Tokens live only in httpOnly cookies set by this BFF: browser JavaScript
 * never sees them (docs/01-system-architecture.md §2).
 */
export const ACCESS_COOKIE = "sfmtp_at";
export const REFRESH_COOKIE = "sfmtp_rt";
export const MFA_COOKIE = "sfmtp_mfa";

export type TokenPair = {
  access_token: string;
  expires_in: number;
  refresh_token: string;
  refresh_expires_in: number;
};

function cookieOptions(maxAge: number) {
  return {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "lax" as const,
    path: "/",
    maxAge,
  };
}

export function setSession(res: NextResponse, tokens: TokenPair): void {
  // Drop the access cookie a little early so we refresh before the JWT expires.
  res.cookies.set(ACCESS_COOKIE, tokens.access_token, cookieOptions(Math.max(tokens.expires_in - 30, 30)));
  res.cookies.set(REFRESH_COOKIE, tokens.refresh_token, cookieOptions(tokens.refresh_expires_in));
  res.cookies.delete(MFA_COOKIE);
}

export function setPendingMfa(res: NextResponse, mfaToken: string): void {
  res.cookies.set(MFA_COOKIE, mfaToken, cookieOptions(300));
}

export function clearSession(res: NextResponse): void {
  for (const name of [ACCESS_COOKIE, REFRESH_COOKIE, MFA_COOKIE]) {
    res.cookies.set(name, "", { ...cookieOptions(0), maxAge: 0 });
  }
}
