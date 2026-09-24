"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import Link from "next/link";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { AssignWorkDialog, ReviewDialog } from "@/components/workforce/dialogs";
import { useWorkers } from "@/components/workforce/queries";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { humanize } from "@/lib/format";
import { can } from "@/lib/permissions";
import { cn } from "@/lib/utils";
import { formatMinutes, type Task, TASK_STATUS } from "@/lib/workforce";

const TABS = [
  { key: "review", label: "To verify", status: "submitted" },
  { key: "open", label: "Open", status: "assigned,in_progress,paused,rejected" },
  { key: "done", label: "Done", status: "verified" },
  { key: "all", label: "All", status: undefined },
] as const;

export default function TasksPage() {
  return (
    <Suspense>
      <Tasks />
    </Suspense>
  );
}

function Tasks() {
  const { farmId } = useParams<{ farmId: string }>();
  const search = useSearchParams();
  const router = useRouter();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const writable = workspace?.type === "farm";
  const canManage = writable && can(perms, "tasks.manage");
  const canVerify = writable && can(perms, "tasks.verify");
  const seesAll = perms?.["tasks.view"] === "all";

  const tab = TABS.find((t) => t.key === search.get("tab")) ?? TABS[canVerify ? 0 : 1];
  const [worker, setWorker] = useState("");
  const [assigning, setAssigning] = useState(search.get("new") === "1");
  const [review, setReview] = useState<{ task: Task; action: "verify" | "reject" } | null>(null);
  const workers = useWorkers(farmId, "active", seesAll && can(perms, "workers.view"));

  const tasks = useQuery({
    queryKey: ["tasks", farmId, tab.key, worker],
    queryFn: async () =>
      (
        await api.GET("/farms/{farm}/tasks", {
          params: { path: { farm: farmId }, query: { "filter[status]": tab.status, "filter[worker_id]": worker || undefined, per_page: 100 } },
        })
      ).data!.data!,
  });

  const done = async () => {
    setAssigning(false);
    setReview(null);
    await queryClient.invalidateQueries({ queryKey: ["tasks", farmId] });
    await queryClient.invalidateQueries({ queryKey: ["dashboard"] });
  };

  return (
    <>
      <PageHeader
        title="Tasks"
        description="Work assigned to workers: done on their phones, verified here."
        actions={
          canManage ? (
            <Button onClick={() => setAssigning(true)}>
              <Plus /> Assign work
            </Button>
          ) : null
        }
      />

      <div role="tablist" aria-label="Task lists" className="mb-4 flex gap-1 overflow-x-auto border-b border-border">
        {TABS.filter((t) => t.key !== "review" || canVerify).map((t) => (
          <button
            key={t.key}
            role="tab"
            type="button"
            aria-selected={tab.key === t.key}
            onClick={() => router.replace(`/farms/${farmId}/tasks?tab=${t.key}`)}
            className={cn("-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm", tab.key === t.key ? "border-primary font-medium text-foreground" : "border-transparent text-muted hover:text-foreground")}
          >
            {t.label}
          </button>
        ))}
      </div>

      {workers.data && workers.data.length > 0 ? (
        <div className="mb-4 w-56">
          <Select aria-label="Worker" value={worker} onChange={(e) => setWorker(e.target.value)}>
            <option value="">All workers</option>
            {workers.data.map((w) => (
              <option key={w.id} value={w.id}>
                {w.full_name}
              </option>
            ))}
          </Select>
        </div>
      ) : null}

      {tasks.error ? <ErrorNotice error={tasks.error} /> : null}
      {tasks.isLoading ? (
        <Skeleton className="h-40" />
      ) : tasks.data?.length === 0 ? (
        <EmptyState title={tab.key === "review" ? "Nothing waiting for verification" : "No tasks here"} />
      ) : (
        <div className="overflow-x-auto">
          <Table>
            <thead>
              <tr>
                <Th>Task</Th>
                <Th>Work</Th>
                <Th>Worker</Th>
                <Th>Due</Th>
                <Th>Status</Th>
                <Th>Done</Th>
                {tab.key === "review" && canVerify ? <Th className="sr-only">Review</Th> : null}
              </tr>
            </thead>
            <tbody>
              {tasks.data?.map((t) => {
                const s = TASK_STATUS[t.status ?? "assigned"];
                return (
                  <tr key={t.id}>
                    <Td>
                      <Link href={`/farms/${farmId}/tasks/${t.id}`} className="font-medium text-primary hover:underline">
                        {t.code}
                      </Link>
                    </Td>
                    <Td>
                      <p className="font-medium">{t.activity?.title}</p>
                      <p className="text-xs text-muted">
                        {humanize(t.activity?.module ?? "general")}
                        {t.activity?.subject?.label ? ` · ${t.activity.subject.label}` : ""}
                      </p>
                    </Td>
                    <Td>{t.worker?.full_name}</Td>
                    <Td>
                      {t.due_on ?? "—"} {t.overdue ? <Badge tone="danger">Overdue</Badge> : null}
                    </Td>
                    <Td>
                      <Badge tone={s.tone}>{s.label}</Badge>
                    </Td>
                    <Td className="whitespace-nowrap text-sm">
                      {t.quantity !== null && t.quantity !== undefined ? `${t.quantity} ${t.unit ?? ""}` : ""}
                      {t.worked_minutes ? <span className="block text-xs text-muted">{formatMinutes(t.worked_minutes)}</span> : null}
                    </Td>
                    {tab.key === "review" && canVerify ? (
                      <Td className="whitespace-nowrap text-right">
                        <Button size="sm" variant="secondary" onClick={() => setReview({ task: t, action: "verify" })}>
                          Verify
                        </Button>{" "}
                        <Button size="sm" variant="ghost" onClick={() => setReview({ task: t, action: "reject" })}>
                          Send back
                        </Button>
                      </Td>
                    ) : null}
                  </tr>
                );
              })}
            </tbody>
          </Table>
        </div>
      )}

      {assigning ? <AssignWorkDialog farmId={farmId} perms={perms} onClose={() => setAssigning(false)} onDone={done} /> : null}
      {review ? <ReviewDialog farmId={farmId} task={review.task} action={review.action} onClose={() => setReview(null)} onDone={done} /> : null}
    </>
  );
}
