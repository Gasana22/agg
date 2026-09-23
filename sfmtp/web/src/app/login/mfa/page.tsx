"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { AuthCard } from "@/components/auth/auth-card";
import { Button } from "@/components/ui/button";
import { FieldError, Input, Label } from "@/components/ui/input";
import { ApiError, toApiError } from "@/lib/api/errors";

function MfaForm() {
  const router = useRouter();
  const params = useSearchParams();
  const [useRecovery, setUseRecovery] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [pending, setPending] = useState(false);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setPending(true);
    setError(null);
    const value = String(new FormData(e.currentTarget).get("code") ?? "").trim();
    try {
      const res = await fetch("/api/auth/mfa", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(useRecovery ? { recovery_code: value } : { code: value }),
      });
      if (!res.ok) throw await toApiError(res);
      const next = params.get("next");
      router.replace(next && next.startsWith("/") && !next.startsWith("//") ? next : "/");
    } catch (err) {
      const apiError = err instanceof ApiError ? err : null;
      if (apiError?.code === "mfa_token_invalid") router.replace("/login");
      setError(apiError);
      setPending(false);
    }
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4">
      <div>
        <Label htmlFor="code">{useRecovery ? "Recovery code" : "Authentication code"}</Label>
        <Input
          id="code"
          name="code"
          key={String(useRecovery)}
          inputMode={useRecovery ? "text" : "numeric"}
          autoComplete="one-time-code"
          placeholder={useRecovery ? "XXXXX-XXXXX" : "123456"}
          required
          autoFocus
        />
      </div>
      <FieldError>{error ? (error.code === "rate_limited" ? "Too many attempts. Wait a minute." : "That code didn't work. Try again.") : null}</FieldError>
      <Button type="submit" className="w-full" disabled={pending}>
        {pending ? "Checking…" : "Verify"}
      </Button>
      <button type="button" onClick={() => setUseRecovery(!useRecovery)} className="w-full text-center text-sm text-primary hover:underline">
        {useRecovery ? "Use your authenticator app instead" : "Lost your device? Use a recovery code"}
      </button>
    </form>
  );
}

export default function MfaPage() {
  return (
    <AuthCard title="Two-step verification" subtitle="Enter the 6-digit code from your authenticator app.">
      <Suspense>
        <MfaForm />
      </Suspense>
    </AuthCard>
  );
}
