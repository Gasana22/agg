"use client";

import * as React from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { Plus } from "lucide-react";

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
  listMaintenanceLogs,
  createMaintenanceLog,
  ASSET_STATUSES,
  MAINTENANCE_LOG_TYPES,
} from "@/lib/modules/assets";
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

  const { data: asset } = useQuery({
    queryKey: ["asset", assetId],
    queryFn: () => getAsset(assetId),
  });

  const { data: logs, isLoading: logsLoading } = useQuery({
    queryKey: ["asset-maintenance-logs", assetId],
    queryFn: () => listMaintenanceLogs(assetId),
  });

  const logForm = useForm<LogFormValues>({ resolver: zodResolver(logSchema) });

  const statusMutation = useMutation({
    mutationFn: (status: string) => updateAssetStatus(assetId, status as never),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["asset", assetId] });
      queryClient.invalidateQueries({ queryKey: ["assets"] });
    },
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
    </div>
  );
}
