"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { AuthCard } from "@/components/auth/auth-card";
import { Button } from "@/components/ui/button";
import { FieldError, Input, Label } from "@/components/ui/input";
import { api, idempotencyKey } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

/** First farm for a new owner. It starts "pending" until SFMTP approves it. */
export default function OnboardingPage() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [error, setError] = useState<ApiError | null>(null);
  const [pending, setPending] = useState(false);
  const [key] = useState(idempotencyKey);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setPending(true);
    setError(null);
    const f = new FormData(e.currentTarget);
    try {
      await api.POST("/farms", {
        params: { header: { "Idempotency-Key": key } },
        body: {
          name: String(f.get("name")),
          district: String(f.get("district") || "") || null,
          village: String(f.get("village") || "") || null,
          size_ha: f.get("size_ha") ? Number(f.get("size_ha")) : null,
        },
      });
      await queryClient.invalidateQueries({ queryKey: ["workspaces"] });
      router.replace("/");
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
      setPending(false);
    }
  }

  return (
    <AuthCard title="Create your farm" subtitle="You'll be its owner. You can add blocks, plots and your team next.">
      {error?.code === "member_account_required" ? (
        <p className="text-sm text-muted">This account can&apos;t own farms.</p>
      ) : (
        <form onSubmit={onSubmit} className="space-y-4">
          <div>
            <Label htmlFor="name">Farm name</Label>
            <Input id="name" name="name" required aria-invalid={!!error?.fieldError("name")} />
            <FieldError>{error?.fieldError("name")}</FieldError>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="district">District</Label>
              <Input id="district" name="district" />
            </div>
            <div>
              <Label htmlFor="village">Village</Label>
              <Input id="village" name="village" />
            </div>
          </div>
          <div>
            <Label htmlFor="size_ha">Size (hectares)</Label>
            <Input id="size_ha" name="size_ha" type="number" min="0" step="0.01" aria-invalid={!!error?.fieldError("size_ha")} />
            <FieldError>{error?.fieldError("size_ha")}</FieldError>
          </div>
          <Button type="submit" className="w-full" disabled={pending}>
            {pending ? "Creating…" : "Create farm"}
          </Button>
        </form>
      )}
    </AuthCard>
  );
}
