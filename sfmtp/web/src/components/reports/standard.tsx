"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Download, FileDown, Play } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input, Label } from "@/components/ui/input";
import {
  EmptyState,
  ErrorNotice,
  Skeleton,
  Table,
  Td,
  Th,
} from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import {
  EXPORT_FORMATS,
  EXPORT_STATUS,
  formatBytes,
  formatReportCell,
  groupReports,
  hasPendingExports,
  isNumericColumn,
  type ReportExport,
  type StandardReportDefinition,
} from "@/lib/analytics";
import { formatDateTime } from "@/lib/format";
import { cn } from "@/lib/utils";

type Params = { from?: string; to?: string; days?: number };

function defaults(): Params {
  const today = new Date();
  const from = new Date(today);
  from.setDate(from.getDate() - 29);
  const iso = (d: Date) => d.toISOString().slice(0, 10);
  return { from: iso(from), to: iso(today), days: 60 };
}

/** The standard report catalogue: pick a report, set its parameters, preview it, export it. */
export function StandardReports({
  farmId,
  canExport,
}: {
  farmId: string;
  canExport: boolean;
}) {
  const catalogue = useQuery({
    queryKey: ["standard-reports", farmId],
    queryFn: async () =>
      (
        await api.GET("/farms/{farm}/standard-reports", {
          params: { path: { farm: farmId } },
        })
      ).data!.data ?? [],
  });
  const [selected, setSelected] = useState<string | null>(null);

  if (catalogue.isLoading) return <Skeleton className="h-64 w-full" />;
  if (catalogue.error) return <ErrorNotice error={catalogue.error} />;
  const reports = catalogue.data ?? [];
  if (reports.length === 0)
    return <EmptyState title="No reports for your role." />;
  const current = reports.find((r) => r.key === selected) ?? null;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
      <nav aria-label="Reports" className="space-y-4">
        {groupReports(reports).map((g) => (
          <div key={g.key}>
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">
              {g.label}
            </p>
            <ul className="space-y-0.5">
              {g.reports.map((r) => (
                <li key={r.key}>
                  <button
                    type="button"
                    onClick={() => setSelected(r.key!)}
                    aria-current={selected === r.key ? "true" : undefined}
                    className={cn(
                      "w-full rounded-md px-2 py-1.5 text-left text-sm hover:bg-surface-muted",
                      selected === r.key && "bg-surface-muted font-medium",
                    )}
                  >
                    {r.title}
                  </button>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </nav>
      {current ? (
        <ReportRunner
          key={current.key}
          farmId={farmId}
          report={current}
          canExport={canExport}
        />
      ) : (
        <EmptyState title="Choose a report on the left." />
      )}
    </div>
  );
}

function ReportRunner({
  farmId,
  report,
  canExport,
}: {
  farmId: string;
  report: StandardReportDefinition;
  canExport: boolean;
}) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [draft, setDraft] = useState<Params>(defaults);
  const [params, setParams] = useState<Params>(defaults);
  const [exportError, setExportError] = useState<unknown>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const uses = (p: string) => (report.params ?? []).includes(p as never);
  const query = Object.fromEntries(
    Object.entries(params).filter(
      ([k, v]) => uses(k) && v !== undefined && v !== "",
    ),
  ) as Params;

  const run = useQuery({
    queryKey: ["standard-report", farmId, report.key, query],
    queryFn: async () =>
      (
        await api.GET("/farms/{farm}/standard-reports/{report}", {
          params: { path: { farm: farmId, report: report.key! }, query },
        })
      ).data!.data!,
  });

  async function exportAs(format: "csv" | "xlsx" | "pdf") {
    setExportError(null);
    setBusy(format);
    try {
      await api.POST("/farms/{farm}/exports", {
        params: { path: { farm: farmId } },
        body: { kind: "report", report: report.key!, format, params: query },
      });
      await queryClient.invalidateQueries({ queryKey: ["exports", farmId] });
      router.replace(`/farms/${farmId}/reports?tab=exports`);
    } catch (e) {
      setExportError(e);
    } finally {
      setBusy(null);
    }
  }

  const d = run.data;
  return (
    <Card className="min-w-0">
      <CardHeader className="flex-wrap gap-2">
        <div>
          <CardTitle>{report.title}</CardTitle>
          <p className="mt-1 text-sm text-muted">{report.description}</p>
        </div>
        {canExport ? (
          <div className="flex flex-wrap gap-1">
            {EXPORT_FORMATS.map((f) => (
              <Button
                key={f.key}
                size="sm"
                variant="secondary"
                disabled={busy !== null}
                onClick={() => exportAs(f.key)}
              >
                <FileDown /> {busy === f.key ? "Queuing…" : f.label}
              </Button>
            ))}
          </div>
        ) : null}
      </CardHeader>
      <CardContent className="space-y-4">
        {(report.params ?? []).length > 0 ? (
          <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(e) => {
              e.preventDefault();
              setParams(draft);
            }}
          >
            {uses("from") ? (
              <div>
                <Label htmlFor="report-from">From</Label>
                <Input
                  id="report-from"
                  type="date"
                  className="h-9 w-40"
                  value={draft.from ?? ""}
                  onChange={(e) => setDraft({ ...draft, from: e.target.value })}
                />
              </div>
            ) : null}
            {uses("to") ? (
              <div>
                <Label htmlFor="report-to">To</Label>
                <Input
                  id="report-to"
                  type="date"
                  className="h-9 w-40"
                  value={draft.to ?? ""}
                  onChange={(e) => setDraft({ ...draft, to: e.target.value })}
                />
              </div>
            ) : null}
            {uses("days") ? (
              <div>
                <Label htmlFor="report-days">Within days</Label>
                <Input
                  id="report-days"
                  type="number"
                  min={1}
                  max={365}
                  className="h-9 w-28"
                  value={draft.days ?? 60}
                  onChange={(e) =>
                    setDraft({ ...draft, days: Number(e.target.value) })
                  }
                />
              </div>
            ) : null}
            <Button type="submit" size="sm">
              <Play /> Run
            </Button>
          </form>
        ) : null}
        {exportError ? <ErrorNotice error={exportError} /> : null}
        {run.isLoading ? (
          <Skeleton className="h-48 w-full" />
        ) : run.error ? (
          <ErrorNotice error={run.error} />
        ) : d && (d.rows ?? []).length === 0 ? (
          <EmptyState title="No rows for these parameters." />
        ) : d ? (
          <>
            <Table>
              <thead>
                <tr>
                  {(d.columns ?? []).map((c) => (
                    <Th
                      key={c.key}
                      className={cn(isNumericColumn(c.type) && "text-right")}
                    >
                      {c.label}
                    </Th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {(d.rows ?? []).map((row, i) => (
                  <tr key={i}>
                    {(d.columns ?? []).map((c) => (
                      <Td
                        key={c.key}
                        className={cn(
                          isNumericColumn(c.type) && "text-right tabular-nums",
                          "whitespace-nowrap",
                        )}
                      >
                        {formatReportCell(
                          (row as Record<string, unknown>)[c.key!],
                          c.type,
                          d.currency,
                        )}
                      </Td>
                    ))}
                  </tr>
                ))}
              </tbody>
              {d.totals ? (
                <tfoot>
                  <tr className="border-t-2 border-border font-semibold">
                    {(d.columns ?? []).map((c, i) => {
                      const v = (d.totals as Record<string, unknown>)[c.key!];
                      return (
                        <Td
                          key={c.key}
                          className={cn(
                            isNumericColumn(c.type) &&
                              "text-right tabular-nums",
                            "whitespace-nowrap",
                          )}
                        >
                          {v === null || v === undefined
                            ? i === 0
                              ? "Total"
                              : ""
                            : formatReportCell(v, c.type, d.currency)}
                        </Td>
                      );
                    })}
                  </tr>
                </tfoot>
              ) : null}
            </Table>
            <p className="text-xs text-muted">
              {d.row_count} rows
              {d.truncated
                ? " (the first 500 are shown; exports carry them all)"
                : ""}{" "}
              · generated {formatDateTime(d.generated_at)}
            </p>
          </>
        ) : null}
      </CardContent>
    </Card>
  );
}

/** The member's own exports; polls while any is still being built. */
export function ExportsList({ farmId }: { farmId: string }) {
  const exports = useQuery({
    queryKey: ["exports", farmId],
    queryFn: async () =>
      (
        await api.GET("/farms/{farm}/exports", {
          params: { path: { farm: farmId } },
        })
      ).data!.data ?? [],
    refetchInterval: (q) =>
      hasPendingExports((q.state.data as ReportExport[] | undefined) ?? [])
        ? 2000
        : false,
  });
  if (exports.isLoading) return <Skeleton className="h-48 w-full" />;
  if (exports.error) return <ErrorNotice error={exports.error} />;
  const rows = exports.data ?? [];
  if (rows.length === 0)
    return (
      <EmptyState title="No exports yet">
        Run a report and export it as CSV, Excel or PDF, or print QR labels from
        the{" "}
        <Link
          href={`/farms/${farmId}/traceability?tab=qr`}
          className="text-primary hover:underline"
        >
          QR codes
        </Link>{" "}
        list. Files are kept for 24 hours.
      </EmptyState>
    );

  return (
    <Card>
      <CardHeader>
        <CardTitle>Your exports</CardTitle>
        <span className="text-xs text-muted">
          Only you see these. Files are kept for 24 hours.
        </span>
      </CardHeader>
      <CardContent>
        <Table>
          <thead>
            <tr>
              <Th>Export</Th>
              <Th>Status</Th>
              <Th>Asked</Th>
              <Th className="text-right">Size</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {rows.map((e) => {
              const status = EXPORT_STATUS[e.status ?? "queued"];
              return (
                <tr key={e.id}>
                  <Td>
                    <p className="font-medium">{e.title}</p>
                    <p className="text-xs text-muted">
                      {(e.format ?? "").toUpperCase()}
                      {e.params &&
                      typeof e.params === "object" &&
                      "from" in e.params
                        ? ` · ${String(e.params.from)} to ${String(e.params.to)}`
                        : ""}
                      {e.row_count != null
                        ? ` · ${e.row_count} ${e.kind === "labels" ? "labels" : "rows"}`
                        : ""}
                    </p>
                    {e.error ? (
                      <p className="text-xs text-danger">{e.error}</p>
                    ) : null}
                  </Td>
                  <Td>
                    <Badge tone={status.tone}>{status.label}</Badge>
                    {e.status === "ready" && e.expires_at ? (
                      <p className="mt-1 text-xs text-muted">
                        until {formatDateTime(e.expires_at)}
                      </p>
                    ) : null}
                  </Td>
                  <Td className="text-muted">{formatDateTime(e.created_at)}</Td>
                  <Td className="text-right tabular-nums text-muted">
                    {formatBytes(e.file_size)}
                  </Td>
                  <Td className="text-right">
                    {e.status === "ready" && e.download_path ? (
                      <a
                        href={`/api/proxy${e.download_path}`}
                        className={buttonVariants({
                          variant: "ghost",
                          size: "sm",
                        })}
                        download={e.file_name ?? undefined}
                      >
                        <Download /> Download
                      </a>
                    ) : null}
                  </Td>
                </tr>
              );
            })}
          </tbody>
        </Table>
      </CardContent>
    </Card>
  );
}
