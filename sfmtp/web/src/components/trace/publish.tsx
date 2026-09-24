"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ExternalLink, FileDown, QrCode } from "lucide-react";
import { useState } from "react";

import { FormDialog } from "@/components/forms/form-dialog";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { FieldError, Input, Label, Textarea } from "@/components/ui/input";
import { EmptyState, ErrorNotice, Skeleton } from "@/components/ui/misc";
import { api, type components } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { formatDateTime } from "@/lib/format";

type QrCodeT = components["schemas"]["TraceQrCode"];

/** Choose what the public sees, approve it, and issue QR codes (docs/07 §5). */
export function PublishPanel({ farmId, batchId, canPublish, recalled }: { farmId: string; batchId: string; canPublish: boolean; recalled: boolean }) {
  const queryClient = useQueryClient();
  const path = { farm: farmId, batch: batchId };
  const approvals = useQuery({
    queryKey: ["trace-approvals", farmId, batchId],
    queryFn: async () => (await api.GET("/farms/{farm}/traceability/batches/{batch}/approvals", { params: { path } })).data!,
  });
  const codes = useQuery({
    queryKey: ["trace-qr", farmId, batchId],
    queryFn: async () => (await api.GET("/farms/{farm}/traceability/batches/{batch}/qr-codes", { params: { path } })).data!.data ?? [],
  });
  const current = approvals.data?.data?.[0];
  const [chosen, setChosen] = useState<string[] | null>(null);
  const fields = chosen ?? current?.public_fields ?? approvals.data?.meta?.default_fields ?? [];
  const preview = useQuery({
    queryKey: ["trace-preview", farmId, batchId, [...fields].sort().join(",")],
    queryFn: async () => (await api.GET("/farms/{farm}/traceability/batches/{batch}/public-preview", { params: { path, query: { "fields[]": fields as never } } })).data!.data,
    enabled: canPublish && !recalled && fields.length > 0,
  });
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [dialog, setDialog] = useState<null | "issue" | { revoke: QrCodeT }>(null);

  const refresh = () => queryClient.invalidateQueries({ predicate: (q) => ["trace-approvals", "trace-qr", "events", "trace-view"].includes(String(q.queryKey[0])) });

  async function approve() {
    setBusy(true);
    setError(null);
    try {
      await api.POST("/farms/{farm}/traceability/batches/{batch}/approvals", { params: { path }, body: { public_fields: fields as never, note: note || null } });
      setChosen(null);
      setNote("");
      await refresh();
    } catch (e) {
      setError(e instanceof ApiError ? e : null);
    } finally {
      setBusy(false);
    }
  }

  if (approvals.isLoading) return <Skeleton className="h-64 w-full" />;
  if (approvals.error) return <ErrorNotice error={approvals.error} />;
  const catalogue = approvals.data?.meta?.fields ?? [];
  const changed = !current || [...fields].sort().join() !== [...(current.public_fields ?? [])].sort().join();

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle>What the public sees</CardTitle>
          {current ? (
            <span className="text-xs text-muted">
              Approved {formatDateTime(current.approved_at)}
              {current.approved_by?.name ? ` by ${current.approved_by.name}` : ""}
            </span>
          ) : (
            <Badge tone="warning">not published</Badge>
          )}
        </CardHeader>
        <CardContent className="space-y-3">
          {recalled ? <p className="text-sm text-danger">This batch is recalled: its public page shows the recall notice.</p> : null}
          <fieldset disabled={!canPublish || recalled} className="space-y-1.5">
            <legend className="mb-1 text-xs text-muted">Prices, costs, people, quantities and exact locations are never public.</legend>
            {catalogue.map((f) => (
              <label key={f.key} className="flex items-start gap-2 text-sm">
                <input
                  type="checkbox"
                  className="mt-0.5 size-4"
                  checked={fields.includes(f.key!)}
                  onChange={(e) => setChosen(e.target.checked ? [...fields, f.key!] : fields.filter((k) => k !== f.key))}
                />
                <span>{f.description}</span>
              </label>
            ))}
          </fieldset>
          {canPublish && !recalled ? (
            <>
              <div>
                <Label htmlFor="approval_note">Note (internal)</Label>
                <Input id="approval_note" value={note} onChange={(e) => setNote(e.target.value)} placeholder="Checked against the field records" />
              </div>
              <Button onClick={approve} disabled={busy || fields.length === 0 || !changed}>
                {current ? "Approve these fields" : "Approve and publish"}
              </Button>
              {error ? <FieldError>{error.problem.title}</FieldError> : null}
            </>
          ) : null}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Preview</CardTitle>
          <span className="text-xs text-muted">{changed && current ? "not yet approved" : "as published"}</span>
        </CardHeader>
        <CardContent>
          {!canPublish ? (
            <PublicFields payload={(current?.payload ?? {}) as Record<string, unknown>} />
          ) : preview.isLoading ? (
            <Skeleton className="h-40 w-full" />
          ) : (
            <PublicFields payload={((changed || !current ? preview.data : current.payload) ?? {}) as Record<string, unknown>} />
          )}
        </CardContent>
      </Card>

      <Card className="lg:col-span-2">
        <CardHeader>
          <CardTitle>QR codes</CardTitle>
          {canPublish && current && !recalled ? (
            <Button size="sm" onClick={() => setDialog("issue")}>
              <QrCode /> Issue a code
            </Button>
          ) : null}
        </CardHeader>
        <CardContent>
          {codes.isLoading ? (
            <Skeleton className="h-24 w-full" />
          ) : (codes.data ?? []).length === 0 ? (
            <EmptyState title={current ? "No QR code yet." : "Approve the public fields, then issue a QR code."} />
          ) : (
            <ul className="grid gap-4 sm:grid-cols-2">
              {(codes.data ?? []).map((c) => (
                <li key={c.id} className="flex gap-4 rounded-lg border border-border p-3">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img src={`/api/proxy/farms/${farmId}/traceability/qr-codes/${c.id}/image.svg`} alt={`QR code ${c.code}`} className={`size-28 shrink-0 rounded bg-white ${c.status === "revoked" ? "opacity-30" : ""}`} />
                  <div className="min-w-0 space-y-1 text-sm">
                    <p className="flex items-center gap-2 font-mono font-medium">
                      {c.code} <Badge tone={c.status === "active" ? "success" : "danger"}>{c.status}</Badge>
                    </p>
                    {c.label ? <p className="text-xs text-muted">{c.label}</p> : null}
                    <p className="text-xs text-muted">
                      {c.scan_count} scans{c.last_scanned_at ? `, last ${formatDateTime(c.last_scanned_at)}` : ""}
                    </p>
                    {c.revoke_reason ? <p className="text-xs text-danger">{c.revoke_reason}</p> : null}
                    <div className="flex flex-wrap gap-1 pt-1">
                      <a href={`/q/${c.code}`} target="_blank" rel="noreferrer" className={buttonVariants({ variant: "ghost", size: "sm" })}>
                        <ExternalLink /> Public page
                      </a>
                      {c.status === "active" ? (
                        <a href={`/api/proxy/farms/${farmId}/traceability/qr-codes/${c.id}/labels.pdf?copies=24`} target="_blank" rel="noreferrer" className={buttonVariants({ variant: "ghost", size: "sm" })}>
                          <FileDown /> Labels (PDF)
                        </a>
                      ) : null}
                      {canPublish && c.status === "active" ? (
                        <Button variant="ghost" size="sm" className="text-danger" onClick={() => setDialog({ revoke: c })}>
                          Revoke
                        </Button>
                      ) : null}
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      {dialog === "issue" ? (
        <FormDialog
          title="Issue a QR code"
          description="A new random code for this batch. It shows the approved fields; approving new fields later updates every code."
          submitLabel="Issue"
          onClose={() => setDialog(null)}
          onSubmit={async (f) => {
            await api.POST("/farms/{farm}/traceability/batches/{batch}/qr-codes", { params: { path }, body: { label: String(f.get("label") ?? "") || null } });
            setDialog(null);
            await refresh();
          }}
        >
          {() => (
            <div>
              <Label htmlFor="label">Label (internal)</Label>
              <Input id="label" name="label" placeholder="Bag labels, run 2" />
            </div>
          )}
        </FormDialog>
      ) : null}
      {dialog && typeof dialog === "object" ? (
        <FormDialog
          title={`Revoke ${dialog.revoke.code}`}
          description="Scans then show that the code is withdrawn. This cannot be undone; issue a new code if needed."
          submitLabel="Revoke"
          onClose={() => setDialog(null)}
          onSubmit={async (f) => {
            await api.POST("/farms/{farm}/traceability/qr-codes/{qrCode}/revoke", { params: { path: { farm: farmId, qrCode: dialog.revoke.id! } }, body: { reason: String(f.get("reason")) } });
            setDialog(null);
            await refresh();
          }}
        >
          {(err) => (
            <div>
              <Label htmlFor="reason">Reason (internal)</Label>
              <Textarea id="reason" name="reason" required minLength={3} rows={2} placeholder="Labels printed with the wrong date" />
              <FieldError>{err?.fieldError("reason")}</FieldError>
            </div>
          )}
        </FormDialog>
      ) : null}
    </div>
  );
}

/** The approved payload, as a compact list (the public page shows it nicely). */
function PublicFields({ payload }: { payload: Record<string, unknown> }) {
  const entries = Object.entries(payload);
  if (entries.length === 0) return <EmptyState title="Nothing chosen." />;
  const show = (v: unknown): string =>
    Array.isArray(v) ? v.map((x) => (typeof x === "object" && x ? Object.values(x).filter(Boolean).join(" · ") : String(x))).join("; ") : typeof v === "object" && v ? Object.entries(v).map(([k, x]) => `${k}: ${x}`).join(", ") : String(v);
  return (
    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
      {entries.map(([k, v]) => (
        <div key={k} className="contents">
          <dt className="text-muted">{k.replaceAll("_", " ")}</dt>
          <dd className="break-words">{show(v) || "—"}</dd>
        </div>
      ))}
    </dl>
  );
}
