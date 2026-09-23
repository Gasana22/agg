"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { FieldError, Input, Label } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

export default function AdminSettingsPage() {
  const queryClient = useQueryClient();
  const [error, setError] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);
  const settings = useQuery({ queryKey: ["admin-settings"], queryFn: async () => (await api.GET("/admin/settings")).data!.data! });

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const values: Record<string, unknown> = {};
    for (const s of settings.data ?? []) {
      const raw = String(f.get(s.key!) ?? "");
      values[s.key!] = typeof s.default === "number" ? Number(raw) : raw;
    }
    setError(null);
    setSaved(false);
    try {
      await api.PUT("/admin/settings", { body: { settings: values } });
      await queryClient.invalidateQueries({ queryKey: ["admin-settings"] });
      setSaved(true);
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  if (settings.isLoading) return <Skeleton className="h-48 w-full max-w-xl" />;
  if (settings.error) return <ErrorNotice error={settings.error} />;

  return (
    <>
      <PageHeader title="Platform settings" description="Defaults for billing and support. Every change is audited." />
      <Card className="max-w-xl">
        <CardContent>
          <form onSubmit={onSubmit} className="space-y-4">
            {settings.data!.map((s) => (
              <div key={s.key}>
                <Label htmlFor={s.key}>{s.label}</Label>
                <Input id={s.key} name={s.key} defaultValue={String(s.value ?? "")} type={typeof s.default === "number" ? "number" : "text"} aria-invalid={!!error?.fieldError(`settings.${s.key}`)} />
                <FieldError>{error?.fieldError(`settings.${s.key}`)}</FieldError>
              </div>
            ))}
            <div className="flex items-center gap-3">
              <Button type="submit">Save</Button>
              {saved ? <span role="status" className="text-sm text-success">Saved</span> : null}
            </div>
          </form>
        </CardContent>
      </Card>
    </>
  );
}
