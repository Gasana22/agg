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
  getCropSeason,
  updateCropSeasonStatus,
  listCropActivities,
  createCropActivity,
  listCropMonitoringLogs,
  createCropMonitoringLog,
  listCropHarvests,
  createCropHarvest,
  createCropSale,
  CROP_SEASON_STATUSES,
  CROP_ACTIVITY_TYPES,
  CROP_MONITORING_TYPES,
  type CropHarvest,
} from "@/lib/modules/crop-management";
import { formatRole } from "@/lib/utils";

const activitySchema = z.object({
  type: z.string().min(1, "Pick a type"),
  date: z.string().min(1, "Date is required"),
  cost: z.string().optional(),
  notes: z.string().optional(),
});
type ActivityFormValues = z.infer<typeof activitySchema>;

const monitoringSchema = z.object({
  type: z.string().min(1, "Pick a type"),
  date: z.string().min(1, "Date is required"),
  description: z.string().min(1, "Description is required"),
  severity: z.string().optional(),
});
type MonitoringFormValues = z.infer<typeof monitoringSchema>;

const harvestSchema = z.object({
  harvest_date: z.string().min(1, "Date is required"),
  quantity: z.string().min(1, "Quantity is required"),
  unit: z.string().min(1, "Unit is required"),
  quality_grade: z.string().optional(),
  notes: z.string().optional(),
});
type HarvestFormValues = z.infer<typeof harvestSchema>;

const saleSchema = z.object({
  buyer_name: z.string().min(1, "Buyer name is required"),
  quantity_sold: z.string().min(1, "Quantity is required"),
  unit_price: z.string().min(1, "Unit price is required"),
  sale_date: z.string().min(1, "Date is required"),
  notes: z.string().optional(),
});
type SaleFormValues = z.infer<typeof saleSchema>;

const STATUS_VARIANT: Record<string, "default" | "secondary" | "warning" | "success"> = {
  planning: "secondary",
  nursery: "secondary",
  field: "default",
  monitoring: "warning",
  harvested: "success",
  closed: "secondary",
};

export default function CropSeasonDetailPage() {
  const params = useParams<{ seasonId: string }>();
  const seasonId = Number(params.seasonId);
  const queryClient = useQueryClient();

  const [activityOpen, setActivityOpen] = React.useState(false);
  const [monitoringOpen, setMonitoringOpen] = React.useState(false);
  const [harvestOpen, setHarvestOpen] = React.useState(false);
  const [saleHarvest, setSaleHarvest] = React.useState<CropHarvest | null>(null);
  const [activityError, setActivityError] = React.useState<string | null>(null);
  const [monitoringError, setMonitoringError] = React.useState<string | null>(null);
  const [harvestError, setHarvestError] = React.useState<string | null>(null);
  const [saleError, setSaleError] = React.useState<string | null>(null);

  const { data: season } = useQuery({
    queryKey: ["crop-season", seasonId],
    queryFn: () => getCropSeason(seasonId),
  });

  const { data: activities, isLoading: activitiesLoading } = useQuery({
    queryKey: ["crop-activities", seasonId],
    queryFn: () => listCropActivities(seasonId),
  });

  const { data: logs, isLoading: logsLoading } = useQuery({
    queryKey: ["crop-monitoring-logs", seasonId],
    queryFn: () => listCropMonitoringLogs(seasonId),
  });

  const { data: harvests, isLoading: harvestsLoading } = useQuery({
    queryKey: ["crop-harvests", seasonId],
    queryFn: () => listCropHarvests(seasonId),
  });

  const activityForm = useForm<ActivityFormValues>({ resolver: zodResolver(activitySchema) });
  const monitoringForm = useForm<MonitoringFormValues>({ resolver: zodResolver(monitoringSchema) });
  const harvestForm = useForm<HarvestFormValues>({ resolver: zodResolver(harvestSchema) });
  const saleForm = useForm<SaleFormValues>({ resolver: zodResolver(saleSchema) });

  const statusMutation = useMutation({
    mutationFn: (status: string) => updateCropSeasonStatus(seasonId, status as never),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["crop-season", seasonId] });
      queryClient.invalidateQueries({ queryKey: ["crop-seasons"] });
    },
  });

  const createActivityMutation = useMutation({
    mutationFn: (values: ActivityFormValues) =>
      createCropActivity(seasonId, {
        type: values.type,
        date: values.date,
        cost: values.cost ? Number(values.cost) : undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["crop-activities", seasonId] });
      queryClient.invalidateQueries({ queryKey: ["crop-seasons"] });
      setActivityOpen(false);
      activityForm.reset();
    },
    onError: (err) =>
      setActivityError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add activity." : "Something went wrong."
      ),
  });

  const createMonitoringMutation = useMutation({
    mutationFn: (values: MonitoringFormValues) =>
      createCropMonitoringLog(seasonId, {
        type: values.type,
        date: values.date,
        description: values.description,
        severity: values.severity || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["crop-monitoring-logs", seasonId] });
      setMonitoringOpen(false);
      monitoringForm.reset();
    },
    onError: (err) =>
      setMonitoringError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add log." : "Something went wrong."
      ),
  });

  const createHarvestMutation = useMutation({
    mutationFn: (values: HarvestFormValues) =>
      createCropHarvest(seasonId, {
        harvest_date: values.harvest_date,
        quantity: Number(values.quantity),
        unit: values.unit,
        quality_grade: values.quality_grade || undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["crop-harvests", seasonId] });
      queryClient.invalidateQueries({ queryKey: ["crop-seasons"] });
      setHarvestOpen(false);
      harvestForm.reset();
    },
    onError: (err) =>
      setHarvestError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add harvest." : "Something went wrong."
      ),
  });

  const createSaleMutation = useMutation({
    mutationFn: (values: SaleFormValues) =>
      createCropSale(saleHarvest!.id, {
        buyer_name: values.buyer_name,
        quantity_sold: Number(values.quantity_sold),
        unit_price: Number(values.unit_price),
        sale_date: values.sale_date,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["crop-harvests", seasonId] });
      setSaleHarvest(null);
      saleForm.reset();
    },
    onError: (err) =>
      setSaleError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not record sale." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{season?.season_name ?? "Crop season"}</h1>
          <p className="text-sm text-muted-foreground">
            {season?.crop.name}
            {season?.crop.variety ? ` (${season.crop.variety})` : ""}
          </p>
        </div>
        {season && (
          <div className="flex items-center gap-2">
            <Badge variant={STATUS_VARIANT[season.status] ?? "secondary"}>{formatRole(season.status)}</Badge>
            <Select value={season.status} onValueChange={(v) => statusMutation.mutate(v)}>
              <SelectTrigger className="h-8 w-44">
                <SelectValue placeholder="Change status" />
              </SelectTrigger>
              <SelectContent>
                {CROP_SEASON_STATUSES.map((status) => (
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
          <CardTitle>Activities</CardTitle>
          <Dialog open={activityOpen} onOpenChange={setActivityOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add activity
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Log an activity</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={activityForm.handleSubmit((values) => {
                  setActivityError(null);
                  createActivityMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label>Type</Label>
                  <Select onValueChange={(v) => activityForm.setValue("type", v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select a type" />
                    </SelectTrigger>
                    <SelectContent>
                      {CROP_ACTIVITY_TYPES.map((type) => (
                        <SelectItem key={type} value={type}>
                          {formatRole(type)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {activityForm.formState.errors.type && (
                    <p className="text-xs text-destructive">{activityForm.formState.errors.type.message}</p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="activity-date">Date</Label>
                    <Input id="activity-date" type="date" {...activityForm.register("date")} />
                    {activityForm.formState.errors.date && (
                      <p className="text-xs text-destructive">{activityForm.formState.errors.date.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="activity-cost">Cost</Label>
                    <Input id="activity-cost" type="number" step="any" {...activityForm.register("cost")} />
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="activity-notes">Notes</Label>
                  <Textarea id="activity-notes" rows={2} {...activityForm.register("notes")} />
                </div>
                {activityError && <p className="text-sm text-destructive">{activityError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createActivityMutation.isPending}>
                    {createActivityMutation.isPending ? "Adding…" : "Add activity"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {activitiesLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !activities || activities.length === 0 ? (
            <p className="text-sm text-muted-foreground">No activities logged yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Type</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Cost</TableHead>
                  <TableHead>Performed by</TableHead>
                  <TableHead>Notes</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {activities.map((activity) => (
                  <TableRow key={activity.id}>
                    <TableCell className="font-medium">{formatRole(activity.type)}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {new Date(activity.date).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{activity.cost ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{activity.performer.name}</TableCell>
                    <TableCell className="text-muted-foreground">{activity.notes ?? "—"}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Monitoring logs</CardTitle>
          <Dialog open={monitoringOpen} onOpenChange={setMonitoringOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add log
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Log a monitoring observation</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={monitoringForm.handleSubmit((values) => {
                  setMonitoringError(null);
                  createMonitoringMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label>Type</Label>
                  <Select onValueChange={(v) => monitoringForm.setValue("type", v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select a type" />
                    </SelectTrigger>
                    <SelectContent>
                      {CROP_MONITORING_TYPES.map((type) => (
                        <SelectItem key={type} value={type}>
                          {formatRole(type)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {monitoringForm.formState.errors.type && (
                    <p className="text-xs text-destructive">{monitoringForm.formState.errors.type.message}</p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="monitoring-date">Date</Label>
                    <Input id="monitoring-date" type="date" {...monitoringForm.register("date")} />
                    {monitoringForm.formState.errors.date && (
                      <p className="text-xs text-destructive">{monitoringForm.formState.errors.date.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="monitoring-severity">Severity</Label>
                    <Input id="monitoring-severity" placeholder="low / medium / high" {...monitoringForm.register("severity")} />
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="monitoring-description">Description</Label>
                  <Textarea id="monitoring-description" rows={2} {...monitoringForm.register("description")} />
                  {monitoringForm.formState.errors.description && (
                    <p className="text-xs text-destructive">
                      {monitoringForm.formState.errors.description.message}
                    </p>
                  )}
                </div>
                {monitoringError && <p className="text-sm text-destructive">{monitoringError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createMonitoringMutation.isPending}>
                    {createMonitoringMutation.isPending ? "Adding…" : "Add log"}
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
            <p className="text-sm text-muted-foreground">No monitoring logs yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Type</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Severity</TableHead>
                  <TableHead>Reported by</TableHead>
                  <TableHead>Description</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {logs.map((log) => (
                  <TableRow key={log.id}>
                    <TableCell className="font-medium">{formatRole(log.type)}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {new Date(log.date).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{log.severity ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{log.reporter.name}</TableCell>
                    <TableCell className="text-muted-foreground">{log.description}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Harvests</CardTitle>
          <Dialog open={harvestOpen} onOpenChange={setHarvestOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add harvest
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Record a harvest</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={harvestForm.handleSubmit((values) => {
                  setHarvestError(null);
                  createHarvestMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="harvest-date">Harvest date</Label>
                  <Input id="harvest-date" type="date" {...harvestForm.register("harvest_date")} />
                  {harvestForm.formState.errors.harvest_date && (
                    <p className="text-xs text-destructive">
                      {harvestForm.formState.errors.harvest_date.message}
                    </p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="harvest-quantity">Quantity</Label>
                    <Input id="harvest-quantity" type="number" step="any" {...harvestForm.register("quantity")} />
                    {harvestForm.formState.errors.quantity && (
                      <p className="text-xs text-destructive">
                        {harvestForm.formState.errors.quantity.message}
                      </p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="harvest-unit">Unit</Label>
                    <Input id="harvest-unit" placeholder="kg" {...harvestForm.register("unit")} />
                    {harvestForm.formState.errors.unit && (
                      <p className="text-xs text-destructive">{harvestForm.formState.errors.unit.message}</p>
                    )}
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="harvest-grade">Quality grade</Label>
                  <Input id="harvest-grade" {...harvestForm.register("quality_grade")} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="harvest-notes">Notes</Label>
                  <Textarea id="harvest-notes" rows={2} {...harvestForm.register("notes")} />
                </div>
                {harvestError && <p className="text-sm text-destructive">{harvestError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createHarvestMutation.isPending}>
                    {createHarvestMutation.isPending ? "Adding…" : "Add harvest"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {harvestsLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !harvests || harvests.length === 0 ? (
            <p className="text-sm text-muted-foreground">No harvests recorded yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Date</TableHead>
                  <TableHead>Quantity</TableHead>
                  <TableHead>Grade</TableHead>
                  <TableHead>Recorded by</TableHead>
                  <TableHead>Sales</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {harvests.map((harvest) => (
                  <TableRow key={harvest.id}>
                    <TableCell className="font-medium">
                      {new Date(harvest.harvest_date).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {harvest.quantity} {harvest.unit}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{harvest.quality_grade ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{harvest.recorder.name}</TableCell>
                    <TableCell className="text-muted-foreground">{harvest.sales_count ?? 0}</TableCell>
                    <TableCell>
                      <Button variant="outline" size="sm" onClick={() => setSaleHarvest(harvest)}>
                        Record sale
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Dialog
        open={!!saleHarvest}
        onOpenChange={(open) => {
          if (!open) {
            setSaleHarvest(null);
            setSaleError(null);
            saleForm.reset();
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              Record a sale
              {saleHarvest ? ` — ${new Date(saleHarvest.harvest_date).toLocaleDateString()} harvest` : ""}
            </DialogTitle>
          </DialogHeader>
          <form
            onSubmit={saleForm.handleSubmit((values) => {
              setSaleError(null);
              createSaleMutation.mutate(values);
            })}
            className="flex flex-col gap-4"
          >
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="sale-buyer">Buyer name</Label>
              <Input id="sale-buyer" {...saleForm.register("buyer_name")} />
              {saleForm.formState.errors.buyer_name && (
                <p className="text-xs text-destructive">{saleForm.formState.errors.buyer_name.message}</p>
              )}
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="sale-quantity">Quantity sold</Label>
                <Input id="sale-quantity" type="number" step="any" {...saleForm.register("quantity_sold")} />
                {saleForm.formState.errors.quantity_sold && (
                  <p className="text-xs text-destructive">
                    {saleForm.formState.errors.quantity_sold.message}
                  </p>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="sale-price">Unit price</Label>
                <Input id="sale-price" type="number" step="any" {...saleForm.register("unit_price")} />
                {saleForm.formState.errors.unit_price && (
                  <p className="text-xs text-destructive">{saleForm.formState.errors.unit_price.message}</p>
                )}
              </div>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="sale-date">Sale date</Label>
              <Input id="sale-date" type="date" {...saleForm.register("sale_date")} />
              {saleForm.formState.errors.sale_date && (
                <p className="text-xs text-destructive">{saleForm.formState.errors.sale_date.message}</p>
              )}
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="sale-notes">Notes</Label>
              <Textarea id="sale-notes" rows={2} {...saleForm.register("notes")} />
            </div>
            {saleError && <p className="text-sm text-destructive">{saleError}</p>}
            <DialogFooter>
              <Button type="submit" disabled={createSaleMutation.isPending}>
                {createSaleMutation.isPending ? "Recording…" : "Record sale"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
