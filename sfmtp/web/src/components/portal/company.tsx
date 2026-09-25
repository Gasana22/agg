"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { FieldError, Input, Label } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

/** The party's own details, shared by both portals. Farms keep their own copy. */
export function CompanyPage({ partyId }: { partyId: string }) {
  const queryClient = useQueryClient();
  const [error, setError] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);
  const profile = useQuery({
    queryKey: ["party-profile", partyId],
    queryFn: async () => (await api.GET("/parties/{party}/profile", { params: { path: { party: partyId } } })).data!.data!,
  });

  if (profile.isLoading) return <Skeleton className="h-64 w-full" />;
  if (profile.error) return <ErrorNotice error={profile.error} />;
  const p = profile.data!;
  const fields = [
    ["name", "Company name"],
    ["email", "Email"],
    ["phone", "Phone"],
    ["address", "Address"],
    ["tax_id", "Tax ID (TIN)"],
  ] as const;

  return (
    <>
      <PageHeader title="Company details" description="Shown to the farms you work with. Each farm also keeps its own record of you." />
      <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
        <Card>
          <CardContent className="pt-6">
            <form
              className="space-y-4"
              onSubmit={async (e) => {
                e.preventDefault();
                setError(null);
                setSaved(false);
                const f = new FormData(e.currentTarget);
                const body = Object.fromEntries(fields.map(([k]) => [k, String(f.get(k) ?? "").trim() || null]));
                try {
                  await api.PATCH("/parties/{party}/profile", { params: { path: { party: partyId } }, body: { ...body, name: body.name ?? p.name, version: p.version! } });
                  await queryClient.invalidateQueries({ queryKey: ["party-profile", partyId] });
                  await queryClient.invalidateQueries({ queryKey: ["workspaces"] });
                  setSaved(true);
                } catch (err) {
                  setError(err instanceof ApiError ? err : null);
                }
              }}
            >
              {fields.map(([key, label]) => (
                <div key={key}>
                  <Label htmlFor={key}>{label}</Label>
                  <Input id={key} name={key} defaultValue={(p[key] as string | null | undefined) ?? ""} required={key === "name"} />
                  <FieldError>{error?.fieldError(key)}</FieldError>
                </div>
              ))}
              {error && !error.problem.errors ? <p className="text-sm text-danger">{error.problem.title}</p> : null}
              <div className="flex items-center gap-3">
                <Button type="submit">Save</Button>
                {saved ? <span className="text-sm text-muted">Saved.</span> : null}
              </div>
            </form>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>People</CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="space-y-2 text-sm">
              {(p.people ?? []).map((u) => (
                <li key={u.id}>
                  <p className="font-medium">{u.name}</p>
                  <p className="text-muted">{u.email}</p>
                </li>
              ))}
            </ul>
            <p className="mt-4 text-xs text-muted">To add a colleague, ask the farm to invite their email address to your record.</p>
          </CardContent>
        </Card>
      </div>
    </>
  );
}
