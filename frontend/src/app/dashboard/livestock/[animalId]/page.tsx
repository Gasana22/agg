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
  getAnimal,
  updateAnimalStatus,
  updateAnimal,
  listHealthLogs,
  createHealthLog,
  listProductionRecords,
  createProductionRecord,
  listAnimalSales,
  createAnimalSale,
  ANIMAL_STATUSES,
  ANIMAL_SOURCES,
  ANIMAL_HEALTH_LOG_TYPES,
} from "@/lib/modules/livestock";
import { usePermissions } from "@/lib/permissions";
import { formatRole } from "@/lib/utils";

const animalEditSchema = z.object({
  name: z.string().optional(),
  breed: z.string().optional(),
  sex: z.string().optional(),
  birth_date: z.string().optional(),
  source: z.string().optional(),
  acquired_date: z.string().optional(),
  notes: z.string().optional(),
});
type AnimalEditFormValues = z.infer<typeof animalEditSchema>;

const healthLogSchema = z.object({
  type: z.string().min(1, "Pick a type"),
  date: z.string().min(1, "Date is required"),
  value: z.string().optional(),
  unit: z.string().optional(),
  product_name: z.string().optional(),
  cost: z.string().optional(),
  next_due_date: z.string().optional(),
  notes: z.string().optional(),
});
type HealthLogFormValues = z.infer<typeof healthLogSchema>;

const productionSchema = z.object({
  date: z.string().min(1, "Date is required"),
  product_type: z.string().min(1, "Product type is required"),
  quantity: z.string().min(1, "Quantity is required"),
  unit: z.string().min(1, "Unit is required"),
});
type ProductionFormValues = z.infer<typeof productionSchema>;

const saleSchema = z.object({
  buyer_name: z.string().min(1, "Buyer name is required"),
  sale_price: z.string().min(1, "Sale price is required"),
  sale_date: z.string().min(1, "Date is required"),
  notes: z.string().optional(),
});
type SaleFormValues = z.infer<typeof saleSchema>;

const ANIMAL_STATUS_VARIANT: Record<string, "default" | "secondary" | "destructive" | "success"> = {
  active: "success",
  sold: "secondary",
  deceased: "destructive",
};

export default function AnimalDetailPage() {
  const params = useParams<{ animalId: string }>();
  const animalId = Number(params.animalId);
  const { canManageLivestock } = usePermissions();
  const queryClient = useQueryClient();

  const [healthOpen, setHealthOpen] = React.useState(false);
  const [productionOpen, setProductionOpen] = React.useState(false);
  const [saleOpen, setSaleOpen] = React.useState(false);
  const [healthError, setHealthError] = React.useState<string | null>(null);
  const [productionError, setProductionError] = React.useState<string | null>(null);
  const [saleError, setSaleError] = React.useState<string | null>(null);
  const [editOpen, setEditOpen] = React.useState(false);
  const [editError, setEditError] = React.useState<string | null>(null);
  const [editSex, setEditSex] = React.useState("");
  const [editSource, setEditSource] = React.useState("");

  const { data: animal } = useQuery({
    queryKey: ["animal", animalId],
    queryFn: () => getAnimal(animalId),
  });

  const { data: healthLogs, isLoading: healthLoading } = useQuery({
    queryKey: ["animal-health-logs", animalId],
    queryFn: () => listHealthLogs(animalId),
  });

  const { data: productionRecords, isLoading: productionLoading } = useQuery({
    queryKey: ["animal-production-records", animalId],
    queryFn: () => listProductionRecords(animalId),
  });

  const { data: sales, isLoading: salesLoading } = useQuery({
    queryKey: ["animal-sales", animalId],
    queryFn: () => listAnimalSales(animalId),
  });

  const healthForm = useForm<HealthLogFormValues>({ resolver: zodResolver(healthLogSchema) });
  const productionForm = useForm<ProductionFormValues>({ resolver: zodResolver(productionSchema) });
  const saleForm = useForm<SaleFormValues>({ resolver: zodResolver(saleSchema) });
  const animalEditForm = useForm<AnimalEditFormValues>({ resolver: zodResolver(animalEditSchema) });

  const statusMutation = useMutation({
    mutationFn: (status: string) => updateAnimalStatus(animalId, { status: status as never }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["animal", animalId] });
      queryClient.invalidateQueries({ queryKey: ["animals"] });
    },
  });

  const createHealthLogMutation = useMutation({
    mutationFn: (values: HealthLogFormValues) =>
      createHealthLog(animalId, {
        type: values.type,
        date: values.date,
        value: values.value ? Number(values.value) : undefined,
        unit: values.unit || undefined,
        product_name: values.product_name || undefined,
        cost: values.cost ? Number(values.cost) : undefined,
        next_due_date: values.next_due_date || undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["animal-health-logs", animalId] });
      setHealthOpen(false);
      healthForm.reset();
    },
    onError: (err) =>
      setHealthError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add health log." : "Something went wrong."
      ),
  });

  const createProductionMutation = useMutation({
    mutationFn: (values: ProductionFormValues) =>
      createProductionRecord(animalId, {
        date: values.date,
        product_type: values.product_type,
        quantity: Number(values.quantity),
        unit: values.unit,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["animal-production-records", animalId] });
      setProductionOpen(false);
      productionForm.reset();
    },
    onError: (err) =>
      setProductionError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add record." : "Something went wrong."
      ),
  });

  const createSaleMutation = useMutation({
    mutationFn: (values: SaleFormValues) =>
      createAnimalSale(animalId, {
        buyer_name: values.buyer_name,
        sale_price: Number(values.sale_price),
        sale_date: values.sale_date,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["animal-sales", animalId] });
      setSaleOpen(false);
      saleForm.reset();
    },
    onError: (err) =>
      setSaleError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not record sale." : "Something went wrong."
      ),
  });

  const editAnimalMutation = useMutation({
    mutationFn: (values: AnimalEditFormValues) =>
      updateAnimal(animalId, {
        name: values.name || undefined,
        breed: values.breed || undefined,
        sex: (editSex as "male" | "female") || undefined,
        birth_date: values.birth_date || undefined,
        source: (editSource as "born_on_farm" | "purchased") || undefined,
        acquired_date: values.acquired_date || undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["animal", animalId] });
      queryClient.invalidateQueries({ queryKey: ["animals"] });
      setEditOpen(false);
    },
    onError: (err) =>
      setEditError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not update animal." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{animal?.tag_number ?? "Animal"}</h1>
          <p className="text-sm text-muted-foreground">
            {animal?.name ? `${animal.name} · ` : ""}
            {animal?.species}
            {animal?.breed ? ` (${animal.breed})` : ""}
          </p>
        </div>
        {animal && (
          <div className="flex items-center gap-2">
            <Badge variant={ANIMAL_STATUS_VARIANT[animal.status] ?? "secondary"}>{formatRole(animal.status)}</Badge>
            {canManageLivestock && (
              <Select value={animal.status} onValueChange={(v) => statusMutation.mutate(v)}>
                <SelectTrigger className="h-8 w-36">
                  <SelectValue placeholder="Change status" />
                </SelectTrigger>
                <SelectContent>
                  {ANIMAL_STATUSES.map((status) => (
                    <SelectItem key={status} value={status}>
                      {formatRole(status)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
            {canManageLivestock && (
              <Button
                size="sm"
                variant="outline"
                className="gap-2"
                onClick={() => {
                  animalEditForm.reset({
                    name: animal.name ?? "",
                    breed: animal.breed ?? "",
                    birth_date: animal.birth_date ?? "",
                    acquired_date: animal.acquired_date ?? "",
                    notes: animal.notes ?? "",
                  });
                  setEditSex(animal.sex ?? "");
                  setEditSource(animal.source ?? "");
                  setEditError(null);
                  setEditOpen(true);
                }}
              >
                <Pencil className="size-4" />
                Edit
              </Button>
            )}
          </div>
        )}
      </div>

      <Dialog open={editOpen} onOpenChange={setEditOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit animal</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={animalEditForm.handleSubmit((values) => {
              setEditError(null);
              editAnimalMutation.mutate(values);
            })}
            className="flex max-h-[70vh] flex-col gap-4 overflow-y-auto"
          >
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-animal-name">Name</Label>
                <Input id="edit-animal-name" {...animalEditForm.register("name")} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-animal-breed">Breed</Label>
                <Input id="edit-animal-breed" {...animalEditForm.register("breed")} />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label>Sex</Label>
                <Select value={editSex || "unset"} onValueChange={(v) => setEditSex(v === "unset" ? "" : v)}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select sex" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="unset">Unknown</SelectItem>
                    <SelectItem value="male">Male</SelectItem>
                    <SelectItem value="female">Female</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-animal-birth-date">Birth date</Label>
                <Input id="edit-animal-birth-date" type="date" {...animalEditForm.register("birth_date")} />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label>Source</Label>
                <Select
                  value={editSource || "unset"}
                  onValueChange={(v) => setEditSource(v === "unset" ? "" : v)}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select source" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="unset">Unset</SelectItem>
                    {ANIMAL_SOURCES.map((source) => (
                      <SelectItem key={source} value={source}>
                        {formatRole(source)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-animal-acquired-date">Acquired date</Label>
                <Input id="edit-animal-acquired-date" type="date" {...animalEditForm.register("acquired_date")} />
              </div>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="edit-animal-notes">Notes</Label>
              <Textarea id="edit-animal-notes" rows={2} {...animalEditForm.register("notes")} />
            </div>
            {editError && <p className="text-sm text-destructive">{editError}</p>}
            <DialogFooter>
              <Button type="submit" disabled={editAnimalMutation.isPending}>
                {editAnimalMutation.isPending ? "Saving…" : "Save changes"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Health logs</CardTitle>
          <Dialog open={healthOpen} onOpenChange={setHealthOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add health log
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Log a health event</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={healthForm.handleSubmit((values) => {
                  setHealthError(null);
                  createHealthLogMutation.mutate(values);
                })}
                className="flex max-h-[70vh] flex-col gap-4 overflow-y-auto"
              >
                <div className="flex flex-col gap-1.5">
                  <Label>Type</Label>
                  <Select onValueChange={(v) => healthForm.setValue("type", v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select a type" />
                    </SelectTrigger>
                    <SelectContent>
                      {ANIMAL_HEALTH_LOG_TYPES.map((type) => (
                        <SelectItem key={type} value={type}>
                          {formatRole(type)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {healthForm.formState.errors.type && (
                    <p className="text-xs text-destructive">{healthForm.formState.errors.type.message}</p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="health-date">Date</Label>
                    <Input id="health-date" type="date" {...healthForm.register("date")} />
                    {healthForm.formState.errors.date && (
                      <p className="text-xs text-destructive">{healthForm.formState.errors.date.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="health-next-due">Next due date</Label>
                    <Input id="health-next-due" type="date" {...healthForm.register("next_due_date")} />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="health-value">Value</Label>
                    <Input id="health-value" type="number" step="any" {...healthForm.register("value")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="health-unit">Unit</Label>
                    <Input id="health-unit" placeholder="kg, ml, ..." {...healthForm.register("unit")} />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="health-product">Product name</Label>
                    <Input id="health-product" {...healthForm.register("product_name")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="health-cost">Cost</Label>
                    <Input id="health-cost" type="number" step="any" {...healthForm.register("cost")} />
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="health-notes">Notes</Label>
                  <Textarea id="health-notes" rows={2} {...healthForm.register("notes")} />
                </div>
                {healthError && <p className="text-sm text-destructive">{healthError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createHealthLogMutation.isPending}>
                    {createHealthLogMutation.isPending ? "Adding…" : "Add health log"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {healthLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !healthLogs || healthLogs.length === 0 ? (
            <p className="text-sm text-muted-foreground">No health logs yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Type</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Value</TableHead>
                  <TableHead>Product</TableHead>
                  <TableHead>Cost</TableHead>
                  <TableHead>Recorded by</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {healthLogs.map((log) => (
                  <TableRow key={log.id}>
                    <TableCell className="font-medium">{formatRole(log.type)}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {new Date(log.date).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {log.value ? `${log.value} ${log.unit ?? ""}` : "—"}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{log.product_name ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{log.cost ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{log.recorder.name}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Production records</CardTitle>
          <Dialog open={productionOpen} onOpenChange={setProductionOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add record
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Record production</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={productionForm.handleSubmit((values) => {
                  setProductionError(null);
                  createProductionMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="production-date">Date</Label>
                  <Input id="production-date" type="date" {...productionForm.register("date")} />
                  {productionForm.formState.errors.date && (
                    <p className="text-xs text-destructive">{productionForm.formState.errors.date.message}</p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="product_type">Product type</Label>
                    <Input id="product_type" placeholder="Milk, Eggs, ..." {...productionForm.register("product_type")} />
                    {productionForm.formState.errors.product_type && (
                      <p className="text-xs text-destructive">
                        {productionForm.formState.errors.product_type.message}
                      </p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="production-unit">Unit</Label>
                    <Input id="production-unit" placeholder="litres, dozen, ..." {...productionForm.register("unit")} />
                    {productionForm.formState.errors.unit && (
                      <p className="text-xs text-destructive">{productionForm.formState.errors.unit.message}</p>
                    )}
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="production-quantity">Quantity</Label>
                  <Input id="production-quantity" type="number" step="any" {...productionForm.register("quantity")} />
                  {productionForm.formState.errors.quantity && (
                    <p className="text-xs text-destructive">{productionForm.formState.errors.quantity.message}</p>
                  )}
                </div>
                {productionError && <p className="text-sm text-destructive">{productionError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createProductionMutation.isPending}>
                    {createProductionMutation.isPending ? "Adding…" : "Add record"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {productionLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !productionRecords || productionRecords.length === 0 ? (
            <p className="text-sm text-muted-foreground">No production records yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Date</TableHead>
                  <TableHead>Product</TableHead>
                  <TableHead>Quantity</TableHead>
                  <TableHead>Recorded by</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {productionRecords.map((record) => (
                  <TableRow key={record.id}>
                    <TableCell className="font-medium">
                      {new Date(record.date).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{record.product_type}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {record.quantity} {record.unit}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{record.recorder.name}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Sales</CardTitle>
          {canManageLivestock && (
          <Dialog open={saleOpen} onOpenChange={setSaleOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Record sale
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Record a sale</DialogTitle>
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
                    <Label htmlFor="sale-price">Sale price</Label>
                    <Input id="sale-price" type="number" step="any" {...saleForm.register("sale_price")} />
                    {saleForm.formState.errors.sale_price && (
                      <p className="text-xs text-destructive">{saleForm.formState.errors.sale_price.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="sale-date">Sale date</Label>
                    <Input id="sale-date" type="date" {...saleForm.register("sale_date")} />
                    {saleForm.formState.errors.sale_date && (
                      <p className="text-xs text-destructive">{saleForm.formState.errors.sale_date.message}</p>
                    )}
                  </div>
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
          )}
        </CardHeader>
        <CardContent className="pb-6">
          {salesLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !sales || sales.length === 0 ? (
            <p className="text-sm text-muted-foreground">No sales recorded yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Buyer</TableHead>
                  <TableHead>Price</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Recorded by</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {sales.map((sale) => (
                  <TableRow key={sale.id}>
                    <TableCell className="font-medium">{sale.buyer_name}</TableCell>
                    <TableCell className="text-muted-foreground">{sale.sale_price}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {new Date(sale.sale_date).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{sale.recorder.name}</TableCell>
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
