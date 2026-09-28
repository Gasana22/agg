"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { AuthCard } from "@/components/auth/auth-card";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/input";
import { ErrorNotice, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { humanize } from "@/lib/format";

/** Email and SMS copies of important notices (ADR-0018). The inbox and push always carry them. */
export default function NotificationSettingsPage() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [error, setError] = useState<unknown>(null);
  const [saved, setSaved] = useState(false);
  const prefs = useQuery({
    queryKey: ["notification-preferences"],
    queryFn: async () => (await api.GET("/me/notification-preferences")).data!.data!,
  });

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    setError(null);
    setSaved(false);
    try {
      await api.PUT("/me/notification-preferences", { body: { email: f.get("email") === "on", sms: f.get("sms") === "on" } });
      await queryClient.invalidateQueries({ queryKey: ["notification-preferences"] });
      setSaved(true);
    } catch (err) {
      setError(err);
    }
  }

  const p = prefs.data;
  return (
    <AuthCard title="Notifications" subtitle="Everything arrives in your inbox and on your phone. Choose where else important notices go.">
      {prefs.isLoading ? (
        <Skeleton className="h-32 w-full" />
      ) : prefs.error ? (
        <ErrorNotice error={prefs.error} />
      ) : p ? (
        <form onSubmit={onSubmit} className="space-y-4 text-sm">
          <Checkbox name="email" defaultChecked={p.email} label={`Email copies${p.email_address ? ` to ${p.email_address}` : ""}`} />
          <Checkbox name="sms" defaultChecked={p.sms} disabled={!p.phone} label={p.phone ? `SMS copies to ${p.phone}` : "SMS copies (add a phone number to your profile first)"} />
          <p className="text-xs text-muted">Copied: {Object.keys(p.kinds ?? {}).map(humanize).join(", ").toLowerCase()}. SMS may cost the farm; it is off until you turn it on.</p>
          {error ? <ErrorNotice error={error} /> : null}
          <div className="flex items-center gap-3">
            <Button type="submit">Save</Button>
            <Button type="button" variant="ghost" onClick={() => router.back()}>
              Back
            </Button>
            {saved ? (
              <span className="text-success" role="status">
                Saved
              </span>
            ) : null}
          </div>
        </form>
      ) : null}
    </AuthCard>
  );
}
