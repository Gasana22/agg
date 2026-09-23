"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog } from "@/components/ui/dialog";
import { Checkbox, FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api, type components } from "@/lib/api/client";
import { configText, parseConfig } from "@/lib/config-text";
import { ApiError } from "@/lib/api/errors";
import { humanize } from "@/lib/format";

type Integration = components["schemas"]["Integration"];
type Kind = components["schemas"]["IntegrationKind"];

export default function AdminIntegrationsPage() {
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState<Integration | "new" | null>(null);
  const list = useQuery({ queryKey: ["admin-integrations"], queryFn: async () => (await api.GET("/admin/integrations")).data! });

  return (
    <>
      <PageHeader title="Integrations" description="Provider credentials are encrypted and never shown again after saving. Live connections arrive in Phase 14." actions={<Button onClick={() => setEditing("new")}><Plus /> Add provider</Button>} />
      {list.isLoading ? (
        <Skeleton className="h-48 w-full" />
      ) : list.error ? (
        <ErrorNotice error={list.error} />
      ) : (list.data?.data ?? []).length === 0 ? (
        <EmptyState title="No providers configured" />
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {list.data!.data!.map((p) => (
            <Card key={p.id}>
              <CardHeader>
                <CardTitle>{p.name}</CardTitle>
                <div className="space-x-1">
                  <Badge>{p.kind}</Badge>
                  {p.is_default ? <Badge tone="primary">default</Badge> : null}
                  {!p.is_enabled ? <Badge tone="danger">disabled</Badge> : null}
                </div>
              </CardHeader>
              <CardContent className="space-y-3 text-sm">
                <p className="text-muted">{humanize(p.provider ?? "")}</p>
                <dl className="grid grid-cols-[auto_1fr] gap-x-3 font-mono text-xs">
                  {Object.entries(p.config ?? {}).map(([k, v]) => (<div key={k} className="contents"><dt className="text-muted">{k}</dt><dd className="truncate">{v}</dd></div>))}
                </dl>
                <Button size="sm" variant="secondary" onClick={() => setEditing(p)}>Edit</Button>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
      <IntegrationForm
        target={editing}
        providers={(list.data?.meta?.providers ?? {}) as Record<string, string[]>}
        onClose={() => setEditing(null)}
        onDone={() => { setEditing(null); queryClient.invalidateQueries({ queryKey: ["admin-integrations"] }); }}
      />
    </>
  );
}

function IntegrationForm({ target, providers, onClose, onDone }: { target: Integration | "new" | null; providers: Record<string, string[]>; onClose: () => void; onDone: () => void }) {
  const [error, setError] = useState<ApiError | null>(null);
  const isNew = target === "new";
  const p = target && target !== "new" ? target : undefined;
  const [kind, setKind] = useState<string>("sms");

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const config = parseConfig(String(f.get("config") ?? ""));
    if (p) for (const key of Object.keys(p.config ?? {})) if (!(key in config)) config[key] = null;   // removed lines
    const common = { name: String(f.get("name")), config, is_enabled: f.get("is_enabled") === "on", is_default: f.get("is_default") === "on" };
    setError(null);
    try {
      if (isNew) await api.POST("/admin/integrations", { body: { ...common, kind: f.get("kind") as Kind, provider: String(f.get("provider")) } });
      else await api.PATCH("/admin/integrations/{integration}", { params: { path: { integration: p!.id! } }, body: common });
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  return (
    <Dialog open={target !== null} onClose={onClose} title={isNew ? "Add provider" : `Edit ${p?.name}`} description="One KEY=value per line. Leave a masked value (••••1234) untouched to keep the stored secret; delete a line to remove it.">
      <form key={p?.id ?? "new"} onSubmit={onSubmit} className="space-y-3">
        {isNew ? (
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="kind">Kind</Label>
              <Select id="kind" name="kind" value={kind} onChange={(e) => setKind(e.target.value)}>{Object.keys(providers).map((k) => <option key={k} value={k}>{humanize(k)}</option>)}</Select>
            </div>
            <div>
              <Label htmlFor="provider">Provider</Label>
              <Select id="provider" name="provider">{(providers[kind] ?? []).map((pr) => <option key={pr} value={pr}>{humanize(pr)}</option>)}</Select>
            </div>
          </div>
        ) : null}
        <div><Label htmlFor="name">Display name</Label><Input id="name" name="name" defaultValue={p?.name} required /></div>
        <div>
          <Label htmlFor="config">Configuration</Label>
          <Textarea id="config" name="config" className="font-mono text-xs" defaultValue={configText(p?.config)} placeholder={"username=sfmtp\napi_key=…"} />
        </div>
        <div className="flex gap-6">
          <Checkbox name="is_enabled" label="Enabled" defaultChecked={p?.is_enabled ?? true} />
          <Checkbox name="is_default" label="Default for this kind" defaultChecked={p?.is_default ?? false} />
        </div>
        <FieldError>{error ? (Object.values(error.problem.errors ?? {})[0]?.[0] ?? error.problem.title) : null}</FieldError>
        <div className="flex justify-between gap-2">
          {p ? <Button type="button" variant="danger" onClick={async () => { await api.DELETE("/admin/integrations/{integration}", { params: { path: { integration: p.id! } } }); onDone(); }}>Remove</Button> : <span />}
          <div className="flex gap-2">
            <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
            <Button type="submit">Save</Button>
          </div>
        </div>
      </form>
    </Dialog>
  );
}
