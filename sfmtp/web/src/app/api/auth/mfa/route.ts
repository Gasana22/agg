import { NextResponse, type NextRequest } from "next/server";

import { callApi, forwardHeaders, problem } from "@/lib/server/backend";
import { isSameOriginRequest } from "@/lib/server/csrf";
import { MFA_COOKIE, setSession } from "@/lib/server/session";

/** Second sign-in step. The pending MFA token stays in an httpOnly cookie. */
export async function POST(req: NextRequest) {
  if (!isSameOriginRequest(req)) return problem(403, "csrf_failed", "Cross-site request refused.");

  const mfaToken = req.cookies.get(MFA_COOKIE)?.value;
  if (!mfaToken) return problem(401, "mfa_token_invalid", "The sign-in attempt has expired. Please sign in again.");

  const input = await req.json().catch(() => ({}));
  const res = await callApi("/auth/mfa/challenge", {
    method: "POST",
    headers: forwardHeaders(req),
    body: JSON.stringify({ mfa_token: mfaToken, code: input.code ?? null, recovery_code: input.recovery_code ?? null }),
  });

  const body = await res.json().catch(() => ({}));
  if (!res.ok) return NextResponse.json(body, { status: res.status, headers: { "content-type": "application/problem+json" } });

  const out = NextResponse.json({ data: { signed_in: true } });
  setSession(out, body.data);
  return out;
}
