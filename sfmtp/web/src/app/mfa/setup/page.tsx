"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import QRCode from "qrcode";
import { useEffect, useState } from "react";

import { AuthCard } from "@/components/auth/auth-card";
import { Button } from "@/components/ui/button";
import { FieldError, Input, Label } from "@/components/ui/input";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

type Setup = { secret: string; otpauth_uri: string };

/** Mandatory for owners, accountants and platform admins (docs/01 §6). */
export default function MfaSetupPage() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [setup, setSetup] = useState<Setup | null>(null);
  const [qr, setQr] = useState<string | null>(null);
  const [codes, setCodes] = useState<string[] | null>(null);
  const [error, setError] = useState<ApiError | null>(null);

  useEffect(() => {
    api
      .POST("/auth/mfa/setup")
      .then(async ({ data }) => {
        const s = data!.data as Setup;
        setSetup(s);
        setQr(await QRCode.toDataURL(s.otpauth_uri, { margin: 1, width: 200 }));
      })
      .catch((err) => {
        if (err instanceof ApiError && err.code === "mfa_already_enabled") router.replace("/");
        else setError(err instanceof ApiError ? err : null);
      });
  }, [router]);

  async function confirm(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    const code = String(new FormData(e.currentTarget).get("code") ?? "").trim();
    try {
      const { data } = await api.POST("/auth/mfa/confirm", { body: { code } });
      setCodes(data!.data!.recovery_codes ?? []);
      // Drop cached workspaces: the next redirect must see MFA as enabled,
      // not stale data that would send the user back here.
      queryClient.removeQueries({ queryKey: ["workspaces"] });
      queryClient.removeQueries({ queryKey: ["me"] });
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  if (codes) {
    return (
      <AuthCard title="Save your recovery codes" subtitle="Each code works once if you lose your phone. They won't be shown again.">
        <ul className="grid grid-cols-2 gap-2 rounded-lg bg-surface-muted p-4 font-mono text-sm">
          {codes.map((c) => (
            <li key={c}>{c}</li>
          ))}
        </ul>
        <Button className="mt-6 w-full" onClick={() => router.replace("/")}>
          I&apos;ve saved them — continue
        </Button>
      </AuthCard>
    );
  }

  return (
    <AuthCard title="Set up two-step verification" subtitle="Your role requires it. Scan the code with an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password …).">
      <div className="mb-5 grid place-items-center">
        {qr ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={qr} alt="QR code for your authenticator app" className="size-48 rounded-lg bg-white p-2" />
        ) : (
          <div className="size-48 animate-pulse rounded-lg bg-surface-muted" />
        )}
        {setup ? (
          <p className="mt-3 break-all text-center font-mono text-xs text-muted" aria-label="Setup key">
            {setup.secret}
          </p>
        ) : null}
      </div>
      <form onSubmit={confirm} className="space-y-4">
        <div>
          <Label htmlFor="code">6-digit code</Label>
          <Input id="code" name="code" inputMode="numeric" autoComplete="one-time-code" required />
        </div>
        <FieldError>{error ? "That code didn't work. Check your phone's time and try again." : null}</FieldError>
        <Button type="submit" className="w-full" disabled={!setup}>
          Turn on
        </Button>
      </form>
    </AuthCard>
  );
}
