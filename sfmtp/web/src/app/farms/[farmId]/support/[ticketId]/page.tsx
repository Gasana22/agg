"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ShieldCheck } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { ReplyForm } from "@/components/support/reply-form";
import { Thread } from "@/components/support/thread";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Select } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { formatDateTime } from "@/lib/format";

export default function TicketPage() {
  const { farmId, ticketId } = useParams<{ farmId: string; ticketId: string }>();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const [error, setError] = useState<ApiError | null>(null);
  const [hours, setHours] = useState("24");
  const path = { params: { path: { ticket: ticketId } } };

  const ticket = useQuery({ queryKey: ["my-ticket", ticketId], queryFn: async () => (await api.GET("/support/tickets/{ticket}", path)).data!.data! });
  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ["my-ticket", ticketId] });
    queryClient.invalidateQueries({ queryKey: ["workspaces"] });
  };
  const act = async (fn: () => Promise<unknown>) => {
    setError(null);
    try {
      await fn();
      refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  };

  if (ticket.isLoading) return <Skeleton className="h-64 w-full" />;
  if (ticket.error) return <ErrorNotice error={ticket.error} />;
  const t = ticket.data!;
  const active = (t.access_grants ?? []).find((g) => g.active);
  const canGrant = workspace?.is_owner && t.farm && !["resolved", "closed"].includes(t.status ?? "");

  return (
    <>
      <Link href={`/farms/${farmId}/support`} className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden /> All tickets
      </Link>
      <PageHeader title={t.subject ?? "Ticket"} description={`${t.reference} · ${t.farm?.name ?? ""}`} actions={<StatusBadge status={t.status} />} />
      {error ? <div className="mb-4"><ErrorNotice error={error} /></div> : null}

      <div className="grid gap-4 lg:grid-cols-[1fr_18rem]">
        <div className="space-y-4">
          <Thread ticket={t} />
          {t.status !== "closed" ? <Card><CardContent><ReplyForm onSend={async (body) => { await api.POST("/support/tickets/{ticket}/messages", { ...path, body: { body } }); refresh(); }} /></CardContent></Card> : null}
        </div>

        {workspace?.is_owner && t.farm ? (
          <Card>
            <CardHeader><CardTitle className="inline-flex items-center gap-2"><ShieldCheck className="size-4 text-primary" aria-hidden /> Support access</CardTitle></CardHeader>
            <CardContent className="space-y-3 text-sm">
              <p className="text-muted">Let SFMTP support look at {t.farm.name} to investigate. They can view, never change anything, and every page they open appears in your audit log.</p>
              {active ? (
                <>
                  <p>Access active until <strong>{formatDateTime(active.expires_at)}</strong>.</p>
                  <Button variant="danger" size="sm" onClick={() => act(() => api.DELETE("/support/tickets/{ticket}/access-grants/{grant}", { params: { path: { ticket: ticketId, grant: active.id! } } }))}>Revoke now</Button>
                </>
              ) : canGrant ? (
                <div className="flex gap-2">
                  <Select aria-label="Duration" value={hours} onChange={(e) => setHours(e.target.value)} className="h-9">
                    {["4", "24", "48", "72"].map((h) => <option key={h} value={h}>{h} hours</option>)}
                  </Select>
                  <Button size="sm" onClick={() => act(() => api.POST("/support/tickets/{ticket}/access-grants", { ...path, body: { hours: Number(hours) } }))}>Grant</Button>
                </div>
              ) : (
                <p className="text-muted">Access can be granted while the ticket is open.</p>
              )}
            </CardContent>
          </Card>
        ) : null}
      </div>
    </>
  );
}
