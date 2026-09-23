import { NextResponse, type NextRequest } from "next/server";

import { callApi, forwardHeaders, problem } from "@/lib/server/backend";
import { isSameOriginRequest } from "@/lib/server/csrf";
import { setPendingMfa, setSession } from "@/lib/server/session";

export async function POST(req: NextRequest) {
  if (!isSameOriginRequest(req)) return problem(403, "csrf_failed", "Cross-site request refused.");

  const input = await req.json().catch(() => ({}));
  const res = await callApi("/auth/login", {
    method: "POST",
    headers: forwardHeaders(req),
    body: JSON.stringify({
      email: input.email,
      password: input.password,
      client: "web",
      device: { name: deviceName(req.headers.get("user-agent")), platform: "web" },
    }),
  });

  const body = await res.json().catch(() => ({}));
  if (!res.ok) return NextResponse.json(body, { status: res.status, headers: { "content-type": "application/problem+json" } });

  const out = NextResponse.json({ data: { mfa_required: Boolean(body.data?.mfa_required) } });
  if (body.data?.mfa_required) {
    setPendingMfa(out, body.data.mfa_token);
  } else {
    setSession(out, body.data);
  }
  return out;
}

function deviceName(ua: string | null): string {
  if (!ua) return "Web browser";
  const browser = /Edg\//.test(ua) ? "Edge" : /Firefox\//.test(ua) ? "Firefox" : /Chrome\//.test(ua) ? "Chrome" : /Safari\//.test(ua) ? "Safari" : "Browser";
  const os = /Windows/.test(ua) ? "Windows" : /Android/.test(ua) ? "Android" : /iPhone|iPad/.test(ua) ? "iOS" : /Mac OS X/.test(ua) ? "macOS" : /Linux/.test(ua) ? "Linux" : "";
  return os ? `${browser} on ${os}` : browser;
}
