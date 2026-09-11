"use client";

import * as React from "react";
import Link from "next/link";
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
import {
  listPoultryFlocks,
  createPoultryFlock,
  updatePoultryFlock,
  POULTRY_SOURCES,
  type PoultryFlock,
} from "@/lib/modules/poultry";
import { useFarm } from "@/lib/farm-context";
import { formatRole } from "@/lib/utils";

const flockSchema = z.object({
  flock_code: z.string().min(1, "Flock code is required"),
  name: z.string().optional(),
  bird_type: z.string().min(1, "Bird type is required"),
  breed: z.string().optional(),
  initial_count: z.string().min(1, "Initial count is required"),
  source: z.string().optional(),
  acquired_date: z.string().optional(),
  notes: z.string().optional(),
});
type FlockFormValues = z.infer<typeof flockSchema>;

const STATUS_VARIANT: Record<string, "default" | "secondary" | "success"> = {
  active: "success",
  sold: "secondary",
  closed: "secondary",
};

export default function PoultryPage() {
  const { currentFarmId } = useFarm();
  const queryClient = useQueryClient();
  const [flockOpen, setFlockOpen] = React.useState(false);
  const [flockError, setFlockError] = React.useState<string | null>(null);
  const [editingFlock, setEditingFlock] = React.useState<PoultryFlock | null>(null);
  const [flockEditError, setFlockEditError] = React.useState<string | null>(null);
  const [source, setSource] = React.useState("");
  const [editSource, setEditSource] = React.useState("");

  const { data: flocks, isLoading } = useQuery({
    queryKey: ["poultry-flocks", currentFarmId],
    queryFn: () => listPoultryFlocks(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const flockForm = useForm<FlockFormValues>({ resolver: zodResolver(flockSchema) });
  const flockEditForm = useForm<FlockFormValues>({ resolver: zodResolver(flockSchema) });

  const createFlockMutation = useMutation({
    mutationFn: (values: FlockFormValues) =>
      createPoultryFlock(currentFarmId!, {
        flock_code: values.flock_code,
        name: values.name || undefined,
        bird_type: values.bird_type,
        breed: values.breed || undefined,
        initial_count: Number(values.initial_count),
        source: (source || undefined) as "born_on_farm" | "purchased" | undefined,
        acquired_date: values.acquired_date || undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["poultry-flocks", currentFarmId] });
      setFlockOpen(false);
      setSource("");
      flockForm.reset();
    },
    onError: (err) =>
      setFlockError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add flock." : "Something went wrong."
      ),
  });

  const editFlockMutation = useMutation({
    mutationFn: (values: FlockFormValues) =>
      updatePoultryFlock(editingFlock!.id, {
        flock_code: values.flock_code,
        name: values.name || undefined,
        bird_type: values.bird_type,
        breed: values.breed || undefined,
        initial_count: values.initial_count ? Number(values.initial_count) : undefined,
        source: (editSource || undefined) as "born_on_farm" | "purchased" | undefined,
        acquired_date: values.acquired_date || undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["poultry-flocks", currentFarmId] });
      setEditingFlock(null);
    },
    onError: (err) =>
      setFlockEditError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not update flock." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link href="/dashboard/livestock" className="text-sm text-muted-foreground hover:underline">
          ← Back to livestock
        </Link>
      </div>
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Poultry</h1>
          <p className="text-sm text-muted-foreground">
            Flocks tracked by headcount, not individually tagged like other animals.
          </p>
        </div>
        <Dialog open={flockOpen} onOpenChange={setFlockOpen}>
          <DialogTrigger asChild>
            <Button className="gap-2">
              <Plus className="size-4" />
              Add flock
            </Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Register a flock</DialogTitle>
            </DialogHeader>
            <form
              onSubmit={flockForm.handleSubmit((values) => {
                setFlockError(null);
                createFlockMutation.mutate(values);
              })}
              className="flex flex-col gap-4"
            >
              <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="flock_code">Flock code</Label>
                  <Input id="flock_code" placeholder="LAYER-A" {...flockForm.register("flock_code")} />
                  {flockForm.formState.errors.flock_code && (
                    <p className="text-xs text-destructive">{flockForm.formState.errors.flock_code.message}</p>
                  )}
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="flock-name">Name</Label>
                  <Input id="flock-name" {...flockForm.register("name")} />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="bird_type">Bird type</Label>
                  <Input id="bird_type" placeholder="Broiler, Layer, Kienyeji..." {...flockForm.register("bird_type")} />
                  {flockForm.formState.errors.bird_type && (
                    <p className="text-xs text-destructive">{flockForm.formState.errors.bird_type.message}</p>
                  )}
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="flock-breed">Breed</Label>
                  <Input id="flock-breed" {...flockForm.register("breed")} />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="initial_count">Initial count</Label>
                  <Input id="initial_count" type="number" min={1} {...flockForm.register("initial_count")} />
                  {flockForm.formState.errors.initial_count && (
                    <p className="text-xs text-destructive">{flockForm.formState.errors.initial_count.message}</p>
                  )}
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="acquired_date">Acquired date</Label>
                  <Input id="acquired_date" type="date" {...flockForm.register("acquired_date")} />
                </div>
              </div>
              <div className="flex flex-col gap-1.5">
                <Label>Source</Label>
                <Select onValueChange={setSource}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select source" />
                  </SelectTrigger>
                  <SelectContent>
                    {POULTRY_SOURCES.map((s) => (
                      <SelectItem key={s} value={s}>
                        {formatRole(s)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="flock-notes">Notes</Label>
                <Textarea id="flock-notes" rows={2} {...flockForm.register("notes")} />
              </div>
              {flockError && <p className="text-sm text-destructive">{flockError}</p>}
              <DialogFooter>
                <Button type="submit" disabled={createFlockMutation.isPending}>
                  {createFlockMutation.isPending ? "Adding…" : "Add flock"}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>

      <Card>
        <CardContent className="pb-6 pt-6">
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !flocks || flocks.length === 0 ? (
            <p className="text-sm text-muted-foreground">No poultry flocks registered yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Code</TableHead>
                  <TableHead>Bird type</TableHead>
                  <TableHead>Birds on hand</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {flocks.map((flock) => (
                  <TableRow key={flock.id}>
                    <TableCell className="font-medium">
                      <Link href={`/dashboard/livestock/poultry/${flock.id}`} className="hover:underline">
                        {flock.flock_code}
                      </Link>
                      {flock.name && <span className="ml-1 text-muted-foreground">({flock.name})</span>}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {flock.bird_type}
                      {flock.breed ? ` · ${flock.breed}` : ""}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {flock.current_count} / {flock.initial_count}
                    </TableCell>
                    <TableCell>
                      <Badge variant={STATUS_VARIANT[flock.status] ?? "secondary"}>{formatRole(flock.status)}</Badge>
                    </TableCell>
                    <TableCell>
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => {
                          setEditingFlock(flock);
                          flockEditForm.reset({
                            flock_code: flock.flock_code,
                            name: flock.name ?? "",
                            bird_type: flock.bird_type,
                            breed: flock.breed ?? "",
                            initial_count: String(flock.initial_count),
                            acquired_date: flock.acquired_date ?? "",
                            notes: flock.notes ?? "",
                          });
                          setEditSource(flock.source ?? "");
                          setFlockEditError(null);
                        }}
                      >
                        <Pencil className="size-4" />
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
        open={!!editingFlock}
        onOpenChange={(open) => {
          if (!open) {
            setEditingFlock(null);
            setFlockEditError(null);
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit flock</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={flockEditForm.handleSubmit((values) => {
              setFlockEditError(null);
              editFlockMutation.mutate(values);
            })}
            className="flex flex-col gap-4"
          >
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-flock_code">Flock code</Label>
                <Input id="edit-flock_code" {...flockEditForm.register("flock_code")} />
                {flockEditForm.formState.errors.flock_code && (
                  <p className="text-xs text-destructive">{flockEditForm.formState.errors.flock_code.message}</p>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-flock-name">Name</Label>
                <Input id="edit-flock-name" {...flockEditForm.register("name")} />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-bird_type">Bird type</Label>
                <Input id="edit-bird_type" {...flockEditForm.register("bird_type")} />
                {flockEditForm.formState.errors.bird_type && (
                  <p className="text-xs text-destructive">{flockEditForm.formState.errors.bird_type.message}</p>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-flock-breed">Breed</Label>
                <Input id="edit-flock-breed" {...flockEditForm.register("breed")} />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-initial_count">Initial count</Label>
                <Input id="edit-initial_count" type="number" min={1} {...flockEditForm.register("initial_count")} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-acquired_date">Acquired date</Label>
                <Input id="edit-acquired_date" type="date" {...flockEditForm.register("acquired_date")} />
              </div>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label>Source</Label>
              <Select value={editSource} onValueChange={setEditSource}>
                <SelectTrigger>
                  <SelectValue placeholder="Select source" />
                </SelectTrigger>
                <SelectContent>
                  {POULTRY_SOURCES.map((s) => (
                    <SelectItem key={s} value={s}>
                      {formatRole(s)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="edit-flock-notes">Notes</Label>
              <Textarea id="edit-flock-notes" rows={2} {...flockEditForm.register("notes")} />
            </div>
            {flockEditError && <p className="text-sm text-destructive">{flockEditError}</p>}
            <DialogFooter>
              <Button type="submit" disabled={editFlockMutation.isPending}>
                {editFlockMutation.isPending ? "Saving…" : "Save changes"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
