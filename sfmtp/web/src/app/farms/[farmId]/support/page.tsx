"use client";

import { useInfiniteQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status";
import { api, idempotencyKey } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { formatRelative } from "@/lib/format";

export default function SupportPage() {
  const { farmId } = useParams<{ farmId: string }>();
  const router = useRouter();
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [key] = useState(idempotencyKey);

  const query = useInfiniteQuery({
    queryKey: ["my-tickets"],
    initialPageParam: undefined as string | undefined,
    queryFn: async ({ pageParam }) => (await api.GET("/support/tickets", { params: { query: { cursor: pageParam } } })).data!,
    getNextPageParam: (last) => last.meta?.next_cursor ?? undefined,
  });
  const rows = query.data?.pages.flatMap((p) => p.data ?? []) ?? [];

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    setError(null);
    try {
      const { data } = await api.POST("/support/tickets", {
        params: { header: { "Idempotency-Key": key } },
        body: { subject: String(f.get("subject")), body: String(f.get("body")), farm_id: farmId, priority: f.get("priority") as "normal" },
      });
      await queryClient.invalidateQueries({ queryKey: ["my-tickets"] });
      router.push(`/farms/${farmId}/support/${data!.data!.id}`);
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  return (
    <>
      <PageHeader title="Help & support" description="Ask the SFMTP team. Farm owners see every ticket from their farms." actions={<Button onClick={() => setOpen(true)}><Plus /> New ticket</Button>} />
      {query.isLoading ? <Skeleton className="h-48 w-full" /> : query.error ? <ErrorNotice error={query.error} /> : rows.length === 0 ? (
        <EmptyState title="No tickets yet">Something not working? Open a ticket and we&apos;ll reply here.</EmptyState>
      ) : (
        <Table>
          <thead><tr><Th>Subject</Th><Th>Farm</Th><Th>Status</Th><Th>Activity</Th></tr></thead>
          <tbody>
            {rows.map((t) => (
              <tr key={t.id}>
                <Td><Link href={`/farms/${farmId}/support/${t.id}`} className="font-medium text-primary hover:underline">{t.subject}</Link><p className="font-mono text-xs text-muted">{t.reference} · {t.opened_by?.name}</p></Td>
                <Td>{t.farm?.name ?? "—"}</Td>
                <Td><StatusBadge status={t.status} /></Td>
                <Td className="text-muted">{t.last_activity_at ? formatRelative(t.last_activity_at) : ""}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      <Dialog open={open} onClose={() => setOpen(false)} title="New support ticket" description="About this farm.">
        <form onSubmit={onSubmit} className="space-y-3">
          <div><Label htmlFor="subject">Subject</Label><Input id="subject" name="subject" required maxLength={200} /></div>
          <div><Label htmlFor="body">What happened?</Label><Textarea id="body" name="body" required maxLength={10000} /></div>
          <div>
            <Label htmlFor="priority">Priority</Label>
            <Select id="priority" name="priority" defaultValue="normal">{["low", "normal", "high", "urgent"].map((p) => <option key={p} value={p}>{p}</option>)}</Select>
          </div>
          <FieldError>{error ? (Object.values(error.problem.errors ?? {})[0]?.[0] ?? error.problem.title) : null}</FieldError>
          <div className="flex justify-end gap-2"><Button type="button" variant="ghost" onClick={() => setOpen(false)}>Cancel</Button><Button type="submit">Send</Button></div>
        </form>
      </Dialog>
    </>
  );
}
