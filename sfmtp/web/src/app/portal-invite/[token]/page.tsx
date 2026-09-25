"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { AuthCard } from "@/components/auth/auth-card";
import { Button, buttonVariants } from "@/components/ui/button";
import { FieldError, Input, Label } from "@/components/ui/input";
import { ErrorNotice, Skeleton } from "@/components/ui/misc";
import { ApiError, toApiError } from "@/lib/api/errors";
import type { components } from "@/lib/api/schema";

type Preview = components["schemas"]["PortalInvitationPreview"];

const PORTAL = { supplier: "supplier portal", customer: "customer portal" } as const;

/*
 * Plain fetches, not the typed client: this page works signed out, and the
 * client's 401 handling would bounce the visitor to the sign-in page.
 */
async function getJson<T>(path: string): Promise<T | null> {
  const res = await fetch(`/api/proxy${path}`, { headers: { accept: "application/json" } });
  if (res.status === 401) return null;
  if (!res.ok) throw await toApiError(res);
  return (await res.json()) as T;
}

async function postJson<T>(path: string, body: unknown): Promise<T> {
  const res = await fetch(path, { method: "POST", headers: { "content-type": "application/json", accept: "application/json" }, body: JSON.stringify(body) });
  if (!res.ok) throw await toApiError(res);
  return (await res.json()) as T;
}

/** The page behind the emailed portal invitation (a supplier or customer of a farm). */
export default function PortalInvitePage() {
  const { token } = useParams<{ token: string }>();
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);

  const preview = useQuery({
    queryKey: ["portal-invitation", token],
    queryFn: async () => (await getJson<{ data: Preview }>(`/portal-invitations/${token}`))!.data,
    retry: false,
  });
  const me = useQuery({
    queryKey: ["portal-invitation-me"],
    queryFn: async () => (await getJson<{ data: { email: string; name: string } }>("/me"))?.data ?? null,
    retry: false,
  });

  async function accept(body: Record<string, string> = {}) {
    setError(null);
    setBusy(true);
    try {
      const result = await postJson<{ data: { kind: string; party: { id: string }; account_created: boolean } }>(`/api/proxy/portal-invitations/${token}/accept`, body);
      const home = `/${result.data.kind}/${result.data.party.id}`;
      // Full reloads on purpose: the session cookies just changed, so nothing cached may survive.
      if (result.data.account_created) {
        // Sign the new account in, then let the home page pick the right place (MFA set-up if the role needs it).
        const login = await postJson<{ data: { mfa_required: boolean } }>("/api/auth/login", { email: preview.data!.email, password: body.password });
        window.location.assign(login.data.mfa_required ? `/login/mfa?next=${encodeURIComponent(home)}` : home);
      } else {
        // eslint-disable-next-line @next/next/no-location-assign-relative-destination
        window.location.assign(home);
      }
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
      setBusy(false);
    }
  }

  async function signOut() {
    await fetch("/api/auth/logout", { method: "POST" });
    window.location.reload();
  }

  if (preview.isLoading || me.isLoading) {
    return (
      <AuthCard title="Invitation">
        <Skeleton className="h-32 w-full" />
      </AuthCard>
    );
  }
  if (preview.error) {
    return (
      <AuthCard title="Invitation not found" subtitle="The link may be incomplete, or a newer invitation replaced it.">
        <Link href="/login" className={buttonVariants({ variant: "secondary" })}>
          Go to sign in
        </Link>
      </AuthCard>
    );
  }

  const invite = preview.data!;
  const signedIn = me.data;
  const sameEmail = signedIn && signedIn.email.toLowerCase() === invite.email;
  const portal = PORTAL[(invite.kind ?? "customer") as keyof typeof PORTAL];
  const subtitle = `${invite.invited_by ?? "Someone"} of ${invite.farm?.name} invited ${invite.record_name ?? invite.email} (${invite.email}) to the SFMTP ${portal}.`;

  if (invite.status !== "pending") {
    const text: Record<string, string> = {
      accepted: "This invitation has already been used.",
      revoked: "This invitation was withdrawn.",
      expired: "This invitation has expired. Ask the farm for a new one.",
    };
    return (
      <AuthCard title="Invitation unavailable" subtitle={text[invite.status ?? ""] ?? ""}>
        <Link href="/" className={buttonVariants({ variant: "secondary" })}>
          Continue
        </Link>
      </AuthCard>
    );
  }

  return (
    <AuthCard title={`${invite.farm?.name}: ${portal}`} subtitle={subtitle}>
      {invite.message ? <blockquote className="mb-4 border-l-2 border-primary pl-3 text-sm italic text-muted">{invite.message}</blockquote> : null}
      {error && !error.problem.errors ? (
        <div className="mb-4">
          <ErrorNotice error={error} />
        </div>
      ) : null}

      {sameEmail ? (
        <Button className="w-full" disabled={busy} onClick={() => accept()}>
          {busy ? "Opening…" : `Open the ${portal} as ${signedIn.name}`}
        </Button>
      ) : signedIn ? (
        <div className="space-y-3 text-sm">
          <p>
            You are signed in as <strong>{signedIn.email}</strong>. This invitation is for <strong>{invite.email}</strong>.
          </p>
          <Button variant="secondary" className="w-full" onClick={signOut}>
            Sign out and continue
          </Button>
        </div>
      ) : invite.account_exists ? (
        <div className="space-y-3 text-sm">
          <p>You already have an SFMTP account. Sign in with {invite.email} to accept.</p>
          <Link href={`/login?next=${encodeURIComponent(`/portal-invite/${token}`)}`} className={buttonVariants({ className: "w-full" })}>
            Sign in to accept
          </Link>
        </div>
      ) : (
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            const f = new FormData(e.currentTarget);
            void accept({ name: String(f.get("name")), password: String(f.get("password")), password_confirmation: String(f.get("password_confirmation")) });
          }}
        >
          <div>
            <Label htmlFor="name">Your name</Label>
            <Input id="name" name="name" required minLength={2} autoComplete="name" aria-invalid={!!error?.fieldError("name")} />
            <FieldError>{error?.fieldError("name")}</FieldError>
          </div>
          <div>
            <Label htmlFor="password">Choose a password</Label>
            <Input id="password" name="password" type="password" required minLength={10} autoComplete="new-password" aria-invalid={!!error?.fieldError("password")} aria-describedby="password-help" />
            <p id="password-help" className="mt-1 text-xs text-muted">
              At least 10 characters, with letters and numbers.
            </p>
            <FieldError>{error?.fieldError("password")}</FieldError>
          </div>
          <div>
            <Label htmlFor="password_confirmation">Repeat the password</Label>
            <Input id="password_confirmation" name="password_confirmation" type="password" required autoComplete="new-password" />
          </div>
          <Button type="submit" className="w-full" disabled={busy}>
            {busy ? "Creating your account…" : `Create account and open the ${portal}`}
          </Button>
        </form>
      )}
    </AuthCard>
  );
}
