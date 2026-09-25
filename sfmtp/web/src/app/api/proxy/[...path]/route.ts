import { NextResponse, type NextRequest } from "next/server";

import { callApi, forwardHeaders, problem } from "@/lib/server/backend";
import { isSameOriginRequest } from "@/lib/server/csrf";
import { refreshSession } from "@/lib/server/refresh";
import { ACCESS_COOKIE, clearSession, REFRESH_COOKIE, setSession, type TokenPair } from "@/lib/server/session";

/**
 * Backend-for-frontend proxy: /api/proxy/<path> → API /api/v1/<path>.
 * Adds the bearer token from the httpOnly cookie, refreshes an expired
 * session once, and enforces same-origin for state-changing requests.
 */
const RESPONSE_HEADERS = ["content-type", "content-disposition", "x-request-id", "idempotent-replayed", "retry-after"];

/** API paths that also work signed out: the emailed invitation links (farm members and portals). */
function isPublicApiPath(path: string[]): boolean {
  return path[0] === "invitations" || path[0] === "portal-invitations";
}

async function handle(req: NextRequest, { params }: { params: Promise<{ path: string[] }> }) {
  if (!isSameOriginRequest(req)) return problem(403, "csrf_failed", "Cross-site request refused.");

  const { path } = await params;
  if (path.some((segment) => segment === ".." || segment === ".")) return problem(400, "bad_request", "Invalid path.");
  const target = "/" + path.map(encodeURIComponent).join("/") + req.nextUrl.search;

  let access = req.cookies.get(ACCESS_COOKIE)?.value;
  const refresh = req.cookies.get(REFRESH_COOKIE)?.value;
  let rotated: TokenPair | null = null;

  if (!access && refresh) {
    const result = await refreshSession(refresh);
    if (result.ok) {
      rotated = result.tokens;
      access = rotated.access_token;
    }
  }
  const anonymous = !access && isPublicApiPath(path);
  if (!access && !anonymous) return signedOut();

  const body = req.method === "GET" || req.method === "HEAD" ? undefined : await req.arrayBuffer();
  const send = (token: string | undefined) => callApi(target, { method: req.method, headers: forwardHeaders(req, token), body });

  let res: Response;
  try {
    res = await send(access);
    if (res.status === 401 && refresh && !rotated && !anonymous) {
      const result = await refreshSession(refresh);
      if (result.ok) {
        rotated = result.tokens;
        res = await send(rotated.access_token);
      }
    }
  } catch {
    return problem(503, "api_unreachable", "The SFMTP service is unreachable. Try again shortly.");
  }

  const headers = new Headers();
  for (const name of RESPONSE_HEADERS) {
    const value = res.headers.get(name);
    if (value) headers.set(name, value);
  }
  const out = new NextResponse(res.status === 204 ? null : await res.arrayBuffer(), { status: res.status, headers });

  if (res.status === 401 && !anonymous) {
    clearSession(out);
  } else if (rotated) {
    setSession(out, rotated);
  }
  return out;
}

function signedOut() {
  const res = NextResponse.json(
    { type: "https://docs.sfmtp.app/errors/unauthenticated", title: "Please sign in.", status: 401, code: "unauthenticated" },
    { status: 401, headers: { "content-type": "application/problem+json" } },
  );
  clearSession(res);
  return res;
}

export { handle as DELETE, handle as GET, handle as PATCH, handle as POST, handle as PUT };
