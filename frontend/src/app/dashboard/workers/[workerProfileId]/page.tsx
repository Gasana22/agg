"use client";

import * as React from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { Camera, Check, X, Pencil } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
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
import { useAuth } from "@/lib/auth-context";
import {
  getWorkerProfile,
  updateWorkerProfile,
  listAttendances,
  checkIn,
  checkOut,
  approveAttendance,
} from "@/lib/modules/worker-management";
import { listMembers } from "@/lib/modules/farm-structure";
import { usePermissions } from "@/lib/permissions";

const editSchema = z.object({
  employee_id: z.string().optional(),
  hire_date: z.string().optional(),
  daily_rate: z.string().optional(),
});
type EditFormValues = z.infer<typeof editSchema>;

function getGeolocation(): Promise<GeolocationPosition> {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) {
      reject(new Error("Geolocation is not available in this browser."));
      return;
    }
    navigator.geolocation.getCurrentPosition(resolve, reject, { timeout: 10000 });
  });
}

export default function WorkerDetailPage() {
  const params = useParams<{ workerProfileId: string }>();
  const workerProfileId = Number(params.workerProfileId);
  const { user } = useAuth();
  const { canManageFarm } = usePermissions();
  const queryClient = useQueryClient();
  const fileInputRef = React.useRef<HTMLInputElement>(null);
  const [pendingAction, setPendingAction] = React.useState<"check-in" | "check-out" | null>(null);
  const [actionError, setActionError] = React.useState<string | null>(null);
  const [editOpen, setEditOpen] = React.useState(false);
  const [editError, setEditError] = React.useState<string | null>(null);
  const [editSupervisorId, setEditSupervisorId] = React.useState("");

  const { data: profile, isLoading: profileLoading } = useQuery({
    queryKey: ["worker-profile", workerProfileId],
    queryFn: () => getWorkerProfile(workerProfileId),
  });

  const { data: members } = useQuery({
    queryKey: ["farm-members", profile?.farm_id],
    queryFn: () => listMembers(profile!.farm_id),
    enabled: !!profile,
  });

  const editForm = useForm<EditFormValues>({ resolver: zodResolver(editSchema) });

  const { data: attendances, isLoading: attendancesLoading } = useQuery({
    queryKey: ["attendances", workerProfileId],
    queryFn: () => listAttendances(workerProfileId),
  });

  const isOwnProfile = profile?.user.id === user?.id;
  const isSupervisor = !!profile?.supervisor && profile.supervisor.id === user?.id;
  const canApproveAttendance = canManageFarm || isSupervisor;
  const today = new Date().toISOString().slice(0, 10);
  const todayAttendance = attendances?.find((a) => a.date.slice(0, 10) === today);

  const checkInMutation = useMutation({
    mutationFn: (form: FormData) => checkIn(workerProfileId, form),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["attendances", workerProfileId] }),
    onError: (err) =>
      setActionError(
        isAxiosError(err) ? err.response?.data?.message ?? "Check-in failed." : "Something went wrong."
      ),
  });

  const checkOutMutation = useMutation({
    mutationFn: (form: FormData) => checkOut(workerProfileId, form),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["attendances", workerProfileId] }),
    onError: (err) =>
      setActionError(
        isAxiosError(err) ? err.response?.data?.message ?? "Check-out failed." : "Something went wrong."
      ),
  });

  const approveMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: "approved" | "rejected" }) =>
      approveAttendance(id, status),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["attendances", workerProfileId] }),
  });

  const editMutation = useMutation({
    mutationFn: (values: EditFormValues) =>
      updateWorkerProfile(workerProfileId, {
        employee_id: values.employee_id || undefined,
        hire_date: values.hire_date || undefined,
        daily_rate: values.daily_rate ? Number(values.daily_rate) : undefined,
        supervisor_id: editSupervisorId ? Number(editSupervisorId) : null,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["worker-profile", workerProfileId] });
      queryClient.invalidateQueries({ queryKey: ["worker-profiles"] });
      setEditOpen(false);
    },
    onError: (err) =>
      setEditError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not update profile." : "Something went wrong."
      ),
  });

  const triggerAction = (action: "check-in" | "check-out") => {
    setActionError(null);
    setPendingAction(action);
    fileInputRef.current?.click();
  };

  const onPhotoSelected = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file || !pendingAction) return;

    try {
      const position = await getGeolocation();
      const form = new FormData();
      form.append("gps_lat", String(position.coords.latitude));
      form.append("gps_lng", String(position.coords.longitude));
      form.append("photo", file);

      if (pendingAction === "check-in") {
        await checkInMutation.mutateAsync(form);
      } else {
        await checkOutMutation.mutateAsync(form);
      }
    } catch (err) {
      setActionError(err instanceof Error ? err.message : "Could not get your location.");
    } finally {
      setPendingAction(null);
    }
  };

  if (profileLoading || !profile) {
    return <p className="text-sm text-muted-foreground">Loading worker…</p>;
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">{profile.user.name}</h1>
        <p className="text-sm text-muted-foreground">{profile.user.email}</p>
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Profile</CardTitle>
          {canManageFarm && (
          <Dialog
            open={editOpen}
            onOpenChange={(open) => {
              setEditOpen(open);
              if (open) {
                editForm.reset({
                  employee_id: profile.employee_id ?? "",
                  hire_date: profile.hire_date ?? "",
                  daily_rate: profile.daily_rate ?? "",
                });
                setEditSupervisorId(profile.supervisor ? String(profile.supervisor.id) : "");
                setEditError(null);
              }
            }}
          >
            <DialogTrigger asChild>
              <Button size="sm" variant="outline" className="gap-2">
                <Pencil className="size-4" />
                Edit profile
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Edit worker profile</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={editForm.handleSubmit((values) => {
                  setEditError(null);
                  editMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="edit-employee-id">Employee ID</Label>
                    <Input id="edit-employee-id" {...editForm.register("employee_id")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="edit-hire-date">Hire date</Label>
                    <Input id="edit-hire-date" type="date" {...editForm.register("hire_date")} />
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="edit-daily-rate">Daily rate</Label>
                  <Input id="edit-daily-rate" type="number" step="any" {...editForm.register("daily_rate")} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Supervisor</Label>
                  <Select
                    value={editSupervisorId || "none"}
                    onValueChange={(v) => setEditSupervisorId(v === "none" ? "" : v)}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="None" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      {(members ?? [])
                        .filter((m) => m.id !== profile.user.id)
                        .map((member) => (
                          <SelectItem key={member.id} value={String(member.id)}>
                            {member.name}
                          </SelectItem>
                        ))}
                    </SelectContent>
                  </Select>
                </div>
                {editError && <p className="text-sm text-destructive">{editError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={editMutation.isPending}>
                    {editMutation.isPending ? "Saving…" : "Save changes"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
          )}
        </CardHeader>
        <CardContent className="grid grid-cols-2 gap-4 pb-6 text-sm sm:grid-cols-4">
          <div>
            <p className="text-muted-foreground">Employee ID</p>
            <p className="font-medium">{profile.employee_id ?? "—"}</p>
          </div>
          <div>
            <p className="text-muted-foreground">Hire date</p>
            <p className="font-medium">
              {profile.hire_date ? new Date(profile.hire_date).toLocaleDateString() : "—"}
            </p>
          </div>
          <div>
            <p className="text-muted-foreground">Daily rate</p>
            <p className="font-medium">{profile.daily_rate ?? "—"}</p>
          </div>
          <div>
            <p className="text-muted-foreground">Supervisor</p>
            <p className="font-medium">{profile.supervisor?.name ?? "—"}</p>
          </div>
        </CardContent>
      </Card>

      {isOwnProfile && (
        <Card>
          <CardHeader>
            <CardTitle>Today&apos;s attendance</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-3 pb-6">
            <input
              ref={fileInputRef}
              type="file"
              accept="image/*"
              capture="environment"
              className="hidden"
              onChange={onPhotoSelected}
            />
            {!todayAttendance ? (
              <Button
                className="w-fit gap-2"
                onClick={() => triggerAction("check-in")}
                disabled={checkInMutation.isPending}
              >
                <Camera className="size-4" />
                {checkInMutation.isPending ? "Checking in…" : "Check in"}
              </Button>
            ) : !todayAttendance.check_out_at ? (
              <Button
                className="w-fit gap-2"
                onClick={() => triggerAction("check-out")}
                disabled={checkOutMutation.isPending}
              >
                <Camera className="size-4" />
                {checkOutMutation.isPending ? "Checking out…" : "Check out"}
              </Button>
            ) : (
              <p className="text-sm text-muted-foreground">
                You&apos;ve completed today&apos;s attendance ({todayAttendance.status}).
              </p>
            )}
            {actionError && <p className="text-sm text-destructive">{actionError}</p>}
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Attendance history</CardTitle>
        </CardHeader>
        <CardContent className="pb-6">
          {attendancesLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !attendances || attendances.length === 0 ? (
            <p className="text-sm text-muted-foreground">No attendance records yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Date</TableHead>
                  <TableHead>Check-in</TableHead>
                  <TableHead>Check-out</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-24" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {attendances.map((att) => (
                  <TableRow key={att.id}>
                    <TableCell>{new Date(att.date).toLocaleDateString()}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {att.check_in_at ? new Date(att.check_in_at).toLocaleTimeString() : "—"}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {att.check_out_at ? new Date(att.check_out_at).toLocaleTimeString() : "—"}
                    </TableCell>
                    <TableCell>
                      <Badge
                        variant={
                          att.status === "approved"
                            ? "success"
                            : att.status === "rejected"
                              ? "destructive"
                              : "warning"
                        }
                      >
                        {att.status}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      {att.status === "pending" && canApproveAttendance && (
                        <div className="flex gap-1">
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Approve attendance"
                            onClick={() => approveMutation.mutate({ id: att.id, status: "approved" })}
                          >
                            <Check className="size-4 text-emerald-600" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Reject attendance"
                            onClick={() => approveMutation.mutate({ id: att.id, status: "rejected" })}
                          >
                            <X className="size-4 text-destructive" />
                          </Button>
                        </div>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
