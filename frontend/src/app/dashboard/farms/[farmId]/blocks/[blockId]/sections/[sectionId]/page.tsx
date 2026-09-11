"use client";

import * as React from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { Plus, Trash2, Pencil } from "lucide-react";

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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import {
  getSection,
  updateSection,
  listPlots,
  createPlot,
  updatePlot,
  deletePlot,
  type Plot,
} from "@/lib/modules/farm-structure";

const plotSchema = z.object({
  name: z.string().min(1, "Name is required"),
  gps_lat: z.string().optional(),
  gps_lng: z.string().optional(),
});
type PlotFormValues = z.infer<typeof plotSchema>;

const sectionEditSchema = z.object({
  name: z.string().min(1, "Name is required"),
  gps_lat: z.string().optional(),
  gps_lng: z.string().optional(),
});
type SectionEditFormValues = z.infer<typeof sectionEditSchema>;

export default function SectionDetailPage() {
  const params = useParams<{ farmId: string; blockId: string; sectionId: string }>();
  const farmId = Number(params.farmId);
  const blockId = Number(params.blockId);
  const sectionId = Number(params.sectionId);
  const queryClient = useQueryClient();

  const [plotOpen, setPlotOpen] = React.useState(false);
  const [plotError, setPlotError] = React.useState<string | null>(null);
  const [editingPlot, setEditingPlot] = React.useState<Plot | null>(null);
  const [plotEditError, setPlotEditError] = React.useState<string | null>(null);
  const [sectionEditOpen, setSectionEditOpen] = React.useState(false);
  const [sectionEditError, setSectionEditError] = React.useState<string | null>(null);

  const { data: section } = useQuery({
    queryKey: ["section", sectionId],
    queryFn: () => getSection(sectionId),
  });

  const { data: plots, isLoading: plotsLoading } = useQuery({
    queryKey: ["plots", sectionId],
    queryFn: () => listPlots(sectionId),
  });

  const plotForm = useForm<PlotFormValues>({ resolver: zodResolver(plotSchema) });
  const plotEditForm = useForm<PlotFormValues>({ resolver: zodResolver(plotSchema) });
  const sectionEditForm = useForm<SectionEditFormValues>({ resolver: zodResolver(sectionEditSchema) });

  const createPlotMutation = useMutation({
    mutationFn: (values: PlotFormValues) =>
      createPlot(sectionId, {
        name: values.name,
        gps_lat: values.gps_lat ? Number(values.gps_lat) : undefined,
        gps_lng: values.gps_lng ? Number(values.gps_lng) : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["plots", sectionId] });
      setPlotOpen(false);
      plotForm.reset();
    },
    onError: (err) =>
      setPlotError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add plot." : "Something went wrong."
      ),
  });

  const editPlotMutation = useMutation({
    mutationFn: (values: PlotFormValues) =>
      updatePlot(editingPlot!.id, {
        name: values.name,
        gps_lat: values.gps_lat ? Number(values.gps_lat) : undefined,
        gps_lng: values.gps_lng ? Number(values.gps_lng) : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["plots", sectionId] });
      setEditingPlot(null);
    },
    onError: (err) =>
      setPlotEditError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not update plot." : "Something went wrong."
      ),
  });

  const deletePlotMutation = useMutation({
    mutationFn: (plotId: number) => deletePlot(plotId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["plots", sectionId] }),
  });

  const editSectionMutation = useMutation({
    mutationFn: (values: SectionEditFormValues) =>
      updateSection(sectionId, {
        name: values.name,
        gps_lat: values.gps_lat ? Number(values.gps_lat) : undefined,
        gps_lng: values.gps_lng ? Number(values.gps_lng) : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["section", sectionId] });
      queryClient.invalidateQueries({ queryKey: ["sections", blockId] });
      setSectionEditOpen(false);
    },
    onError: (err) =>
      setSectionEditError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not update section." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link
          href={`/dashboard/farms/${farmId}/blocks/${blockId}`}
          className="text-sm text-muted-foreground hover:underline"
        >
          ← Back to block
        </Link>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{section?.name ?? "Section"}</h1>
          <p className="text-sm text-muted-foreground">
            {section?.gps_lat && section?.gps_lng ? `${section.gps_lat}, ${section.gps_lng}` : "No GPS set"}
          </p>
        </div>
        <Dialog
          open={sectionEditOpen}
          onOpenChange={(open) => {
            setSectionEditOpen(open);
            if (open && section) {
              sectionEditForm.reset({
                name: section.name,
                gps_lat: section.gps_lat ?? "",
                gps_lng: section.gps_lng ?? "",
              });
              setSectionEditError(null);
            }
          }}
        >
          <DialogTrigger asChild>
            <Button size="sm" variant="outline" className="gap-2" disabled={!section}>
              <Pencil className="size-4" />
              Edit section
            </Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Edit section</DialogTitle>
            </DialogHeader>
            <form
              onSubmit={sectionEditForm.handleSubmit((values) => {
                setSectionEditError(null);
                editSectionMutation.mutate(values);
              })}
              className="flex flex-col gap-4"
            >
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-section-detail-name">Name</Label>
                <Input id="edit-section-detail-name" {...sectionEditForm.register("name")} />
                {sectionEditForm.formState.errors.name && (
                  <p className="text-xs text-destructive">{sectionEditForm.formState.errors.name.message}</p>
                )}
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="edit-section-detail-lat">GPS latitude</Label>
                  <Input
                    id="edit-section-detail-lat"
                    type="number"
                    step="any"
                    {...sectionEditForm.register("gps_lat")}
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="edit-section-detail-lng">GPS longitude</Label>
                  <Input
                    id="edit-section-detail-lng"
                    type="number"
                    step="any"
                    {...sectionEditForm.register("gps_lng")}
                  />
                </div>
              </div>
              {sectionEditError && <p className="text-sm text-destructive">{sectionEditError}</p>}
              <DialogFooter>
                <Button type="submit" disabled={editSectionMutation.isPending}>
                  {editSectionMutation.isPending ? "Saving…" : "Save changes"}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Plots</CardTitle>
          <Dialog open={plotOpen} onOpenChange={setPlotOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add plot
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Add a plot</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={plotForm.handleSubmit((values) => {
                  setPlotError(null);
                  createPlotMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="plot-name">Name</Label>
                  <Input id="plot-name" {...plotForm.register("name")} />
                  {plotForm.formState.errors.name && (
                    <p className="text-xs text-destructive">{plotForm.formState.errors.name.message}</p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="plot-lat">GPS latitude</Label>
                    <Input id="plot-lat" type="number" step="any" {...plotForm.register("gps_lat")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="plot-lng">GPS longitude</Label>
                    <Input id="plot-lng" type="number" step="any" {...plotForm.register("gps_lng")} />
                  </div>
                </div>
                {plotError && <p className="text-sm text-destructive">{plotError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createPlotMutation.isPending}>
                    {createPlotMutation.isPending ? "Adding…" : "Add plot"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {plotsLoading ? (
            <p className="text-sm text-muted-foreground">Loading plots…</p>
          ) : !plots || plots.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No plots yet. Plots are the smallest unit crop seasons can be assigned to.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>GPS</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-20" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {plots.map((plot) => (
                  <TableRow key={plot.id}>
                    <TableCell className="font-medium">{plot.name}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {plot.gps_lat && plot.gps_lng ? `${plot.gps_lat}, ${plot.gps_lng}` : "—"}
                    </TableCell>
                    <TableCell>
                      <Badge variant={plot.is_active ? "success" : "secondary"}>
                        {plot.is_active ? "Active" : "Inactive"}
                      </Badge>
                    </TableCell>
                    <TableCell className="flex justify-end gap-1">
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => {
                          setEditingPlot(plot);
                          plotEditForm.reset({
                            name: plot.name,
                            gps_lat: plot.gps_lat ?? "",
                            gps_lng: plot.gps_lng ?? "",
                          });
                          setPlotEditError(null);
                        }}
                      >
                        <Pencil className="size-4" />
                      </Button>
                      <Button variant="ghost" size="icon" onClick={() => deletePlotMutation.mutate(plot.id)}>
                        <Trash2 className="size-4 text-destructive" />
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
        open={!!editingPlot}
        onOpenChange={(open) => {
          if (!open) {
            setEditingPlot(null);
            setPlotEditError(null);
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit plot</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={plotEditForm.handleSubmit((values) => {
              setPlotEditError(null);
              editPlotMutation.mutate(values);
            })}
            className="flex flex-col gap-4"
          >
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="edit-plot-name">Name</Label>
              <Input id="edit-plot-name" {...plotEditForm.register("name")} />
              {plotEditForm.formState.errors.name && (
                <p className="text-xs text-destructive">{plotEditForm.formState.errors.name.message}</p>
              )}
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-plot-lat">GPS latitude</Label>
                <Input id="edit-plot-lat" type="number" step="any" {...plotEditForm.register("gps_lat")} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-plot-lng">GPS longitude</Label>
                <Input id="edit-plot-lng" type="number" step="any" {...plotEditForm.register("gps_lng")} />
              </div>
            </div>
            {plotEditError && <p className="text-sm text-destructive">{plotEditError}</p>}
            <DialogFooter>
              <Button type="submit" disabled={editPlotMutation.isPending}>
                {editPlotMutation.isPending ? "Saving…" : "Save changes"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
