"use client";

import { useInfiniteQuery, useQuery, useQueryClient } from "@tanstack/react-query";
import { CheckCircle2, Plus, Search, ShieldAlert, ShieldCheck, Truck } from "lucide-react";
import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { useDeferredValue, useState } from "react";

import { KIND_LABELS, STATUS_TONE } from "@/components/trace/labels";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input, Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api, type components } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { formatDateTime } from "@/lib/format";
import { can } from "@/lib/permissions";
import { ALERT_TONE, formatQty } from "@/lib/trace";
import { cn } from "@/lib/utils";

type Kind = components["schemas"]["BatchKind"];
type Tab = "batches" | "alerts" | "qr" | "integrity";

export default function TraceabilityPage() {
  const { farmId } = useParams<{ farmId: string }>();
  const search = useSearchParams();
  const { workspace } = useFarmWorkspace(farmId);
  const [tab, setTab] = useState<Tab>((search.get("tab") as Tab) || "batches");
  const alerts = useQuery({
    queryKey: ["trace-alerts", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/traceability/alerts", { params: { path: { farm: farmId } } })).data!,
  });
  const counts = alerts.data?.meta?.counts;
  const flagged = (counts?.critical ?? 0) + (counts?.warning ?? 0);

  return (
    <>
      <PageHeader
        title="Traceability"
        description="Every batch has a permanent, auditable journey from seed to customer."
        actions={
          <div className="flex gap-2">
            {can(workspace?.permissions, "sales.view|sales.fulfil|sales.invoice") ? (
              <Link href={`/farms/${farmId}/shipments`} className={buttonVariants({ variant: "secondary" })}>
                <Truck /> Shipments
              </Link>
            ) : null}
            {can(workspace?.permissions, "trace.batches.create") ? (
              <Link href={`/farms/${farmId}/traceability/batches/new`} className={buttonVariants()}>
                <Plus /> New batch
              </Link>
            ) : null}
          </div>
        }
      />
      <div role="tablist" aria-label="Traceability sections" className="mb-4 flex gap-1 border-b border-border">
        {(
          [
            ["batches", "Batches"],
            ["alerts", flagged ? `Alerts (${flagged})` : "Alerts"],
            ["qr", "QR codes"],
            ["integrity", "Integrity"],
          ] as [Tab, string][]
        ).map(([key, label]) => (
          <button
            key={key}
            role="tab"
            type="button"
            aria-selected={tab === key}
            onClick={() => setTab(key)}
            className={cn("-mb-px border-b-2 px-3 py-2 text-sm", tab === key ? "border-primary font-medium text-foreground" : "border-transparent text-muted hover:text-foreground", key === "alerts" && (counts?.critical ?? 0) > 0 && "text-danger")}
          >
            {label}
          </button>
        ))}
      </div>
      {tab === "batches" ? <Batches farmId={farmId} /> : tab === "alerts" ? <Alerts farmId={farmId} query={alerts} /> : tab === "qr" ? <QrCodes farmId={farmId} /> : <Integrity farmId={farmId} canVerify={can(workspace?.permissions, "trace.publish|audit.view")} />}
    </>
  );
}

function Batches({ farmId }: { farmId: string }) {
  const [kind, setKind] = useState<Kind | "">("");
  const [q, setQ] = useState("");
  const search = useDeferredValue(q.trim());

  const query = useInfiniteQuery({
    queryKey: ["batches", farmId, kind, search],
    initialPageParam: undefined as string | undefined,
    queryFn: async ({ pageParam }) =>
      (
        await api.GET("/farms/{farm}/traceability/batches", {
          params: {
            path: { farm: farmId },
            query: { cursor: pageParam, per_page: 25, "filter[kind]": kind || undefined, q: search || undefined },
          },
        })
      ).data!,
    getNextPageParam: (last) => last.meta?.next_cursor ?? undefined,
  });

  const batches = query.data?.pages.flatMap((p) => p.data ?? []) ?? [];

  return (
    <>
      <div className="mb-4 flex flex-wrap gap-2">
        <div className="relative w-full max-w-xs">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden />
          <Input aria-label="Search batches" placeholder="Search code or name" className="pl-9" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        <Select aria-label="Kind" className="w-44" value={kind} onChange={(e) => setKind(e.target.value as Kind | "")}>
          <option value="">All kinds</option>
          {Object.entries(KIND_LABELS).map(([k, label]) => (
            <option key={k} value={k}>
              {label}
            </option>
          ))}
        </Select>
      </div>

      {query.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : query.error ? (
        <ErrorNotice error={query.error} />
      ) : batches.length === 0 ? (
        <EmptyState title="No batches yet">Batches appear as seed lots, harvests and products are recorded.</EmptyState>
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>Batch</Th>
                <Th>Kind</Th>
                <Th className="text-right">Quantity</Th>
                <Th className="text-right">Left</Th>
                <Th>Status</Th>
                <Th>Created</Th>
              </tr>
            </thead>
            <tbody>
              {batches.map((b) => (
                <tr key={b.id} className="hover:bg-surface-muted/50">
                  <Td>
                    <Link href={`/farms/${farmId}/traceability/batches/${b.id}`} className="font-mono text-sm font-medium text-primary hover:underline">
                      {b.batch_code}
                    </Link>
                    {b.name ? <p className="text-xs text-muted">{b.name}</p> : null}
                  </Td>
                  <Td>{KIND_LABELS[b.kind ?? ""] ?? b.kind}</Td>
                  <Td className="text-right tabular-nums">{formatQty(b.quantity)}</Td>
                  <Td className="text-right tabular-nums text-muted">{formatQty(b.available)}</Td>
                  <Td>
                    <Badge tone={STATUS_TONE[b.status ?? "open"]}>{b.status}</Badge>
                  </Td>
                  <Td className="text-muted">{formatDateTime(b.created_at)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
          {query.hasNextPage ? (
            <div className="mt-4 text-center">
              <Button variant="secondary" onClick={() => query.fetchNextPage()} disabled={query.isFetchingNextPage}>
                {query.isFetchingNextPage ? "Loading…" : "Load more"}
              </Button>
            </div>
          ) : null}
        </>
      )}
    </>
  );
}

type AlertsQuery = { isLoading: boolean; error: unknown; data?: { data?: components["schemas"]["TraceAlert"][] } };

function Alerts({ farmId, query }: { farmId: string; query: AlertsQuery }) {
  if (query.isLoading) return <Skeleton className="h-48 w-full" />;
  if (query.error) return <ErrorNotice error={query.error} />;
  const alerts = query.data?.data ?? [];
  if (alerts.length === 0)
    return (
      <EmptyState title="Nothing needs attention">
        <span className="inline-flex items-center gap-1">
          <CheckCircle2 className="size-4 text-primary" aria-hidden /> The history verifies, every product has a source and every shipment is accounted for.
        </span>
      </EmptyState>
    );
  return (
    <div className="space-y-4">
      {alerts.map((a) => (
        <Card key={a.code} className={cn(a.severity === "critical" && "border-danger/50")}>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Badge tone={ALERT_TONE[a.severity ?? "info"]}>{a.severity}</Badge> {a.title}
            </CardTitle>
            <span className="text-sm text-muted">{a.count}</span>
          </CardHeader>
          <CardContent>
            <ul className="divide-y divide-border">
              {(a.items ?? []).map((item, i) => {
                const it = item as { batch?: { id: string; batch_code: string; name?: string | null }; customer?: string | null; reason?: string | null; first_bad_seq?: number | null; checked_at?: string | null };
                return (
                  <li key={it.batch?.id ?? i} className="flex flex-wrap items-baseline gap-x-3 py-2 text-sm">
                    {it.batch ? (
                      <Link href={`/farms/${farmId}/traceability/batches/${it.batch.id}`} className="font-mono text-primary hover:underline">
                        {it.batch.batch_code}
                      </Link>
                    ) : null}
                    {it.batch?.name ? <span className="text-muted">{it.batch.name}</span> : null}
                    {it.customer && !it.batch?.name?.includes(it.customer) ? <span>→ {it.customer}</span> : null}
                    {it.reason ? <span className="text-danger">{it.reason.replaceAll("_", " ")} at event #{it.first_bad_seq}</span> : null}
                    {it.checked_at !== undefined ? <span className="text-muted">last checked {it.checked_at ? formatDateTime(it.checked_at) : "never"}</span> : null}
                  </li>
                );
              })}
            </ul>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}

function Integrity({ farmId, canVerify }: { farmId: string; canVerify: boolean }) {
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState(false);
  const q = useQuery({
    queryKey: ["trace-integrity", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/traceability/integrity", { params: { path: { farm: farmId } } })).data!.data!,
  });
  async function verify() {
    setBusy(true);
    try {
      await api.POST("/farms/{farm}/traceability/integrity/verify", { params: { path: { farm: farmId } } });
      await queryClient.invalidateQueries({ queryKey: ["trace-integrity", farmId] });
      await queryClient.invalidateQueries({ queryKey: ["trace-alerts", farmId] });
    } finally {
      setBusy(false);
    }
  }
  if (q.isLoading) return <Skeleton className="h-48 w-full" />;
  if (q.error) return <ErrorNotice error={q.error} />;
  const d = q.data!;
  const ok = d.latest?.result === "pass";
  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            {ok ? <ShieldCheck className="size-5 text-primary" aria-hidden /> : <ShieldAlert className="size-5 text-danger" aria-hidden />}
            {d.latest ? (ok ? "The history is intact" : "The history has been altered") : "Not checked yet"}
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-2 text-sm">
          <p>
            {d.events?.toLocaleString()} events, each sealed with the hash of the one before. Changing any stored event breaks the chain from that point on.
          </p>
          {d.latest && !ok ? (
            <p className="text-danger" role="alert">
              First bad event: #{d.latest.first_bad_seq} ({d.latest.reason?.replaceAll("_", " ")}). Contact SFMTP support.
            </p>
          ) : null}
          <p className="break-all font-mono text-xs text-muted">Head {d.head_hash ?? "—"}</p>
          <p className="text-xs text-muted">Checked every night; the owner is emailed if it fails.</p>
          {canVerify ? (
            <Button variant="secondary" size="sm" onClick={verify} disabled={busy}>
              {busy ? "Checking…" : "Check now"}
            </Button>
          ) : null}
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>Recent checks</CardTitle>
        </CardHeader>
        <CardContent>
          {(d.history ?? []).length === 0 ? (
            <EmptyState title="No checks yet." />
          ) : (
            <ul className="divide-y divide-border text-sm">
              {(d.history ?? []).map((h) => (
                <li key={h.id} className="flex items-center justify-between py-2">
                  <span>{formatDateTime(h.checked_at)}</span>
                  <span className="text-xs text-muted">{h.events} events</span>
                  <Badge tone={h.result === "pass" ? "success" : "danger"}>{h.result}</Badge>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function QrCodes({ farmId }: { farmId: string }) {
  const stats = useQuery({
    queryKey: ["qr-stats", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/traceability/qr-stats", { params: { path: { farm: farmId }, query: { days: 30 } } })).data!.data!,
  });
  const codes = useQuery({
    queryKey: ["qr-codes", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/traceability/qr-codes", { params: { path: { farm: farmId }, query: { per_page: 100 } } })).data!.data ?? [],
  });
  if (stats.isLoading || codes.isLoading) return <Skeleton className="h-48 w-full" />;
  if (stats.error || codes.error) return <ErrorNotice error={stats.error ?? codes.error} />;
  const days = stats.data?.days ?? [];
  const peak = Math.max(1, ...days.map((d) => d.scans ?? 0));
  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>Scans in the last 30 days</CardTitle>
          <span className="text-sm font-semibold tabular-nums">{stats.data?.total ?? 0}</span>
        </CardHeader>
        <CardContent>
          <div className="flex h-28 items-end gap-1" role="img" aria-label={`QR scans per day, ${stats.data?.total ?? 0} in total`}>
            {days.map((d) => (
              <div key={d.date} title={`${d.date}: ${d.scans}`} className="flex-1 rounded-t bg-primary/70" style={{ height: `${Math.max(2, ((d.scans ?? 0) / peak) * 100)}%` }} />
            ))}
          </div>
          <p className="mt-3 text-xs text-muted">
            By country: {(stats.data?.countries ?? []).map((c) => `${c.country === "ZZ" ? "unknown" : c.country} ${c.scans}`).join(" · ") || "no scans yet"}. Only the day and
            country of a scan are kept.
          </p>
        </CardContent>
      </Card>
      {(codes.data ?? []).length === 0 ? (
        <EmptyState title="No QR codes yet">Open a batch, approve its public fields on the “Public page &amp; QR” tab, then issue a code.</EmptyState>
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Code</Th>
              <Th>Batch</Th>
              <Th>Status</Th>
              <Th className="text-right">Scans</Th>
              <Th>Issued</Th>
            </tr>
          </thead>
          <tbody>
            {(codes.data ?? []).map((c) => (
              <tr key={c.id}>
                <Td>
                  <a href={`/q/${c.code}`} target="_blank" rel="noreferrer" className="font-mono text-primary hover:underline">
                    {c.code}
                  </a>
                  {c.label ? <p className="text-xs text-muted">{c.label}</p> : null}
                </Td>
                <Td>
                  <Link href={`/farms/${farmId}/traceability/batches/${c.batch?.id}?tab=publish`} className="font-mono text-sm hover:underline">
                    {c.batch?.batch_code}
                  </Link>
                  <p className="text-xs text-muted">{c.batch?.name}</p>
                </Td>
                <Td>
                  <Badge tone={c.status === "active" ? "success" : "danger"}>{c.status}</Badge>
                </Td>
                <Td className="text-right tabular-nums">{c.scan_count}</Td>
                <Td className="text-muted">{formatDateTime(c.issued_at)}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </div>
  );
}
