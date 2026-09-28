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
import { formatDateTime, humanize } from "@/lib/format";
import { HEALTH_TONE, PROVIDER_SETTINGS, webhookUrl } from "@/lib/integrations";

type Integration = components["schemas"]["Integration"];
type Kind = components["schemas"]["IntegrationKind"];

export default function AdminIntegrationsPage() {
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState<Integration | "new" | null>(null);
  const list = useQuery({ queryKey: ["admin-integrations"], queryFn: async () => (await api.GET("/admin/integrations")).data! });

  return (
    <>
      <PageHeader title="Integrations" description="Providers of a kind are tried in order: the default first, then by priority. A failing one is skipped for five minutes after three failures. Credentials are encrypted and never shown again after saving." actions={<Button onClick={() => setEditing("new")}><Plus /> Add provider</Button>} />
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
                  {p.health ? <Badge tone={HEALTH_TONE[p.health.status ?? "unknown"]}>{p.health.status}</Badge> : null}
                </div>
              </CardHeader>
              <CardContent className="space-y-3 text-sm">
                <p className="text-muted">{humanize(p.provider ?? "")}</p>
                <dl className="grid grid-cols-[auto_1fr] gap-x-3 font-mono text-xs">
                  {Object.entries(p.config ?? {}).map(([k, v]) => (<div key={k} className="contents"><dt className="text-muted">{k}</dt><dd className="truncate">{v}</dd></div>))}
                </dl>
                {p.kind === "payment" && p.provider !== "manual" ? (
                  <p className="text-xs text-muted">
                    Webhook URL for the provider dashboard: <code className="break-all">{webhookUrl(process.env.NEXT_PUBLIC_API_URL ?? "https://<your API host>", p.provider ?? "")}</code>
                  </p>
                ) : null}
                <Health integration={p} />
                <div className="flex flex-wrap gap-2">
                  <Button size="sm" variant="secondary" onClick={() => setEditing(p)}>Edit</Button>
                  <TestButton integration={p} onDone={() => queryClient.invalidateQueries({ queryKey: ["admin-integrations"] })} />
                </div>
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
  const [provider, setProvider] = useState<string>("");
  const hint = PROVIDER_SETTINGS[p?.provider ?? (provider || providers[kind]?.[0] || "")];

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const config = parseConfig(String(f.get("config") ?? ""));
    if (p) for (const key of Object.keys(p.config ?? {})) if (!(key in config)) config[key] = null;   // removed lines
    const common = { name: String(f.get("name")), config, is_enabled: f.get("is_enabled") === "on", is_default: f.get("is_default") === "on", priority: Number(f.get("priority") || 100) };
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
              <Select id="kind" name="kind" value={kind} onChange={(e) => { setKind(e.target.value); setProvider(""); }}>{Object.keys(providers).map((k) => <option key={k} value={k}>{humanize(k)}</option>)}</Select>
            </div>
            <div>
              <Label htmlFor="provider">Provider</Label>
              <Select id="provider" name="provider" value={provider || providers[kind]?.[0]} onChange={(e) => setProvider(e.target.value)}>{(providers[kind] ?? []).map((pr) => <option key={pr} value={pr}>{humanize(pr)}</option>)}</Select>
            </div>
          </div>
        ) : null}
        <div><Label htmlFor="name">Display name</Label><Input id="name" name="name" defaultValue={p?.name} required /></div>
        <div>
          <Label htmlFor="config">Configuration</Label>
          <Textarea id="config" name="config" className="font-mono text-xs" defaultValue={configText(p?.config)} placeholder={hint ?? "api_key=…"} />
          {hint ? <p className="mt-1 whitespace-pre-line text-xs text-muted">Settings: {hint}</p> : null}
        </div>
        <div className="w-40"><Label htmlFor="priority">Priority (failover order)</Label><Input id="priority" name="priority" type="number" min={0} max={1000} defaultValue={p?.priority ?? 100} /></div>
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

function Health({ integration: p }: { integration: Integration }) {
  const h = p.health;
  if (!h || (!h.last_success_at && !h.last_failure_at)) return <p className="text-xs text-muted">Not used yet.</p>;
  return (
    <div className="space-y-0.5 text-xs text-muted">
      {h.last_success_at ? <p>Last worked {formatDateTime(h.last_success_at)}</p> : null}
      {h.last_failure_at ? (
        <p className={h.consecutive_failures ? "text-danger" : undefined}>
          Last failed {formatDateTime(h.last_failure_at)}{h.last_error ? `: ${h.last_error}` : ""}
          {h.consecutive_failures ? ` (${h.consecutive_failures} in a row)` : ""}
        </p>
      ) : null}
    </div>
  );
}

function TestButton({ integration: p, onDone }: { integration: Integration; onDone: () => void }) {
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [phone, setPhone] = useState("");
  const needsPhone = p.kind === "sms";

  async function run() {
    setBusy(true);
    setResult(null);
    try {
      const res = await api.POST("/admin/integrations/{integration}/test", { params: { path: { integration: p.id! } }, body: needsPhone ? { phone: phone || null } : {} });
      setResult({ ok: Boolean(res.data?.data?.ok), message: res.data?.data?.message ?? "" });
      onDone();
    } catch (err) {
      setResult({ ok: false, message: err instanceof ApiError ? (Object.values(err.problem.errors ?? {})[0]?.[0] ?? err.problem.title ?? "Test failed") : "Test failed" });
    } finally {
      setBusy(false);
    }
  }

  if (p.kind === "accounting") return null;
  return (
    <div className="flex w-full flex-wrap items-center gap-2">
      {needsPhone ? <Input aria-label="Phone for the test message" className="h-8 w-44" placeholder="Phone (+256…)" value={phone} onChange={(e) => setPhone(e.target.value)} /> : null}
      <Button size="sm" variant="ghost" disabled={busy} onClick={run}>{busy ? "Testing…" : "Test"}</Button>
      {result ? <span role="status" className={result.ok ? "text-xs text-success" : "text-xs text-danger"}>{result.message}</span> : null}
    </div>
  );
}
