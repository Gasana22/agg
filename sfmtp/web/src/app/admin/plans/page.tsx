"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useState } from "react";

import { money } from "@/components/billing/usage";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Checkbox, FieldError, Input, Label, Select } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api, type components } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

type Plan = components["schemas"]["Plan"];

export default function AdminPlansPage() {
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState<Plan | "new" | null>(null);
  const plans = useQuery({ queryKey: ["admin-plans"], queryFn: async () => (await api.GET("/admin/plans")).data!.data! });

  return (
    <>
      <PageHeader
        title="Plans & pricing"
        description="Configurable plans. Codes are permanent; retire a plan by making it inactive."
        actions={<Button onClick={() => setEditing("new")}><Plus /> New plan</Button>}
      />
      {plans.isLoading ? (
        <Skeleton className="h-48 w-full" />
      ) : plans.error ? (
        <ErrorNotice error={plans.error} />
      ) : (
        <Table>
          <thead><tr><Th>Plan</Th><Th>Price</Th><Th className="text-right">Farms</Th><Th className="text-right">Users</Th><Th className="text-right">Storage</Th><Th>Visibility</Th><Th /></tr></thead>
          <tbody>
            {plans.data!.map((p) => (
              <tr key={p.id}>
                <Td>
                  <p className="font-medium">{p.name}</p>
                  <p className="font-mono text-xs text-muted">{p.code}</p>
                </Td>
                <Td className="tabular-nums">{money(p.price)} / {p.billing_period === "yearly" ? "yr" : "mo"}</Td>
                <Td className="text-right tabular-nums">{p.limits?.farms ?? "∞"}</Td>
                <Td className="text-right tabular-nums">{p.limits?.users ?? "∞"}</Td>
                <Td className="text-right tabular-nums">{p.limits?.storage_mb ? `${Math.round(p.limits.storage_mb / 1024)} GB` : "∞"}</Td>
                <Td className="space-x-1">
                  {p.is_active ? <Badge tone="primary">active</Badge> : <Badge>inactive</Badge>}
                  {p.is_public ? <Badge>public</Badge> : <Badge tone="warning">private</Badge>}
                </Td>
                <Td className="text-right"><Button size="sm" variant="secondary" onClick={() => setEditing(p)}>Edit</Button></Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      <PlanForm
        plan={editing}
        onClose={() => setEditing(null)}
        onSaved={() => {
          setEditing(null);
          queryClient.invalidateQueries({ queryKey: ["admin-plans"] });
        }}
      />
    </>
  );
}

function PlanForm({ plan, onClose, onSaved }: { plan: Plan | "new" | null; onClose: () => void; onSaved: () => void }) {
  const [error, setError] = useState<ApiError | null>(null);
  const isNew = plan === "new";
  const p = plan && plan !== "new" ? plan : undefined;
  const num = (v: FormDataEntryValue | null) => (v === null || v === "" ? null : Number(v));

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const body = {
      name: String(f.get("name")),
      price: Number(f.get("price")),
      currency: String(f.get("currency")),
      billing_period: f.get("billing_period") as "monthly" | "yearly",
      max_farms: num(f.get("max_farms")),
      max_users: num(f.get("max_users")),
      max_storage_mb: num(f.get("max_storage_mb")),
      is_active: f.get("is_active") === "on",
      is_public: f.get("is_public") === "on",
    };
    setError(null);
    try {
      if (isNew) await api.POST("/admin/plans", { body: { ...body, code: String(f.get("code")) } });
      else await api.PATCH("/admin/plans/{plan}", { params: { path: { plan: p!.id! } }, body });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  return (
    <Dialog open={plan !== null} onClose={onClose} title={isNew ? "New plan" : `Edit ${p?.name}`} description="Leave a limit empty for unlimited.">
      <form key={p?.id ?? "new"} onSubmit={onSubmit} className="space-y-3">
        {isNew ? (
          <div>
            <Label htmlFor="code">Code</Label>
            <Input id="code" name="code" required pattern="[a-z0-9_]+" placeholder="e.g. cooperative" aria-invalid={!!error?.fieldError("code")} />
          </div>
        ) : null}
        <div>
          <Label htmlFor="name">Name</Label>
          <Input id="name" name="name" defaultValue={p?.name} required />
        </div>
        <div className="grid grid-cols-3 gap-3">
          <div className="col-span-1">
            <Label htmlFor="price">Price</Label>
            <Input id="price" name="price" type="number" min="0" step="0.01" defaultValue={p?.price?.amount ? Number(p.price.amount) : ""} required />
          </div>
          <div>
            <Label htmlFor="currency">Currency</Label>
            <Input id="currency" name="currency" defaultValue={p?.price?.currency ?? "UGX"} maxLength={3} required />
          </div>
          <div>
            <Label htmlFor="billing_period">Period</Label>
            <Select id="billing_period" name="billing_period" defaultValue={p?.billing_period ?? "monthly"}>
              <option value="monthly">Monthly</option>
              <option value="yearly">Yearly</option>
            </Select>
          </div>
        </div>
        <div className="grid grid-cols-3 gap-3">
          <div><Label htmlFor="max_farms">Farms</Label><Input id="max_farms" name="max_farms" type="number" min="1" defaultValue={p?.limits?.farms ?? ""} /></div>
          <div><Label htmlFor="max_users">Users</Label><Input id="max_users" name="max_users" type="number" min="1" defaultValue={p?.limits?.users ?? ""} /></div>
          <div><Label htmlFor="max_storage_mb">Storage MB</Label><Input id="max_storage_mb" name="max_storage_mb" type="number" min="1" defaultValue={p?.limits?.storage_mb ?? ""} /></div>
        </div>
        <div className="flex gap-6">
          <Checkbox name="is_active" label="Active" defaultChecked={p?.is_active ?? true} />
          <Checkbox name="is_public" label="Owners can choose it" defaultChecked={p?.is_public ?? true} />
        </div>
        <FieldError>{error ? (Object.values(error.problem.errors ?? {})[0]?.[0] ?? error.problem.title) : null}</FieldError>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit">Save</Button>
        </div>
      </form>
    </Dialog>
  );
}
