"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { AuthCard } from "@/components/auth/auth-card";
import { Button } from "@/components/ui/button";
import { FieldError, Input, Label } from "@/components/ui/input";
import { ApiError, toApiError } from "@/lib/api/errors";

function safeNext(next: string | null): string {
  // Only same-site paths: never redirect off-site after sign-in.
  return next && next.startsWith("/") && !next.startsWith("//") ? next : "/";
}

function LoginForm() {
  const router = useRouter();
  const params = useSearchParams();
  const [error, setError] = useState<ApiError | null>(null);
  const [pending, setPending] = useState(false);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setPending(true);
    setError(null);
    const form = new FormData(e.currentTarget);
    try {
      const res = await fetch("/api/auth/login", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ email: form.get("email"), password: form.get("password") }),
      });
      if (!res.ok) throw await toApiError(res);
      const body = await res.json();
      const next = safeNext(params.get("next"));
      router.replace(body.data.mfa_required ? `/login/mfa?next=${encodeURIComponent(next)}` : next);
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
      setPending(false);
    }
  }

  const message =
    error?.code === "rate_limited"
      ? "Too many attempts. Wait a minute and try again."
      : error?.code === "validation_failed"
        ? "Enter your email and password."
        : error
          ? "The email or password is incorrect."
          : null;

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      <div>
        <Label htmlFor="email">Email</Label>
        <Input id="email" name="email" type="email" autoComplete="username" required autoFocus />
      </div>
      <div>
        <Label htmlFor="password">Password</Label>
        <Input id="password" name="password" type="password" autoComplete="current-password" required />
      </div>
      <FieldError>{message}</FieldError>
      <Button type="submit" className="w-full" disabled={pending}>
        {pending ? "Signing in…" : "Sign in"}
      </Button>
    </form>
  );
}

export default function LoginPage() {
  return (
    <AuthCard title="Sign in" subtitle="Welcome back to your farm.">
      <Suspense>
        <LoginForm />
      </Suspense>
    </AuthCard>
  );
}
