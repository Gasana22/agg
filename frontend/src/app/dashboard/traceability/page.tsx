"use client";

import * as React from "react";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { isAxiosError } from "axios";
import { Plus } from "lucide-react";

import { Button } from "@/components/ui/button";
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
import { listTraceBatches, createTraceBatch } from "@/lib/modules/traceability";
import { listCropSeasons, listCropHarvests } from "@/lib/modules/crop-management";
import { listAnimals, listProductionRecords } from "@/lib/modules/livestock";
import { useFarm } from "@/lib/farm-context";
import { usePermissions } from "@/lib/permissions";
import { formatRole } from "@/lib/utils";

type SourceType = "crop_harvest" | "animal_production_record";

const STATUS_VARIANT: Record<string, "default" | "secondary" | "warning" | "success" | "destructive"> = {
  active: "default",
  sold: "success",
  recalled: "destructive",
};

function resetRegisterState() {
  return { sourceType: "crop_harvest" as SourceType, parentId: "", sourceId: "" };
}

export default function TraceabilityPage() {
  const { currentFarmId } = useFarm();
  const { canManageCrops, canManageLivestock } = usePermissions();
  const queryClient = useQueryClient();
  const [registerOpen, setRegisterOpen] = React.useState(false);
  const [registerError, setRegisterError] = React.useState<string | null>(null);
  const [{ sourceType, parentId, sourceId }, setRegisterState] = React.useState(resetRegisterState);

  const { data: batches, isLoading: batchesLoading } = useQuery({
    queryKey: ["trace-batches", currentFarmId],
    queryFn: () => listTraceBatches(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const { data: cropSeasons } = useQuery({
    queryKey: ["crop-seasons", currentFarmId],
    queryFn: () => listCropSeasons(currentFarmId!),
    enabled: !!currentFarmId && registerOpen && sourceType === "crop_harvest",
  });

  const { data: harvests } = useQuery({
    queryKey: ["crop-harvests", parentId],
    queryFn: () => listCropHarvests(Number(parentId)),
    enabled: sourceType === "crop_harvest" && !!parentId,
  });

  const { data: animals } = useQuery({
    queryKey: ["animals", currentFarmId],
    queryFn: () => listAnimals(currentFarmId!),
    enabled: !!currentFarmId && registerOpen && sourceType === "animal_production_record",
  });

  const { data: productionRecords } = useQuery({
    queryKey: ["animal-production-records", parentId],
    queryFn: () => listProductionRecords(Number(parentId)),
    enabled: sourceType === "animal_production_record" && !!parentId,
  });

  const createBatchMutation = useMutation({
    mutationFn: () => createTraceBatch(currentFarmId!, { source_type: sourceType, source_id: Number(sourceId) }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["trace-batches", currentFarmId] });
      setRegisterOpen(false);
      setRegisterState(resetRegisterState());
    },
    onError: (err) =>
      setRegisterError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not register batch." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Traceability</h1>
        <p className="text-sm text-muted-foreground">Chain-of-custody batches from harvest or production to sale.</p>
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Trace batches</CardTitle>
          {(canManageCrops || canManageLivestock) && (
          <Dialog
            open={registerOpen}
            onOpenChange={(open) => {
              setRegisterOpen(open);
              if (!open) {
                setRegisterError(null);
                setRegisterState(resetRegisterState());
              }
            }}
          >
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Register a batch
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Register a trace batch</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  setRegisterError(null);
                  if (!sourceId) {
                    setRegisterError("Pick a record to register.");
                    return;
                  }
                  createBatchMutation.mutate();
                }}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label>Source</Label>
                  <Select
                    value={sourceType}
                    onValueChange={(v) => setRegisterState({ sourceType: v as SourceType, parentId: "", sourceId: "" })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="crop_harvest">Crop harvest</SelectItem>
                      <SelectItem value="animal_production_record">Animal production record</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                {sourceType === "crop_harvest" ? (
                  <>
                    <div className="flex flex-col gap-1.5">
                      <Label>Crop season</Label>
                      <Select
                        value={parentId}
                        onValueChange={(v) => setRegisterState({ sourceType, parentId: v, sourceId: "" })}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Select a season" />
                        </SelectTrigger>
                        <SelectContent>
                          {(cropSeasons ?? []).map((season) => (
                            <SelectItem key={season.id} value={String(season.id)}>
                              {season.season_name} — {season.crop.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <Label>Harvest</Label>
                      <Select
                        value={sourceId}
                        onValueChange={(v) => setRegisterState({ sourceType, parentId, sourceId: v })}
                        disabled={!parentId}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder={parentId ? "Select a harvest" : "Pick a season first"} />
                        </SelectTrigger>
                        <SelectContent>
                          {(harvests ?? []).map((harvest) => (
                            <SelectItem key={harvest.id} value={String(harvest.id)}>
                              {new Date(harvest.harvest_date).toLocaleDateString()} — {harvest.quantity} {harvest.unit}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  </>
                ) : (
                  <>
                    <div className="flex flex-col gap-1.5">
                      <Label>Animal</Label>
                      <Select
                        value={parentId}
                        onValueChange={(v) => setRegisterState({ sourceType, parentId: v, sourceId: "" })}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Select an animal" />
                        </SelectTrigger>
                        <SelectContent>
                          {(animals ?? []).map((animal) => (
                            <SelectItem key={animal.id} value={String(animal.id)}>
                              {animal.tag_number}
                              {animal.name ? ` (${animal.name})` : ""}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <Label>Production record</Label>
                      <Select
                        value={sourceId}
                        onValueChange={(v) => setRegisterState({ sourceType, parentId, sourceId: v })}
                        disabled={!parentId}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder={parentId ? "Select a record" : "Pick an animal first"} />
                        </SelectTrigger>
                        <SelectContent>
                          {(productionRecords ?? []).map((record) => (
                            <SelectItem key={record.id} value={String(record.id)}>
                              {new Date(record.date).toLocaleDateString()} — {record.product_type} ({record.quantity}{" "}
                              {record.unit})
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  </>
                )}
                {registerError && <p className="text-sm text-destructive">{registerError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createBatchMutation.isPending}>
                    {createBatchMutation.isPending ? "Registering…" : "Register batch"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
          )}
        </CardHeader>
        <CardContent className="pb-6">
          {batchesLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !batches || batches.length === 0 ? (
            <p className="text-sm text-muted-foreground">No trace batches registered yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Code</TableHead>
                  <TableHead>Product</TableHead>
                  <TableHead>Quantity</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Events</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {batches.map((batch) => (
                  <TableRow key={batch.id}>
                    <TableCell className="font-medium">
                      <Link href={`/dashboard/traceability/${batch.id}`} className="hover:underline">
                        {batch.code}
                      </Link>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{batch.product_name}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {batch.quantity} {batch.unit}
                    </TableCell>
                    <TableCell>
                      <Badge variant={STATUS_VARIANT[batch.status] ?? "secondary"}>{formatRole(batch.status)}</Badge>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{batch.events?.length ?? 0}</TableCell>
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
