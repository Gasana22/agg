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
import { listCrops, createCrop, listCropSeasons, createCropSeason } from "@/lib/modules/crop-management";
import { useFarm } from "@/lib/farm-context";
import { formatRole } from "@/lib/utils";

const cropSchema = z.object({
  name: z.string().min(1, "Name is required"),
  variety: z.string().optional(),
  category: z.string().optional(),
  description: z.string().optional(),
});
type CropFormValues = z.infer<typeof cropSchema>;

const seasonSchema = z.object({
  crop_id: z.string().min(1, "Pick a crop"),
  season_name: z.string().min(1, "Season name is required"),
  planned_planting_date: z.string().optional(),
  budget: z.string().optional(),
  expected_yield: z.string().optional(),
  expected_yield_unit: z.string().optional(),
});
type SeasonFormValues = z.infer<typeof seasonSchema>;

const STATUS_VARIANT: Record<string, "default" | "secondary" | "warning" | "success"> = {
  planning: "secondary",
  nursery: "secondary",
  field: "default",
  monitoring: "warning",
  harvested: "success",
  closed: "secondary",
};

export default function CropsPage() {
  const { currentFarmId } = useFarm();
  const queryClient = useQueryClient();
  const [cropOpen, setCropOpen] = React.useState(false);
  const [seasonOpen, setSeasonOpen] = React.useState(false);
  const [cropError, setCropError] = React.useState<string | null>(null);
  const [seasonError, setSeasonError] = React.useState<string | null>(null);

  const { data: crops, isLoading: cropsLoading } = useQuery({
    queryKey: ["crops", currentFarmId],
    queryFn: () => listCrops(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const { data: seasons, isLoading: seasonsLoading } = useQuery({
    queryKey: ["crop-seasons", currentFarmId],
    queryFn: () => listCropSeasons(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const cropForm = useForm<CropFormValues>({ resolver: zodResolver(cropSchema) });
  const seasonForm = useForm<SeasonFormValues>({ resolver: zodResolver(seasonSchema) });

  const createCropMutation = useMutation({
    mutationFn: (values: CropFormValues) => createCrop(currentFarmId!, values),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["crops", currentFarmId] });
      setCropOpen(false);
      cropForm.reset();
    },
    onError: (err) =>
      setCropError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not create crop." : "Something went wrong."
      ),
  });

  const createSeasonMutation = useMutation({
    mutationFn: (values: SeasonFormValues) =>
      createCropSeason(currentFarmId!, {
        crop_id: Number(values.crop_id),
        season_name: values.season_name,
        planned_planting_date: values.planned_planting_date || undefined,
        budget: values.budget ? Number(values.budget) : undefined,
        expected_yield: values.expected_yield ? Number(values.expected_yield) : undefined,
        expected_yield_unit: values.expected_yield_unit || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["crop-seasons", currentFarmId] });
      queryClient.invalidateQueries({ queryKey: ["crops", currentFarmId] });
      setSeasonOpen(false);
      seasonForm.reset();
    },
    onError: (err) =>
      setSeasonError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not create season." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Crop Management</h1>
        <p className="text-sm text-muted-foreground">Crop catalog and the seasons planted from it.</p>
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Crop catalog</CardTitle>
          <Dialog open={cropOpen} onOpenChange={setCropOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add crop
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Add a crop</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={cropForm.handleSubmit((values) => {
                  setCropError(null);
                  createCropMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="crop-name">Name</Label>
                  <Input id="crop-name" {...cropForm.register("name")} />
                  {cropForm.formState.errors.name && (
                    <p className="text-xs text-destructive">{cropForm.formState.errors.name.message}</p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="variety">Variety</Label>
                    <Input id="variety" {...cropForm.register("variety")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="category">Category</Label>
                    <Input id="category" {...cropForm.register("category")} />
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="description">Description</Label>
                  <Textarea id="description" rows={2} {...cropForm.register("description")} />
                </div>
                {cropError && <p className="text-sm text-destructive">{cropError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createCropMutation.isPending}>
                    {createCropMutation.isPending ? "Adding…" : "Add crop"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {cropsLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !crops || crops.length === 0 ? (
            <p className="text-sm text-muted-foreground">No crops in the catalog yet.</p>
          ) : (
            <div className="flex flex-wrap gap-2">
              {crops.map((crop) => (
                <Badge key={crop.id} variant="outline" className="px-3 py-1.5 text-sm">
                  {crop.name}
                  {crop.variety && <span className="text-muted-foreground">· {crop.variety}</span>}
                  <span className="ml-1 text-muted-foreground">({crop.seasons_count ?? 0})</span>
                </Badge>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Crop seasons</CardTitle>
          <Dialog open={seasonOpen} onOpenChange={setSeasonOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2" disabled={!crops || crops.length === 0}>
                <Plus className="size-4" />
                New season
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Start a crop season</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={seasonForm.handleSubmit((values) => {
                  setSeasonError(null);
                  createSeasonMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label>Crop</Label>
                  <Select onValueChange={(v) => seasonForm.setValue("crop_id", v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select a crop" />
                    </SelectTrigger>
                    <SelectContent>
                      {(crops ?? []).map((crop) => (
                        <SelectItem key={crop.id} value={String(crop.id)}>
                          {crop.name}
                          {crop.variety ? ` (${crop.variety})` : ""}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {seasonForm.formState.errors.crop_id && (
                    <p className="text-xs text-destructive">{seasonForm.formState.errors.crop_id.message}</p>
                  )}
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="season_name">Season name</Label>
                  <Input id="season_name" placeholder="e.g. 2026 Season A" {...seasonForm.register("season_name")} />
                  {seasonForm.formState.errors.season_name && (
                    <p className="text-xs text-destructive">
                      {seasonForm.formState.errors.season_name.message}
                    </p>
                  )}
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="planned_planting_date">Planned planting date</Label>
                  <Input id="planned_planting_date" type="date" {...seasonForm.register("planned_planting_date")} />
                </div>
                <div className="grid grid-cols-3 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="budget">Budget</Label>
                    <Input id="budget" type="number" step="any" {...seasonForm.register("budget")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="expected_yield">Expected yield</Label>
                    <Input id="expected_yield" type="number" step="any" {...seasonForm.register("expected_yield")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="expected_yield_unit">Unit</Label>
                    <Input id="expected_yield_unit" placeholder="kg" {...seasonForm.register("expected_yield_unit")} />
                  </div>
                </div>
                {seasonError && <p className="text-sm text-destructive">{seasonError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createSeasonMutation.isPending}>
                    {createSeasonMutation.isPending ? "Starting…" : "Start season"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {seasonsLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !seasons || seasons.length === 0 ? (
            <p className="text-sm text-muted-foreground">No crop seasons yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Season</TableHead>
                  <TableHead>Crop</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Activities</TableHead>
                  <TableHead>Harvests</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {seasons.map((season) => (
                  <TableRow key={season.id}>
                    <TableCell className="font-medium">
                      <Link href={`/dashboard/crops/seasons/${season.id}`} className="hover:underline">
                        {season.season_name}
                      </Link>
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {season.crop.name}
                      {season.crop.variety ? ` (${season.crop.variety})` : ""}
                    </TableCell>
                    <TableCell>
                      <Badge variant={STATUS_VARIANT[season.status] ?? "secondary"}>
                        {formatRole(season.status)}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{season.activities_count ?? 0}</TableCell>
                    <TableCell className="text-muted-foreground">{season.harvests_count ?? 0}</TableCell>
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
