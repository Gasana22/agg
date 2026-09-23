"use client";

import { useQuery } from "@tanstack/react-query";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status";
import { api } from "@/lib/api/client";
import { formatDateTime } from "@/lib/format";

function bytes(n?: number | null): string {
  if (n == null) return "—";
  const units = ["B", "KB", "MB", "GB", "TB"];
  let i = 0;
  let v = n;
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
  return `${v.toFixed(i ? 1 : 0)} ${units[i]}`;
}

export default function AdminSystemPage() {
  const health = useQuery({ queryKey: ["admin-health"], queryFn: async () => (await api.GET("/admin/system/health")).data!.data!, refetchInterval: 30_000 });
  const backups = useQuery({ queryKey: ["admin-backups"], queryFn: async () => (await api.GET("/admin/system/backups")).data!.data! });
  const failed = useQuery({ queryKey: ["admin-failed-jobs"], queryFn: async () => (await api.GET("/admin/system/failed-jobs")).data!.data! });
  const audit = useQuery({ queryKey: ["admin-platform-audit"], queryFn: async () => (await api.GET("/admin/system/audit-logs", { params: { query: { per_page: 50 } } })).data!.data! });

  return (
    <>
      <PageHeader title="System" description="Health, backups, failed jobs and the platform audit trail." />
      {health.error ? <ErrorNotice error={health.error} /> : null}
      <section aria-label="Health" className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {health.isLoading
          ? Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-24" />)
          : Object.entries(health.data?.checks ?? {}).map(([name, check]) => (
              <div key={name} className="rounded-xl border border-border bg-surface p-4">
                <p className="text-sm capitalize text-muted">{name}</p>
                <p className="mt-2"><StatusBadge status={check.status} /></p>
                <p className="mt-1 text-xs text-muted">
                  {name === "storage" && check.detail ? `${bytes((check.detail as { free_bytes?: number }).free_bytes)} free` : `${check.latency_ms} ms`}
                  {name === "queue" && check.detail ? ` · ${(check.detail as { failed?: number }).failed ?? 0} failed` : ""}
                </p>
              </div>
            ))}
      </section>
      {health.data?.versions ? <p className="mt-2 text-xs text-muted">App {health.data.versions.app} · PHP {health.data.versions.php} · Laravel {health.data.versions.laravel}</p> : null}

      <div className="mt-6 grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader><CardTitle>Backups</CardTitle></CardHeader>
          <CardContent>
            {(backups.data ?? []).length === 0 ? <EmptyState title="No backup runs recorded">The daily backup script records each run here.</EmptyState> : (
              <Table>
                <thead><tr><Th>Finished</Th><Th>Status</Th><Th className="text-right">Size</Th></tr></thead>
                <tbody>{backups.data!.map((b) => (<tr key={b.id}><Td className="text-muted">{formatDateTime(b.finished_at)}</Td><Td><StatusBadge status={b.status} /></Td><Td className="text-right tabular-nums">{bytes(b.size_bytes)}</Td></tr>))}</tbody>
              </Table>
            )}
          </CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle>Failed jobs</CardTitle></CardHeader>
          <CardContent>
            {(failed.data ?? []).length === 0 ? <EmptyState title="No failed jobs" /> : (
              <ul className="space-y-2 text-sm">{failed.data!.map((j) => (<li key={j.id}><p className="font-medium">{j.job ?? j.queue}</p><p className="truncate text-xs text-muted">{j.error}</p></li>))}</ul>
            )}
          </CardContent>
        </Card>
      </div>

      <Card className="mt-4">
        <CardHeader><CardTitle>Platform audit trail</CardTitle></CardHeader>
        <CardContent>
          {audit.isLoading ? <Skeleton className="h-32 w-full" /> : (
            <Table>
              <thead><tr><Th>When</Th><Th>Action</Th><Th>Change</Th></tr></thead>
              <tbody>{(audit.data ?? []).map((a) => (<tr key={a.id}><Td className="whitespace-nowrap text-muted">{formatDateTime(a.created_at)}</Td><Td className="font-mono text-xs">{a.action}</Td><Td className="max-w-md truncate font-mono text-xs text-muted" title={JSON.stringify(a.new_values)}>{a.new_values ? JSON.stringify(a.new_values) : "—"}</Td></tr>))}</tbody>
            </Table>
          )}
        </CardContent>
      </Card>
    </>
  );
}
