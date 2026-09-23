"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";

import { ReplyForm } from "@/components/support/reply-form";
import { Thread } from "@/components/support/thread";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Label, Select } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status";
import { api, type components } from "@/lib/api/client";
import { useMe, usePlatformWorkspace } from "@/lib/api/hooks";
import { formatDateTime } from "@/lib/format";
import { can } from "@/lib/permissions";

type Status = components["schemas"]["TicketStatus"];
type Priority = components["schemas"]["TicketPriority"];

export default function AdminTicketPage() {
  const { ticketId } = useParams<{ ticketId: string }>();
  const queryClient = useQueryClient();
  const { workspace } = usePlatformWorkspace();
  const me = useMe();
  const manage = can(workspace?.permissions, "support.manage");
  const path = { params: { path: { ticket: ticketId } } };

  const ticket = useQuery({
    queryKey: ["admin-ticket", ticketId],
    queryFn: async () => (await api.GET("/admin/support/tickets/{ticket}", path)).data!.data!,
  });
  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ["admin-ticket", ticketId] });
    queryClient.invalidateQueries({ queryKey: ["admin-tickets"] });
  };
  const update = async (body: { status?: Status; priority?: Priority; assigned_to?: string | null }) => {
    await api.PATCH("/admin/support/tickets/{ticket}", { ...path, body });
    refresh();
  };

  if (ticket.isLoading) return <Skeleton className="h-64 w-full" />;
  if (ticket.error) return <ErrorNotice error={ticket.error} />;
  const t = ticket.data!;
  const activeGrant = (t.access_grants ?? []).find((g) => g.active);

  return (
    <>
      <Link href="/admin/support" className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden /> Support queue
      </Link>
      <PageHeader title={t.subject ?? "Ticket"} description={`${t.reference} · ${t.organization?.name}${t.farm ? ` · ${t.farm.name}` : ""} · opened by ${t.opened_by?.name}`} actions={<StatusBadge status={t.status} />} />

      <div className="grid gap-4 lg:grid-cols-[1fr_18rem]">
        <div className="space-y-4">
          <Thread ticket={t} />
          {manage && t.status !== "closed" ? (
            <Card><CardContent><ReplyForm allowInternal onSend={async (body, internal) => { await api.POST("/admin/support/tickets/{ticket}/messages", { ...path, body: { body, is_internal: internal } }); refresh(); }} /></CardContent></Card>
          ) : null}
        </div>

        <div className="space-y-4">
          <Card>
            <CardHeader><CardTitle>Ticket</CardTitle></CardHeader>
            <CardContent className="space-y-3">
              <div>
                <Label htmlFor="status">Status</Label>
                <Select id="status" value={t.status} disabled={!manage} onChange={(e) => update({ status: e.target.value as Status })}>
                  {["open", "pending", "resolved", "closed"].map((s) => <option key={s} value={s}>{s}</option>)}
                </Select>
              </div>
              <div>
                <Label htmlFor="priority">Priority</Label>
                <Select id="priority" value={t.priority} disabled={!manage} onChange={(e) => update({ priority: e.target.value as Priority })}>
                  {["low", "normal", "high", "urgent"].map((s) => <option key={s} value={s}>{s}</option>)}
                </Select>
              </div>
              <div className="text-sm">
                <p className="mb-1 font-medium">Assignee</p>
                <p className="text-muted">{t.assigned_to?.name ?? "Unassigned"}</p>
                {manage && me.data?.data?.id && t.assigned_to?.id !== me.data.data.id ? (
                  <Button size="sm" variant="secondary" className="mt-2" onClick={() => update({ assigned_to: me.data!.data!.id })}>Assign to me</Button>
                ) : null}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle>Farm access</CardTitle></CardHeader>
            <CardContent className="space-y-2 text-sm">
              {activeGrant ? (
                <>
                  <p>The owner granted read-only access until <strong>{formatDateTime(activeGrant.expires_at)}</strong>.</p>
                  {t.farm && can(workspace?.permissions, "support.access") ? (
                    <Link href={`/farms/${t.farm.id}`} className="text-primary hover:underline">Open farm (read-only)</Link>
                  ) : null}
                </>
              ) : (
                <p className="text-muted">No access. Ask the owner to grant read-only access from this ticket if you need to see the farm.</p>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </>
  );
}
