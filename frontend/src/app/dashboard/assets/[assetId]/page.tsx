"use client";

import * as React from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { Plus, Pencil } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
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
import {
  getAsset,
  updateAssetStatus,
  updateAsset,
  listMaintenanceLogs,
  createMaintenanceLog,
  ASSET_STATUSES,
  MAINTENANCE_LOG_TYPES,
} from "@/lib/modules/assets";
import { listMembers } from "@/lib/modules/farm-structure";
import { formatRole } from "@/lib/utils";

const logSchema = z.object({
  type: z.string().min(1, "Pick a type"),
  date: z.string().min(1, "Date is required"),
  description: z.string().min(1, "Description is required"),
  cost: z.string().optional(),
  performed_by: z.string().optional(),
  next_service_date: z.string().optional(),
  notes: z.string().optional(),
});
type LogFormValues = z.infer<typeof logSchema>;

const assetEditSchema = z.object({
  name: z.string().min(1, "Name is required"),
  category: z.string().optional(),
  serial_number: z.string().optional(),
  purchase_date: z.string().optional(),
  purchase_cost: z.string().optional(),
  notes: z.string().optional(),
});
type AssetEditFormValues = z.infer<typeof assetEditSchema>;

const STATUS_VARIANT: Record<string, "default" | "secondary" | "warning" | "success" | "destructive"> = {
  active: "success",
  under_maintenance: "warning",
  retired: "secondary",
};

export default function AssetDetailPage() {
  const params = useParams<{ assetId: string }>();
  const assetId = Number(params.assetId);
  const queryClient = useQueryClient();

  const [logOpen, setLogOpen] = React.useState(false);
  const [logError, setLogError] = React.useState<string | null>(null);
  const [assetEditOpen, setAssetEditOpen] = React.useState(false);
  const [assetEditError, setAssetEditError] = React.useState<string | null>(null);
  const [editAssignedTo, setEditAssignedTo] = React.useState<string>("");

  const { data: asset } = useQuery({
    queryKey: ["asset", assetId],
    queryFn: () => getAsset(assetId),
  });

  const { data: members } = useQuery({
    queryKey: ["farm-members", asset?.farm_id],
    queryFn: () => listMembers(asset!.farm_id),
    enabled: !!asset?.farm_id,
  });

  const { data: logs, isLoading: logsLoading } = useQuery({
    queryKey: ["asset-maintenance-logs", assetId],
    queryFn: () => listMaintenanceLogs(assetId),
  });

  const logForm = useForm<LogFormValues>({ resolver: zodResolver(logSchema) });
  const assetEditForm = useForm<AssetEditFormValues>({ resolver: zodResolver(assetEditSchema) });

  const statusMutation = useMutation({
    mutationFn: (status: string) => updateAssetStatus(assetId, status as never),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["asset", assetId] });
      queryClient.invalidateQueries({ queryKey: ["assets"] });
    },
  });

  const editAssetMutation = useMutation({
    mutationFn: (values: AssetEditFormValues) =>
      updateAsset(assetId, {
        name: values.name,
        category: values.category || undefined,
        serial_number: values.serial_number || undefined,
        purchase_date: values.purchase_date || undefined,
        purchase_cost: values.purchase_cost ? Number(values.purchase_cost) : undefined,
        assigned_to: editAssignedTo ? Number(editAssignedTo) : null,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["asset", assetId] });
      queryClient.invalidateQueries({ queryKey: ["assets"] });
      setAssetEditOpen(false);
    },
    onError: (err) =>
      setAssetEditError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not update asset." : "Something went wrong."
      ),
  });

  const createLogMutation = useMutation({
    mutationFn: (values: LogFormValues) =>
      createMaintenanceLog(assetId, {
        type: values.type,
        date: values.date,
        description: values.description,
        cost: values.cost ? Number(values.cost) : undefined,
        performed_by: values.performed_by || undefined,
        next_service_date: values.next_service_date || undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["asset-maintenance-logs", assetId] });
      setLogOpen(false);
      logForm.reset();
    },
    onError: (err) =>
      setLogError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add log." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{asset?.name ?? "Asset"}</h1>
          <p className="text-sm text-muted-foreground">
            {asset?.category ?? "No category"}
            {asset?.serial_number ? ` · ${asset.serial_number}` : ""}
            {asset?.assignee ? ` · Assigned to ${asset.assignee.name}` : ""}
          </p>
        </div>
        {asset && (
          <div className="flex items-center gap-2">
            <Badge variant={STATUS_VARIANT[asset.status] ?? "secondary"}>{formatRole(asset.status)}</Badge>
            <Select value={asset.status} onValueChange={(v) => statusMutation.mutate(v)}>
              <SelectTrigger className="h-8 w-44">
                <SelectValue placeholder="Change status" />
              </SelectTrigger>
              <SelectContent>
                {ASSET_STATUSES.map((status) => (
                  <SelectItem key={status} value={status}>
                    {formatRole(status)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button
              variant="outline"
              size="sm"
              className="gap-2"
              onClick={() => {
                setAssetEditError(null);
                setEditAssignedTo(asset.assignee ? String(asset.assignee.id) : "");
                assetEditForm.reset({
                  name: asset.name,
                  category: asset.category ?? "",
                  serial_number: asset.serial_number ?? "",
                  purchase_date: asset.purchase_date?.slice(0, 10) ?? "",
                  purchase_cost: asset.purchase_cost ?? "",
                  notes: asset.notes ?? "",
                });
                setAssetEditOpen(true);
              }}
            >
              <Pencil className="size-4" />
              Edit
            </Button>
          </div>
        )}
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Maintenance logs</CardTitle>
          <Dialog open={logOpen} onOpenChange={setLogOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add log
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Log maintenance</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={logForm.handleSubmit((values) => {
                  setLogError(null);
                  createLogMutation.mutate(values);
                })}
                className="flex max-h-[70vh] flex-col gap-4 overflow-y-auto"
              >
                <div className="flex flex-col gap-1.5">
                  <Label>Type</Label>
                  <Select onValueChange={(v) => logForm.setValue("type", v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select a type" />
                    </SelectTrigger>
                    <SelectContent>
                      {MAINTENANCE_LOG_TYPES.map((type) => (
                        <SelectItem key={type} value={type}>
                          {formatRole(type)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {logForm.formState.errors.type && (
                    <p className="text-xs text-destructive">{logForm.formState.errors.type.message}</p>
                  )}
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="log-description">Description</Label>
                  <Textarea id="log-description" rows={2} {...logForm.register("description")} />
                  {logForm.formState.errors.description && (
                    <p className="text-xs text-destructive">{logForm.formState.errors.description.message}</p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="log-date">Date</Label>
                    <Input id="log-date" type="date" {...logForm.register("date")} />
                    {logForm.formState.errors.date && (
                      <p className="text-xs text-destructive">{logForm.formState.errors.date.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="log-cost">Cost</Label>
                    <Input id="log-cost" type="number" step="any" {...logForm.register("cost")} />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="performed_by">Performed by</Label>
                    <Input id="performed_by" placeholder="Mechanic / vendor name" {...logForm.register("performed_by")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="next_service_date">Next service date</Label>
                    <Input id="next_service_date" type="date" {...logForm.register("next_service_date")} />
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="log-notes">Notes</Label>
                  <Textarea id="log-notes" rows={2} {...logForm.register("notes")} />
                </div>
                {logError && <p className="text-sm text-destructive">{logError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createLogMutation.isPending}>
                    {createLogMutation.isPending ? "Adding…" : "Add log"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {logsLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !logs || logs.length === 0 ? (
            <p className="text-sm text-muted-foreground">No maintenance logs yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Type</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead>Cost</TableHead>
                  <TableHead>Next service</TableHead>
                  <TableHead>Recorded by</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {logs.map((log) => (
                  <TableRow key={log.id}>
                    <TableCell className="font-medium">{formatRole(log.type)}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {new Date(log.date).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{log.description}</TableCell>
                    <TableCell className="text-muted-foreground">{log.cost ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {log.next_service_date ? new Date(log.next_service_date).toLocaleDateString() : "—"}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{log.recorder.name}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Dialog open={assetEditOpen} onOpenChange={setAssetEditOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit asset</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={assetEditForm.handleSubmit((values) => {
              setAssetEditError(null);
              editAssetMutation.mutate(values);
            })}
            className="flex flex-col gap-4"
          >
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="edit-asset-name">Name</Label>
              <Input id="edit-asset-name" {...assetEditForm.register("name")} />
              {assetEditForm.formState.errors.name && (
                <p className="text-xs text-destructive">{assetEditForm.formState.errors.name.message}</p>
              )}
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-asset-category">Category</Label>
                <Input id="edit-asset-category" {...assetEditForm.register("category")} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-asset-serial">Serial number</Label>
                <Input id="edit-asset-serial" {...assetEditForm.register("serial_number")} />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-purchase_date">Purchase date</Label>
                <Input id="edit-purchase_date" type="date" {...assetEditForm.register("purchase_date")} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-purchase_cost">Purchase cost</Label>
                <Input id="edit-purchase_cost" type="number" step="any" {...assetEditForm.register("purchase_cost")} />
              </div>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Assigned to</Label>
              <Select value={editAssignedTo || "unassigned"} onValueChange={(v) => setEditAssignedTo(v === "unassigned" ? "" : v)}>
                <SelectTrigger>
                  <SelectValue placeholder="Unassigned" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="unassigned">Unassigned</SelectItem>
                  {(members ?? []).map((member) => (
                    <SelectItem key={member.id} value={String(member.id)}>
                      {member.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="edit-asset-notes">Notes</Label>
              <Textarea id="edit-asset-notes" rows={2} {...assetEditForm.register("notes")} />
            </div>
            {assetEditError && <p className="text-sm text-destructive">{assetEditError}</p>}
            <DialogFooter>
              <Button type="submit" disabled={editAssetMutation.isPending}>
                {editAssetMutation.isPending ? "Saving…" : "Save changes"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
