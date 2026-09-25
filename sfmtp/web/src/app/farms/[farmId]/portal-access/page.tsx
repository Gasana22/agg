"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Send } from "lucide-react";
import { useParams } from "next/navigation";
import { useState } from "react";

import { useCustomers } from "@/components/finance/queries";
import { FormDialog, text } from "@/components/forms/form-dialog";
import { useActions } from "@/components/inventory/common";
import { useSuppliers } from "@/components/inventory/queries";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { formatDateTime } from "@/lib/format";
import { can } from "@/lib/permissions";

type Row = {
  type?: "portal_link" | "portal_invitation";
  id?: string;
  kind?: "supplier" | "customer";
  record_id?: string;
  record_name?: string | null;
  party?: { id?: string; name?: string };
  people?: number;
  email?: string;
  invited_by?: string | null;
  status?: string;
  linked_at?: string;
  revoked_at?: string | null;
  expires_at?: string;
};

const TONE: Record<string, "success" | "warning" | "neutral" | "danger"> = { active: "success", pending: "warning", revoked: "neutral", expired: "neutral" };

/** Who can use the supplier and customer portals for this farm (ADR-0016). */
export default function PortalAccessPage() {
  const { farmId } = useParams<{ farmId: string }>();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const writable = workspace?.type === "farm";
  const manage = { supplier: writable && can(perms, "suppliers.manage"), customer: writable && can(perms, "customers.manage") };
  const [inviting, setInviting] = useState(false);
  const queryClient = useQueryClient();
  const rows = useQuery({
    queryKey: ["portal-access", farmId],
    queryFn: async () => ((await api.GET("/farms/{farm}/portal-access", { params: { path: { farm: farmId } } })).data!.data ?? []) as Row[],
  });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["portal-access", farmId] });
  const { error, busy, run } = useActions(refresh);

  return (
    <>
      <PageHeader
        title="Portal access"
        description="Suppliers answer purchase orders and send invoices in their portal; customers order, follow deliveries and see what they bought. They see only their own records, never farm data."
        actions={
          manage.supplier || manage.customer ? (
            <Button onClick={() => setInviting(true)}>
              <Send /> Invite
            </Button>
          ) : null
        }
      />
      {error ? (
        <div className="mb-4">
          <ErrorNotice error={error} />
        </div>
      ) : null}
      {rows.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : rows.error ? (
        <ErrorNotice error={rows.error} />
      ) : (rows.data ?? []).length === 0 ? (
        <EmptyState title="Nobody uses the portals yet">Invite a supplier or customer by email; they sign in once to see every farm they deal with.</EmptyState>
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Supplier / customer</Th>
              <Th>Portal account</Th>
              <Th>Status</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {rows.data!.map((r) => (
              <tr key={`${r.type}-${r.id}`} className="align-top">
                <Td>
                  <p className="font-medium">{r.record_name ?? "—"}</p>
                  <p className="text-xs text-muted">{r.kind}</p>
                </Td>
                <Td>
                  {r.type === "portal_link" ? (
                    <>
                      {r.party?.name}
                      <p className="text-xs text-muted">
                        {r.people} {r.people === 1 ? "person" : "people"} · since {formatDateTime(r.linked_at)}
                      </p>
                    </>
                  ) : (
                    <>
                      {r.email}
                      <p className="text-xs text-muted">
                        invited by {r.invited_by ?? "—"} · until {formatDateTime(r.expires_at)}
                      </p>
                    </>
                  )}
                </Td>
                <Td>
                  <Badge tone={TONE[r.status ?? ""] ?? "neutral"}>{r.type === "portal_link" ? (r.status === "active" ? "has access" : "stopped") : r.status === "pending" ? "invited" : r.status}</Badge>
                </Td>
                <Td className="text-right">
                  {r.kind && manage[r.kind] && r.type === "portal_link" && r.status === "active" ? (
                    <Button size="sm" variant="ghost" disabled={busy === r.id} onClick={() => run(r.id!, () => api.DELETE("/farms/{farm}/portal-links/{partyLink}", { params: { path: { farm: farmId, partyLink: r.id! } } }))}>
                      Stop access
                    </Button>
                  ) : null}
                  {r.kind && manage[r.kind] && r.type === "portal_invitation" && r.status === "pending" ? (
                    <Button size="sm" variant="ghost" disabled={busy === r.id} onClick={() => run(r.id!, () => api.DELETE("/farms/{farm}/portal-invitations/{portalInvitation}", { params: { path: { farm: farmId, portalInvitation: r.id! } } }))}>
                      Withdraw
                    </Button>
                  ) : null}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {inviting ? <InviteDialog farmId={farmId} kinds={(["supplier", "customer"] as const).filter((k) => manage[k])} onClose={() => setInviting(false)} onDone={async () => { setInviting(false); await refresh(); }} /> : null}
    </>
  );
}

function InviteDialog({ farmId, kinds, onClose, onDone }: { farmId: string; kinds: ("supplier" | "customer")[]; onClose: () => void; onDone: () => Promise<void> }) {
  const [kind, setKind] = useState(kinds[0]);
  const suppliers = useSuppliers(farmId, kind === "supplier");
  const customers = useCustomers(farmId, kind === "customer");
  const records = (kind === "supplier" ? suppliers.data : customers.data) ?? [];
  const [record, setRecord] = useState("");
  const email = (records as { id?: string; email?: string | null }[]).find((r) => r.id === record)?.email ?? "";
  return (
    <FormDialog
      title="Invite to a portal"
      description="They get an email link, valid for 14 days. Signing in with it opens this record's portal; a person who already has an account keeps it."
      submitLabel="Send invitation"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/portal-invitations", {
          params: { path: { farm: farmId } },
          body: { kind, record_id: record, email: text(f, "email") ?? "", message: text(f, "message") },
        });
        await onDone();
      }}
    >
      {(error) => (
        <>
          {kinds.length > 1 ? (
            <div>
              <Label htmlFor="kind">Portal</Label>
              <Select id="kind" value={kind} onChange={(e) => { setKind(e.target.value as "supplier" | "customer"); setRecord(""); }}>
                <option value="supplier">Supplier portal</option>
                <option value="customer">Customer portal</option>
              </Select>
            </div>
          ) : null}
          <div>
            <Label htmlFor="record">{kind === "supplier" ? "Supplier" : "Customer"}</Label>
            <Select id="record" value={record} onChange={(e) => setRecord(e.target.value)} required>
              <option value="">Choose…</option>
              {(records as { id?: string; name?: string; is_active?: boolean }[]).filter((r) => r.is_active !== false).map((r) => (
                <option key={r.id} value={r.id}>
                  {r.name}
                </option>
              ))}
            </Select>
            <FieldError>{error?.fieldError("record_id")}</FieldError>
          </div>
          <div>
            <Label htmlFor="email">Their email</Label>
            <Input id="email" name="email" type="email" required key={email} defaultValue={email} />
            <FieldError>{error?.fieldError("email")}</FieldError>
          </div>
          <div>
            <Label htmlFor="message">Message (optional)</Label>
            <Textarea id="message" name="message" rows={2} />
          </div>
        </>
      )}
    </FormDialog>
  );
}
