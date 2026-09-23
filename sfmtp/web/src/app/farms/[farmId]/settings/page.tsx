"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useParams } from "next/navigation";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox, FieldError, Input, Label, Select } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { can } from "@/lib/permissions";

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
      <FarmPolicies farmId={farmId} currency={f.currency ?? ""} />
    </>
  );
}

/** Farm-wide policy: MFA for everyone, approval thresholds, stock rules. */
function FarmPolicies({ farmId, currency }: { farmId: string; currency: string }) {
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const editable = workspace?.type === "farm" && can(workspace.permissions, "farm.settings.manage");
  const [error, setError] = useState<ApiError | null>(null);
  const [saved, setSaved] = useState(false);

  const settings = useQuery({
    queryKey: ["farm-settings", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/settings", { params: { path: { farm: farmId } } })).data!.data!,
  });

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    setSaved(false);
    const f = new FormData(e.currentTarget);
    const amount = (k: string) => (String(f.get(k) ?? "").trim() === "" ? null : Number(f.get(k)));
    try {
      await api.PATCH("/farms/{farm}/settings", {
        params: { path: { farm: farmId } },
        body: {
          require_mfa_for_all: f.get("require_mfa_for_all") === "on",
          allow_negative_stock: f.get("allow_negative_stock") === "on",
          units: String(f.get("units")) as "metric" | "imperial",
          approval_thresholds: {
            expense: amount("expense"),
            purchase_order: amount("purchase_order"),
            stock_adjustment_pct: amount("stock_adjustment_pct"),
          },
        },
      });
      await queryClient.invalidateQueries({ queryKey: ["farm-settings", farmId] });
      await queryClient.invalidateQueries({ queryKey: ["workspaces"] });
      setSaved(true);
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  if (settings.isLoading) return <Skeleton className="mt-6 h-64 w-full max-w-xl" />;
  if (settings.error) return <ErrorNotice error={settings.error} />;
  const s = settings.data!;
  const t = s.approval_thresholds ?? {};

  return (
    <Card className="mt-6 max-w-xl">
      <CardContent>
        <h2 className="mb-1 text-lg font-semibold">Policies</h2>
        <p className="mb-4 text-sm text-muted">Rules that apply to everyone on this farm.</p>
        <form onSubmit={onSubmit} className="space-y-4">
          <fieldset disabled={!editable} className="space-y-4">
            <Checkbox name="require_mfa_for_all" defaultChecked={s.require_mfa_for_all} label="Require two-step sign-in (MFA) for every member" />
            <div>
              <p className="mb-2 text-sm font-medium">Owner approval needed above</p>
              <div className="grid grid-cols-3 gap-3">
                <div>
                  <Label htmlFor="expense">Expense ({currency})</Label>
                  <Input id="expense" name="expense" type="number" min="0" step="1" defaultValue={t.expense ?? ""} placeholder="No limit" aria-invalid={!!error?.fieldError("approval_thresholds.expense")} />
                  <FieldError>{error?.fieldError("approval_thresholds.expense")}</FieldError>
                </div>
                <div>
                  <Label htmlFor="purchase_order">Purchase order</Label>
                  <Input id="purchase_order" name="purchase_order" type="number" min="0" step="1" defaultValue={t.purchase_order ?? ""} placeholder="No limit" />
                  <FieldError>{error?.fieldError("approval_thresholds.purchase_order")}</FieldError>
                </div>
                <div>
                  <Label htmlFor="stock_adjustment_pct">Stock adjustment (%)</Label>
                  <Input id="stock_adjustment_pct" name="stock_adjustment_pct" type="number" min="0" max="100" step="0.1" defaultValue={t.stock_adjustment_pct ?? ""} placeholder="No limit" />
                  <FieldError>{error?.fieldError("approval_thresholds.stock_adjustment_pct")}</FieldError>
                </div>
              </div>
              <p className="mt-1 text-xs text-muted">Below a threshold the Farm Manager&apos;s approval is enough. These apply as the finance and inventory modules arrive.</p>
            </div>
            <Checkbox name="allow_negative_stock" defaultChecked={s.allow_negative_stock} label="Allow stock to go below zero" />
            <div className="max-w-48">
              <Label htmlFor="units">Units</Label>
              <Select id="units" name="units" defaultValue={s.units ?? "metric"}>
                <option value="metric">Metric</option>
                <option value="imperial">Imperial</option>
              </Select>
            </div>
            {editable ? (
              <div className="flex items-center gap-3">
                <Button type="submit">Save policies</Button>
                {saved ? <span className="text-sm text-success" role="status">Saved</span> : null}
              </div>
            ) : (
              <p className="text-sm text-muted">Only the farm owner can change these.</p>
            )}
          </fieldset>
          {error && !error.problem.errors ? <ErrorNotice error={error} /> : null}
        </form>
      </CardContent>
    </Card>
  );
}
