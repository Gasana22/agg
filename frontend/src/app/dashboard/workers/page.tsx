"use client";

import * as React from "react";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { Plus } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
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
import { listWorkerProfiles, createWorkerProfile } from "@/lib/modules/worker-management";
import { useFarm } from "@/lib/farm-context";

const schema = z.object({
  user_id: z.string().min(1, "Pick a farm member"),
  employee_id: z.string().optional(),
  hire_date: z.string().optional(),
  daily_rate: z.string().optional(),
  supervisor_id: z.string().optional(),
});
type FormValues = z.infer<typeof schema>;

export default function WorkersPage() {
  const { currentFarmId } = useFarm();
  const queryClient = useQueryClient();
  const [open, setOpen] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const { data: profiles, isLoading, isError } = useQuery({
    queryKey: ["worker-profiles", currentFarmId],
    queryFn: () => listWorkerProfiles(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const { data: members } = useQuery({
    queryKey: ["farm-members", currentFarmId],
    queryFn: () => listMembers(currentFarmId!),
    enabled: !!currentFarmId && open,
  });

  const form = useForm<FormValues>({ resolver: zodResolver(schema) });

  const createMutation = useMutation({
    mutationFn: (values: FormValues) =>
      createWorkerProfile(currentFarmId!, {
        user_id: Number(values.user_id),
        employee_id: values.employee_id || undefined,
        hire_date: values.hire_date || undefined,
        daily_rate: values.daily_rate ? Number(values.daily_rate) : undefined,
        supervisor_id: values.supervisor_id ? Number(values.supervisor_id) : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["worker-profiles", currentFarmId] });
      setOpen(false);
      form.reset();
    },
    onError: (err) =>
      setError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not create worker profile." : "Something went wrong."
      ),
  });

  const existingProfileUserIds = new Set((profiles ?? []).map((p) => p.user.id));
  const eligibleMembers = (members ?? []).filter((m) => !existingProfileUserIds.has(m.id));

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Workers</h1>
          <p className="text-sm text-muted-foreground">
            Worker profiles, pay rates, and supervisors for this farm.
          </p>
        </div>
        <Dialog open={open} onOpenChange={setOpen}>
          <DialogTrigger asChild>
            <Button className="gap-2">
              <Plus className="size-4" />
              Add worker
            </Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Add a worker profile</DialogTitle>
            </DialogHeader>
            <form
              onSubmit={form.handleSubmit((values) => {
                setError(null);
                createMutation.mutate(values);
              })}
              className="flex flex-col gap-4"
            >
              <div className="flex flex-col gap-1.5">
                <Label>Farm member</Label>
                <Select onValueChange={(v) => form.setValue("user_id", v)}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select a member" />
                  </SelectTrigger>
                  <SelectContent>
                    {eligibleMembers.map((member) => (
                      <SelectItem key={member.id} value={String(member.id)}>
                        {member.name} ({member.email})
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {form.formState.errors.user_id && (
                  <p className="text-xs text-destructive">{form.formState.errors.user_id.message}</p>
                )}
                <p className="text-xs text-muted-foreground">
                  Only farm members without a worker profile yet are listed. Add them as a member first
                  from Farm Structure.
                </p>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="employee_id">Employee ID</Label>
                  <Input id="employee_id" {...form.register("employee_id")} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="hire_date">Hire date</Label>
                  <Input id="hire_date" type="date" {...form.register("hire_date")} />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="daily_rate">Daily rate</Label>
                  <Input id="daily_rate" type="number" step="any" {...form.register("daily_rate")} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Supervisor</Label>
                  <Select onValueChange={(v) => form.setValue("supervisor_id", v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="None" />
                    </SelectTrigger>
                    <SelectContent>
                      {(members ?? []).map((member) => (
                        <SelectItem key={member.id} value={String(member.id)}>
                          {member.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
              {error && <p className="text-sm text-destructive">{error}</p>}
              <DialogFooter>
                <Button type="submit" disabled={createMutation.isPending}>
                  {createMutation.isPending ? "Adding…" : "Add worker"}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>

      {isError ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            You don&apos;t have access to worker management on this farm. Only farm owners, managers, and
            the farm&apos;s accountant can view worker profiles and pay rates.
          </CardContent>
        </Card>
      ) : isLoading ? (
        <p className="text-sm text-muted-foreground">Loading workers…</p>
      ) : !profiles || profiles.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            No worker profiles yet.
          </CardContent>
        </Card>
      ) : (
        <Card>
          <CardContent className="pb-6 pt-6">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Employee ID</TableHead>
                  <TableHead>Hire date</TableHead>
                  <TableHead>Daily rate</TableHead>
                  <TableHead>Supervisor</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {profiles.map((profile) => (
                  <TableRow key={profile.id} className="cursor-pointer">
                    <TableCell className="font-medium">
                      <Link href={`/dashboard/workers/${profile.id}`} className="hover:underline">
                        {profile.user.name}
                      </Link>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{profile.employee_id ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {profile.hire_date ? new Date(profile.hire_date).toLocaleDateString() : "—"}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{profile.daily_rate ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {profile.supervisor?.name ?? "—"}
                    </TableCell>
                    <TableCell>
                      <Badge variant={profile.is_active ? "success" : "secondary"}>
                        {profile.is_active ? "Active" : "Inactive"}
                      </Badge>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
