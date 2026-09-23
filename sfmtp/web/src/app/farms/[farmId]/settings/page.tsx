"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useParams } from "next/navigation";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { FieldError, Input, Label } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

export default function FarmSettingsPage() {
  const { farmId } = useParams<{ farmId: string }>();
  const queryClient = useQueryClient();
  const [error, setError] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);

  const farm = useQuery({
    queryKey: ["farm", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}", { params: { path: { farm: farmId } } })).data!.data!,
  });

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    setSaved(false);
    const f = new FormData(e.currentTarget);
    try {
      await api.PATCH("/farms/{farm}", {
        params: { path: { farm: farmId } },
        body: {
          name: String(f.get("name")),
          district: String(f.get("district") || "") || null,
          village: String(f.get("village") || "") || null,
          size_ha: f.get("size_ha") ? Number(f.get("size_ha")) : null,
        },
      });
      await queryClient.invalidateQueries({ queryKey: ["farm", farmId] });
      await queryClient.invalidateQueries({ queryKey: ["workspaces"] });
      setSaved(true);
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  if (farm.isLoading) return <Skeleton className="h-64 w-full max-w-xl" />;
  if (farm.error) return <ErrorNotice error={farm.error} />;
  const f = farm.data!;

  return (
    <>
      <PageHeader title="Farm settings" description={`Farm code ${f.code} · ${f.currency} · ${f.timezone}`} />
      <Card className="max-w-xl">
        <CardContent>
          <form onSubmit={onSubmit} className="space-y-4">
            <div>
              <Label htmlFor="name">Farm name</Label>
              <Input id="name" name="name" defaultValue={f.name} required aria-invalid={!!error?.fieldError("name")} />
              <FieldError>{error?.fieldError("name")}</FieldError>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label htmlFor="district">District</Label>
                <Input id="district" name="district" defaultValue={f.district ?? ""} />
              </div>
              <div>
                <Label htmlFor="village">Village</Label>
                <Input id="village" name="village" defaultValue={f.village ?? ""} />
              </div>
            </div>
            <div>
              <Label htmlFor="size_ha">Size (hectares)</Label>
              <Input id="size_ha" name="size_ha" type="number" min="0" step="0.01" defaultValue={f.size_ha ?? ""} aria-invalid={!!error?.fieldError("size_ha")} />
              <FieldError>{error?.fieldError("size_ha")}</FieldError>
            </div>
            <div className="flex items-center gap-3">
              <Button type="submit">Save</Button>
              {saved ? <span className="text-sm text-success" role="status">Saved</span> : null}
            </div>
          </form>
        </CardContent>
      </Card>
    </>
  );
}
