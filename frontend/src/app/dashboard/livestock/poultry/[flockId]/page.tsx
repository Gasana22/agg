"use client";

import * as React from "react";
import Link from "next/link";
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
  getPoultryFlock,
  updatePoultryFlock,
  listPoultryMortalityLogs,
  createPoultryMortalityLog,
  listPoultryProductionRecords,
  createPoultryProductionRecord,
  listPoultrySales,
  createPoultrySale,
  POULTRY_FLOCK_STATUSES,
  POULTRY_SOURCES,
} from "@/lib/modules/poultry";
import { formatRole } from "@/lib/utils";

const mortalitySchema = z.object({
  date: z.string().min(1, "Date is required"),
  quantity: z.string().min(1, "Quantity is required"),
  cause: z.string().optional(),
  notes: z.string().optional(),
});
type MortalityFormValues = z.infer<typeof mortalitySchema>;

const productionSchema = z.object({
  date: z.string().min(1, "Date is required"),
  product_type: z.string().min(1, "Product type is required"),
  quantity: z.string().min(1, "Quantity is required"),
  unit: z.string().min(1, "Unit is required"),
});
type ProductionFormValues = z.infer<typeof productionSchema>;

const saleSchema = z.object({
  quantity: z.string().min(1, "Quantity is required"),
  buyer_name: z.string().min(1, "Buyer name is required"),
  sale_price: z.string().min(1, "Sale price is required"),
  sale_date: z.string().min(1, "Sale date is required"),
  notes: z.string().optional(),
});
type SaleFormValues = z.infer<typeof saleSchema>;

const flockEditSchema = z.object({
  flock_code: z.string().min(1, "Flock code is required"),
  name: z.string().optional(),
  bird_type: z.string().min(1, "Bird type is required"),
  breed: z.string().optional(),
  initial_count: z.string().optional(),
  acquired_date: z.string().optional(),
  notes: z.string().optional(),
});
type FlockEditFormValues = z.infer<typeof flockEditSchema>;

const STATUS_VARIANT: Record<string, "default" | "secondary" | "success"> = {
  active: "success",
  sold: "secondary",
  closed: "secondary",
};

export default function PoultryFlockDetailPage() {
  const params = useParams<{ flockId: string }>();
  const flockId = Number(params.flockId);
  const queryClient = useQueryClient();

  const [mortalityOpen, setMortalityOpen] = React.useState(false);
  const [mortalityError, setMortalityError] = React.useState<string | null>(null);
  const [productionOpen, setProductionOpen] = React.useState(false);
  const [productionError, setProductionError] = React.useState<string | null>(null);
  const [saleOpen, setSaleOpen] = React.useState(false);
  const [saleError, setSaleError] = React.useState<string | null>(null);
  const [flockEditOpen, setFlockEditOpen] = React.useState(false);
  const [flockEditError, setFlockEditError] = React.useState<string | null>(null);
  const [editSource, setEditSource] = React.useState("");

  const { data: flock } = useQuery({
    queryKey: ["poultry-flock", flockId],
    queryFn: () => getPoultryFlock(flockId),
  });

  const { data: mortalityLogs, isLoading: mortalityLoading } = useQuery({
    queryKey: ["poultry-mortality-logs", flockId],
    queryFn: () => listPoultryMortalityLogs(flockId),
  });

  const { data: productionRecords, isLoading: productionLoading } = useQuery({
    queryKey: ["poultry-production-records", flockId],
    queryFn: () => listPoultryProductionRecords(flockId),
  });

  const { data: sales, isLoading: salesLoading } = useQuery({
    queryKey: ["poultry-sales", flockId],
    queryFn: () => listPoultrySales(flockId),
  });

  const mortalityForm = useForm<MortalityFormValues>({ resolver: zodResolver(mortalitySchema) });
  const productionForm = useForm<ProductionFormValues>({ resolver: zodResolver(productionSchema) });
  const saleForm = useForm<SaleFormValues>({ resolver: zodResolver(saleSchema) });
  const flockEditForm = useForm<FlockEditFormValues>({ resolver: zodResolver(flockEditSchema) });

  const statusMutation = useMutation({
    mutationFn: (status: string) => updatePoultryFlock(flockId, { status: status as never }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["poultry-flock", flockId] });
      queryClient.invalidateQueries({ queryKey: ["poultry-flocks"] });
    },
  });

  const editFlockMutation = useMutation({
    mutationFn: (values: FlockEditFormValues) =>
      updatePoultryFlock(flockId, {
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
      queryClient.invalidateQueries({ queryKey: ["poultry-flock", flockId] });
      queryClient.invalidateQueries({ queryKey: ["poultry-flocks"] });
      setFlockEditOpen(false);
    },
    onError: (err) =>
      setFlockEditError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not update flock." : "Something went wrong."
      ),
  });

  const createMortalityMutation = useMutation({
    mutationFn: (values: MortalityFormValues) =>
      createPoultryMortalityLog(flockId, {
        date: values.date,
        quantity: Number(values.quantity),
        cause: values.cause || undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["poultry-mortality-logs", flockId] });
      queryClient.invalidateQueries({ queryKey: ["poultry-flock", flockId] });
      setMortalityOpen(false);
      mortalityForm.reset();
    },
    onError: (err) =>
      setMortalityError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add mortality log." : "Something went wrong."
      ),
  });

  const createProductionMutation = useMutation({
    mutationFn: (values: ProductionFormValues) =>
      createPoultryProductionRecord(flockId, {
        date: values.date,
        product_type: values.product_type,
        quantity: Number(values.quantity),
        unit: values.unit,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["poultry-production-records", flockId] });
      setProductionOpen(false);
      productionForm.reset();
    },
    onError: (err) =>
      setProductionError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add production record." : "Something went wrong."
      ),
  });

  const createSaleMutation = useMutation({
    mutationFn: (values: SaleFormValues) =>
      createPoultrySale(flockId, {
        quantity: Number(values.quantity),
        buyer_name: values.buyer_name,
        sale_price: Number(values.sale_price),
        sale_date: values.sale_date,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["poultry-sales", flockId] });
      queryClient.invalidateQueries({ queryKey: ["poultry-flock", flockId] });
      setSaleOpen(false);
      saleForm.reset();
    },
    onError: (err) =>
      setSaleError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not record sale." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link href="/dashboard/livestock/poultry" className="text-sm text-muted-foreground hover:underline">
          ← Back to poultry
        </Link>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">
            {flock?.flock_code}
            {flock?.name ? ` — ${flock.name}` : ""}
          </h1>
          <p className="text-sm text-muted-foreground">
            {flock?.bird_type}
            {flock?.breed ? ` · ${flock.breed}` : ""}
            {flock ? ` · ${flock.current_count} of ${flock.initial_count} birds on hand` : ""}
          </p>
        </div>
        {flock && (
          <div className="flex items-center gap-2">
            <Badge variant={STATUS_VARIANT[flock.status] ?? "secondary"}>{formatRole(flock.status)}</Badge>
            <Select value={flock.status} onValueChange={(v) => statusMutation.mutate(v)}>
              <SelectTrigger className="h-8 w-36">
                <SelectValue placeholder="Change status" />
              </SelectTrigger>
              <SelectContent>
                {POULTRY_FLOCK_STATUSES.map((status) => (
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
                setFlockEditError(null);
                setEditSource(flock.source ?? "");
                flockEditForm.reset({
                  flock_code: flock.flock_code,
                  name: flock.name ?? "",
                  bird_type: flock.bird_type,
                  breed: flock.breed ?? "",
                  initial_count: String(flock.initial_count),
                  acquired_date: flock.acquired_date?.slice(0, 10) ?? "",
                  notes: flock.notes ?? "",
                });
                setFlockEditOpen(true);
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
          <CardTitle>Mortality</CardTitle>
          <Dialog open={mortalityOpen} onOpenChange={setMortalityOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Log deaths
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Log mortality</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={mortalityForm.handleSubmit((values) => {
                  setMortalityError(null);
                  createMortalityMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="mortality-date">Date</Label>
                    <Input id="mortality-date" type="date" {...mortalityForm.register("date")} />
                    {mortalityForm.formState.errors.date && (
                      <p className="text-xs text-destructive">{mortalityForm.formState.errors.date.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="mortality-quantity">Quantity</Label>
                    <Input id="mortality-quantity" type="number" min={1} {...mortalityForm.register("quantity")} />
                    {mortalityForm.formState.errors.quantity && (
                      <p className="text-xs text-destructive">{mortalityForm.formState.errors.quantity.message}</p>
                    )}
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="mortality-cause">Cause</Label>
                  <Input id="mortality-cause" placeholder="Disease, heat, predator..." {...mortalityForm.register("cause")} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="mortality-notes">Notes</Label>
                  <Textarea id="mortality-notes" rows={2} {...mortalityForm.register("notes")} />
                </div>
                {mortalityError && <p className="text-sm text-destructive">{mortalityError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createMortalityMutation.isPending}>
                    {createMortalityMutation.isPending ? "Saving…" : "Log deaths"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {mortalityLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !mortalityLogs || mortalityLogs.length === 0 ? (
            <p className="text-sm text-muted-foreground">No mortality recorded yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Date</TableHead>
                  <TableHead>Quantity</TableHead>
                  <TableHead>Cause</TableHead>
                  <TableHead>Recorded by</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {mortalityLogs.map((log) => (
                  <TableRow key={log.id}>
                    <TableCell className="font-medium">{new Date(log.date).toLocaleDateString()}</TableCell>
                    <TableCell className="text-muted-foreground">{log.quantity}</TableCell>
                    <TableCell className="text-muted-foreground">{log.cause ?? "—"}</TableCell>
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
          <CardTitle>Production</CardTitle>
          <Dialog open={productionOpen} onOpenChange={setProductionOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add record
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Log production</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={productionForm.handleSubmit((values) => {
                  setProductionError(null);
                  createProductionMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="production-date">Date</Label>
                    <Input id="production-date" type="date" {...productionForm.register("date")} />
                    {productionForm.formState.errors.date && (
                      <p className="text-xs text-destructive">{productionForm.formState.errors.date.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="product_type">Product type</Label>
                    <Input id="product_type" placeholder="Eggs" {...productionForm.register("product_type")} />
                    {productionForm.formState.errors.product_type && (
                      <p className="text-xs text-destructive">{productionForm.formState.errors.product_type.message}</p>
                    )}
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="production-quantity">Quantity</Label>
                    <Input id="production-quantity" type="number" step="any" {...productionForm.register("quantity")} />
                    {productionForm.formState.errors.quantity && (
                      <p className="text-xs text-destructive">{productionForm.formState.errors.quantity.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="production-unit">Unit</Label>
                    <Input id="production-unit" placeholder="trays, dozen, kg..." {...productionForm.register("unit")} />
                    {productionForm.formState.errors.unit && (
                      <p className="text-xs text-destructive">{productionForm.formState.errors.unit.message}</p>
                    )}
                  </div>
                </div>
                {productionError && <p className="text-sm text-destructive">{productionError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createProductionMutation.isPending}>
                    {createProductionMutation.isPending ? "Saving…" : "Add record"}
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
                    <TableCell className="font-medium">{new Date(record.date).toLocaleDateString()}</TableCell>
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
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="sale-quantity">Quantity</Label>
                    <Input id="sale-quantity" type="number" min={1} {...saleForm.register("quantity")} />
                    {saleForm.formState.errors.quantity && (
                      <p className="text-xs text-destructive">{saleForm.formState.errors.quantity.message}</p>
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
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="sale-buyer">Buyer</Label>
                    <Input id="sale-buyer" {...saleForm.register("buyer_name")} />
                    {saleForm.formState.errors.buyer_name && (
                      <p className="text-xs text-destructive">{saleForm.formState.errors.buyer_name.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="sale-price">Sale price</Label>
                    <Input id="sale-price" type="number" step="any" {...saleForm.register("sale_price")} />
                    {saleForm.formState.errors.sale_price && (
                      <p className="text-xs text-destructive">{saleForm.formState.errors.sale_price.message}</p>
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
                  <TableHead>Date</TableHead>
                  <TableHead>Quantity</TableHead>
                  <TableHead>Buyer</TableHead>
                  <TableHead>Price</TableHead>
                  <TableHead>Recorded by</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {sales.map((sale) => (
                  <TableRow key={sale.id}>
                    <TableCell className="font-medium">{new Date(sale.sale_date).toLocaleDateString()}</TableCell>
                    <TableCell className="text-muted-foreground">{sale.quantity}</TableCell>
                    <TableCell className="text-muted-foreground">{sale.buyer_name}</TableCell>
                    <TableCell className="text-muted-foreground">{sale.sale_price}</TableCell>
                    <TableCell className="text-muted-foreground">{sale.recorder.name}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Dialog open={flockEditOpen} onOpenChange={setFlockEditOpen}>
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
                <Label htmlFor="edit-detail-flock_code">Flock code</Label>
                <Input id="edit-detail-flock_code" {...flockEditForm.register("flock_code")} />
                {flockEditForm.formState.errors.flock_code && (
                  <p className="text-xs text-destructive">{flockEditForm.formState.errors.flock_code.message}</p>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-detail-name">Name</Label>
                <Input id="edit-detail-name" {...flockEditForm.register("name")} />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-detail-bird_type">Bird type</Label>
                <Input id="edit-detail-bird_type" {...flockEditForm.register("bird_type")} />
                {flockEditForm.formState.errors.bird_type && (
                  <p className="text-xs text-destructive">{flockEditForm.formState.errors.bird_type.message}</p>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-detail-breed">Breed</Label>
                <Input id="edit-detail-breed" {...flockEditForm.register("breed")} />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-detail-initial_count">Initial count</Label>
                <Input id="edit-detail-initial_count" type="number" min={1} {...flockEditForm.register("initial_count")} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-detail-acquired_date">Acquired date</Label>
                <Input id="edit-detail-acquired_date" type="date" {...flockEditForm.register("acquired_date")} />
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
              <Label htmlFor="edit-detail-notes">Notes</Label>
              <Textarea id="edit-detail-notes" rows={2} {...flockEditForm.register("notes")} />
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
