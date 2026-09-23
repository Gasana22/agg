import { NextResponse, type NextRequest } from "next/server";

import { callApi, forwardHeaders, problem } from "@/lib/server/backend";
import { isSameOriginRequest } from "@/lib/server/csrf";
import { ACCESS_COOKIE, clearSession, REFRESH_COOKIE } from "@/lib/server/session";
import { refreshSession } from "@/lib/server/refresh";

export async function POST(req: NextRequest) {
  if (!isSameOriginRequest(req)) return problem(403, "csrf_failed", "Cross-site request refused.");

  let access = req.cookies.get(ACCESS_COOKIE)?.value;
  const refresh = req.cookies.get(REFRESH_COOKIE)?.value;

  // The access cookie may have expired: refresh once so the server-side
  // session can actually be revoked.
  if (!access && refresh) {
    const result = await refreshSession(refresh);
    if (result.ok) access = result.tokens.access_token;
  }
  if (access) {
    await callApi("/auth/logout", { method: "POST", headers: forwardHeaders(req, access) }).catch(() => undefined);
  }

  const out = new NextResponse(null, { status: 204 });
  clearSession(out);
  return out;
}
