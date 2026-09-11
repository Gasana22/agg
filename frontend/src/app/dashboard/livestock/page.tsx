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
import {
  listAnimals,
  createAnimal,
  listBreedingRecords,
  createBreedingRecord,
  updateBreedingRecordStatus,
  ANIMAL_SOURCES,
  BREEDING_STATUSES,
} from "@/lib/modules/livestock";
import { useFarm } from "@/lib/farm-context";
import { formatRole } from "@/lib/utils";

const animalSchema = z.object({
  tag_number: z.string().min(1, "Tag number is required"),
  name: z.string().optional(),
  species: z.string().min(1, "Species is required"),
  breed: z.string().optional(),
  sex: z.string().optional(),
  birth_date: z.string().optional(),
  source: z.string().optional(),
  acquired_date: z.string().optional(),
  dam_id: z.string().optional(),
  sire_id: z.string().optional(),
  notes: z.string().optional(),
});
type AnimalFormValues = z.infer<typeof animalSchema>;

const breedingSchema = z.object({
  dam_id: z.string().min(1, "Pick a dam"),
  sire_id: z.string().optional(),
  breeding_date: z.string().min(1, "Breeding date is required"),
  expected_due_date: z.string().optional(),
  notes: z.string().optional(),
});
type BreedingFormValues = z.infer<typeof breedingSchema>;

const ANIMAL_STATUS_VARIANT: Record<string, "default" | "secondary" | "destructive" | "success"> = {
  active: "success",
  sold: "secondary",
  deceased: "destructive",
};

export default function LivestockPage() {
  const { currentFarmId } = useFarm();
  const queryClient = useQueryClient();
  const [animalOpen, setAnimalOpen] = React.useState(false);
  const [breedingOpen, setBreedingOpen] = React.useState(false);
  const [animalError, setAnimalError] = React.useState<string | null>(null);
  const [breedingError, setBreedingError] = React.useState<string | null>(null);

  const { data: animals, isLoading: animalsLoading } = useQuery({
    queryKey: ["animals", currentFarmId],
    queryFn: () => listAnimals(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const { data: breedingRecords, isLoading: breedingLoading } = useQuery({
    queryKey: ["breeding-records", currentFarmId],
    queryFn: () => listBreedingRecords(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const animalForm = useForm<AnimalFormValues>({ resolver: zodResolver(animalSchema) });
  const breedingForm = useForm<BreedingFormValues>({ resolver: zodResolver(breedingSchema) });

  const createAnimalMutation = useMutation({
    mutationFn: (values: AnimalFormValues) =>
      createAnimal(currentFarmId!, {
        tag_number: values.tag_number,
        name: values.name || undefined,
        species: values.species,
        breed: values.breed || undefined,
        sex: (values.sex as "male" | "female") || undefined,
        birth_date: values.birth_date || undefined,
        source: (values.source as "born_on_farm" | "purchased") || undefined,
        acquired_date: values.acquired_date || undefined,
        dam_id: values.dam_id ? Number(values.dam_id) : undefined,
        sire_id: values.sire_id ? Number(values.sire_id) : undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["animals", currentFarmId] });
      setAnimalOpen(false);
      animalForm.reset();
    },
    onError: (err) =>
      setAnimalError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add animal." : "Something went wrong."
      ),
  });

  const createBreedingMutation = useMutation({
    mutationFn: (values: BreedingFormValues) =>
      createBreedingRecord(currentFarmId!, {
        dam_id: Number(values.dam_id),
        sire_id: values.sire_id ? Number(values.sire_id) : undefined,
        breeding_date: values.breeding_date,
        expected_due_date: values.expected_due_date || undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["breeding-records", currentFarmId] });
      setBreedingOpen(false);
      breedingForm.reset();
    },
    onError: (err) =>
      setBreedingError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add breeding record." : "Something went wrong."
      ),
  });

  const updateBreedingStatusMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) =>
      updateBreedingRecordStatus(id, { status: status as never }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["breeding-records", currentFarmId] }),
  });

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Livestock</h1>
        <p className="text-sm text-muted-foreground">Animal registry and breeding records.</p>
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Animal registry</CardTitle>
          <Dialog open={animalOpen} onOpenChange={setAnimalOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add animal
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Register an animal</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={animalForm.handleSubmit((values) => {
                  setAnimalError(null);
                  createAnimalMutation.mutate(values);
                })}
                className="flex max-h-[70vh] flex-col gap-4 overflow-y-auto"
              >
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="tag_number">Tag number</Label>
                    <Input id="tag_number" {...animalForm.register("tag_number")} />
                    {animalForm.formState.errors.tag_number && (
                      <p className="text-xs text-destructive">
                        {animalForm.formState.errors.tag_number.message}
                      </p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="animal-name">Name</Label>
                    <Input id="animal-name" {...animalForm.register("name")} />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="species">Species</Label>
                    <Input id="species" placeholder="Cattle, Goat, ..." {...animalForm.register("species")} />
                    {animalForm.formState.errors.species && (
                      <p className="text-xs text-destructive">{animalForm.formState.errors.species.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="breed">Breed</Label>
                    <Input id="breed" {...animalForm.register("breed")} />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label>Sex</Label>
                    <Select onValueChange={(v) => animalForm.setValue("sex", v)}>
                      <SelectTrigger>
                        <SelectValue placeholder="Select sex" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="male">Male</SelectItem>
                        <SelectItem value="female">Female</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="birth_date">Birth date</Label>
                    <Input id="birth_date" type="date" {...animalForm.register("birth_date")} />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label>Source</Label>
                    <Select onValueChange={(v) => animalForm.setValue("source", v)}>
                      <SelectTrigger>
                        <SelectValue placeholder="Select source" />
                      </SelectTrigger>
                      <SelectContent>
                        {ANIMAL_SOURCES.map((source) => (
                          <SelectItem key={source} value={source}>
                            {formatRole(source)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="acquired_date">Acquired date</Label>
                    <Input id="acquired_date" type="date" {...animalForm.register("acquired_date")} />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label>Dam (mother)</Label>
                    <Select onValueChange={(v) => animalForm.setValue("dam_id", v)}>
                      <SelectTrigger>
                        <SelectValue placeholder="Optional" />
                      </SelectTrigger>
                      <SelectContent>
                        {(animals ?? [])
                          .filter((a) => a.sex !== "male")
                          .map((a) => (
                            <SelectItem key={a.id} value={String(a.id)}>
                              {a.tag_number}
                            </SelectItem>
                          ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label>Sire (father)</Label>
                    <Select onValueChange={(v) => animalForm.setValue("sire_id", v)}>
                      <SelectTrigger>
                        <SelectValue placeholder="Optional" />
                      </SelectTrigger>
                      <SelectContent>
                        {(animals ?? [])
                          .filter((a) => a.sex !== "female")
                          .map((a) => (
                            <SelectItem key={a.id} value={String(a.id)}>
                              {a.tag_number}
                            </SelectItem>
                          ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="animal-notes">Notes</Label>
                  <Textarea id="animal-notes" rows={2} {...animalForm.register("notes")} />
                </div>
                {animalError && <p className="text-sm text-destructive">{animalError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createAnimalMutation.isPending}>
                    {createAnimalMutation.isPending ? "Adding…" : "Add animal"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {animalsLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !animals || animals.length === 0 ? (
            <p className="text-sm text-muted-foreground">No animals registered yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Tag</TableHead>
                  <TableHead>Name</TableHead>
                  <TableHead>Species</TableHead>
                  <TableHead>Sex</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Birth date</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {animals.map((animal) => (
                  <TableRow key={animal.id}>
                    <TableCell className="font-medium">
                      <Link href={`/dashboard/livestock/${animal.id}`} className="hover:underline">
                        {animal.tag_number}
                      </Link>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{animal.name ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {animal.species}
                      {animal.breed ? ` (${animal.breed})` : ""}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {animal.sex ? formatRole(animal.sex) : "—"}
                    </TableCell>
                    <TableCell>
                      <Badge variant={ANIMAL_STATUS_VARIANT[animal.status] ?? "secondary"}>
                        {formatRole(animal.status)}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {animal.birth_date ? new Date(animal.birth_date).toLocaleDateString() : "—"}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Breeding records</CardTitle>
          <Dialog open={breedingOpen} onOpenChange={setBreedingOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2" disabled={!animals || animals.length === 0}>
                <Plus className="size-4" />
                New breeding record
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Record breeding</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={breedingForm.handleSubmit((values) => {
                  setBreedingError(null);
                  createBreedingMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label>Dam (mother)</Label>
                    <Select onValueChange={(v) => breedingForm.setValue("dam_id", v)}>
                      <SelectTrigger>
                        <SelectValue placeholder="Select dam" />
                      </SelectTrigger>
                      <SelectContent>
                        {(animals ?? [])
                          .filter((a) => a.sex !== "male")
                          .map((a) => (
                            <SelectItem key={a.id} value={String(a.id)}>
                              {a.tag_number}
                            </SelectItem>
                          ))}
                      </SelectContent>
                    </Select>
                    {breedingForm.formState.errors.dam_id && (
                      <p className="text-xs text-destructive">{breedingForm.formState.errors.dam_id.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label>Sire (father)</Label>
                    <Select onValueChange={(v) => breedingForm.setValue("sire_id", v)}>
                      <SelectTrigger>
                        <SelectValue placeholder="Optional" />
                      </SelectTrigger>
                      <SelectContent>
                        {(animals ?? [])
                          .filter((a) => a.sex !== "female")
                          .map((a) => (
                            <SelectItem key={a.id} value={String(a.id)}>
                              {a.tag_number}
                            </SelectItem>
                          ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="breeding_date">Breeding date</Label>
                    <Input id="breeding_date" type="date" {...breedingForm.register("breeding_date")} />
                    {breedingForm.formState.errors.breeding_date && (
                      <p className="text-xs text-destructive">
                        {breedingForm.formState.errors.breeding_date.message}
                      </p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="expected_due_date">Expected due date</Label>
                    <Input id="expected_due_date" type="date" {...breedingForm.register("expected_due_date")} />
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="breeding-notes">Notes</Label>
                  <Textarea id="breeding-notes" rows={2} {...breedingForm.register("notes")} />
                </div>
                {breedingError && <p className="text-sm text-destructive">{breedingError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createBreedingMutation.isPending}>
                    {createBreedingMutation.isPending ? "Recording…" : "Record breeding"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {breedingLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !breedingRecords || breedingRecords.length === 0 ? (
            <p className="text-sm text-muted-foreground">No breeding records yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Dam</TableHead>
                  <TableHead>Sire</TableHead>
                  <TableHead>Breeding date</TableHead>
                  <TableHead>Expected due</TableHead>
                  <TableHead>Offspring</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {breedingRecords.map((record) => (
                  <TableRow key={record.id}>
                    <TableCell className="font-medium">{record.dam.tag_number}</TableCell>
                    <TableCell className="text-muted-foreground">{record.sire?.tag_number ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {new Date(record.breeding_date).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {record.expected_due_date ? new Date(record.expected_due_date).toLocaleDateString() : "—"}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{record.offspring_count ?? "—"}</TableCell>
                    <TableCell>
                      <Select
                        defaultValue={record.status}
                        onValueChange={(status) => updateBreedingStatusMutation.mutate({ id: record.id, status })}
                      >
                        <SelectTrigger className="h-8 w-36">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {BREEDING_STATUSES.map((status) => (
                            <SelectItem key={status} value={status}>
                              {formatRole(status)}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
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
