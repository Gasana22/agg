"use client";

import * as React from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { Plus, Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { listMembers } from "@/lib/modules/farm-structure";
import {
  listDailyTasks,
  createDailyTask,
  updateDailyTaskStatus,
  deleteDailyTask,
  type DailyTask,
} from "@/lib/modules/worker-management";
import { useFarm } from "@/lib/farm-context";
import { useAuth } from "@/lib/auth-context";
import { usePermissions } from "@/lib/permissions";

const schema = z.object({
  assigned_to: z.string().min(1, "Pick an assignee"),
  title: z.string().min(1, "Title is required"),
  description: z.string().optional(),
  due_date: z.string().optional(),
});
type FormValues = z.infer<typeof schema>;

const STATUS_FLOW: Record<DailyTask["status"], DailyTask["status"] | null> = {
  pending: "ongoing",
  ongoing: "completed",
  completed: null,
};

const STATUS_LABEL: Record<DailyTask["status"], string> = {
  pending: "Start",
  ongoing: "Complete",
  completed: "",
};

export default function ActivitiesPage() {
  const { currentFarmId } = useFarm();
  const { user } = useAuth();
  const { canManageFarm } = usePermissions();
  const queryClient = useQueryClient();
  const [open, setOpen] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const { data: tasks, isLoading } = useQuery({
    queryKey: ["daily-tasks", currentFarmId],
    queryFn: () => listDailyTasks(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const { data: members } = useQuery({
    queryKey: ["farm-members", currentFarmId],
    queryFn: () => listMembers(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const form = useForm<FormValues>({ resolver: zodResolver(schema) });

  const createMutation = useMutation({
    mutationFn: (values: FormValues) =>
      createDailyTask(currentFarmId!, {
        assigned_to: Number(values.assigned_to),
        title: values.title,
        description: values.description || undefined,
        due_date: values.due_date || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["daily-tasks", currentFarmId] });
      setOpen(false);
      form.reset();
    },
    onError: (err) =>
      setError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not create task." : "Something went wrong."
      ),
  });

  const advanceMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: DailyTask["status"] }) =>
      updateDailyTaskStatus(id, status),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["daily-tasks", currentFarmId] }),
  });

  const deleteMutation = useMutation({
    mutationFn: deleteDailyTask,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["daily-tasks", currentFarmId] }),
  });

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Activity Tracking</h1>
          <p className="text-sm text-muted-foreground">Daily tasks assigned to workers on this farm.</p>
        </div>
        {canManageFarm && (
        <Dialog open={open} onOpenChange={setOpen}>
          <DialogTrigger asChild>
            <Button className="gap-2">
              <Plus className="size-4" />
              New task
            </Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Assign a task</DialogTitle>
            </DialogHeader>
            <form
              onSubmit={form.handleSubmit((values) => {
                setError(null);
                createMutation.mutate(values);
              })}
              className="flex flex-col gap-4"
            >
              <div className="flex flex-col gap-1.5">
                <Label>Assignee</Label>
                <Select onValueChange={(v) => form.setValue("assigned_to", v)}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select a farm member" />
                  </SelectTrigger>
                  <SelectContent>
                    {(members ?? []).map((member) => (
                      <SelectItem key={member.id} value={String(member.id)}>
                        {member.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {form.formState.errors.assigned_to && (
                  <p className="text-xs text-destructive">{form.formState.errors.assigned_to.message}</p>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="title">Title</Label>
                <Input id="title" {...form.register("title")} />
                {form.formState.errors.title && (
                  <p className="text-xs text-destructive">{form.formState.errors.title.message}</p>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="description">Description</Label>
                <Textarea id="description" rows={3} {...form.register("description")} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="due_date">Due date</Label>
                <Input id="due_date" type="date" {...form.register("due_date")} />
              </div>
              {error && <p className="text-sm text-destructive">{error}</p>}
              <DialogFooter>
                <Button type="submit" disabled={createMutation.isPending}>
                  {createMutation.isPending ? "Assigning…" : "Assign task"}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
        )}
      </div>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading tasks…</p>
      ) : !tasks || tasks.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            No tasks yet.
          </CardContent>
        </Card>
      ) : (
        <Card>
          <CardContent className="pb-6 pt-6">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Title</TableHead>
                  <TableHead>Assignee</TableHead>
                  <TableHead>Due</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-32" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {tasks.map((task) => {
                  const nextStatus = STATUS_FLOW[task.status];
                  const canUpdateStatus =
                    canManageFarm || task.assignee.id === user?.id || task.assigner.id === user?.id;
                  const canDelete = canManageFarm || task.assigner.id === user?.id;
                  return (
                    <TableRow key={task.id}>
                      <TableCell className="font-medium">{task.title}</TableCell>
                      <TableCell className="text-muted-foreground">{task.assignee.name}</TableCell>
                      <TableCell className="text-muted-foreground">
                        {task.due_date ? new Date(task.due_date).toLocaleDateString() : "—"}
                      </TableCell>
                      <TableCell>
                        <Badge
                          variant={
                            task.status === "completed"
                              ? "success"
                              : task.status === "ongoing"
                                ? "warning"
                                : "secondary"
                          }
                        >
                          {task.status}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-1">
                          {nextStatus && canUpdateStatus && (
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() => advanceMutation.mutate({ id: task.id, status: nextStatus })}
                              disabled={advanceMutation.isPending}
                            >
                              {STATUS_LABEL[task.status]}
                            </Button>
                          )}
                          {canDelete && (
                            <Button
                              variant="ghost"
                              size="icon"
                              onClick={() => deleteMutation.mutate(task.id)}
                            >
                              <Trash2 className="size-4 text-destructive" />
                            </Button>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
