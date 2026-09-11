"use client";

import * as React from "react";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useFieldArray, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { Plus, Trash2, Pencil } from "lucide-react";

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
  listSuppliers,
  createSupplier,
  updateSupplier,
  listPurchaseOrders,
  createPurchaseOrder,
  getProcurementSummary,
  type Supplier,
} from "@/lib/modules/procurement";
import { useFarm } from "@/lib/farm-context";
import { usePermissions } from "@/lib/permissions";
import { formatRole } from "@/lib/utils";

const supplierSchema = z.object({
  name: z.string().min(1, "Name is required"),
  category: z.string().optional(),
  phone: z.string().optional(),
  email: z.string().optional(),
  address: z.string().optional(),
  notes: z.string().optional(),
});
type SupplierFormValues = z.infer<typeof supplierSchema>;

const poItemSchema = z.object({
  item_name: z.string().min(1, "Required"),
  category: z.string().optional(),
  quantity: z.string().min(1, "Required"),
  unit: z.string().min(1, "Required"),
  unit_price: z.string().min(1, "Required"),
});

const poSchema = z.object({
  supplier_id: z.string().min(1, "Pick a supplier"),
  order_date: z.string().min(1, "Order date is required"),
  expected_delivery_date: z.string().optional(),
  notes: z.string().optional(),
  items: z.array(poItemSchema).min(1, "At least one item is required"),
});
type PoFormValues = z.infer<typeof poSchema>;

const STATUS_VARIANT: Record<string, "default" | "secondary" | "warning" | "success" | "destructive"> = {
  ordered: "secondary",
  partially_delivered: "warning",
  delivered: "success",
  cancelled: "destructive",
};

function startOfMonth() {
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

export default function ProcurementPage() {
  const { currentFarmId } = useFarm();
  const { canManageProcurement } = usePermissions();
  const queryClient = useQueryClient();

  const [supplierOpen, setSupplierOpen] = React.useState(false);
  const [supplierError, setSupplierError] = React.useState<string | null>(null);
  const [poOpen, setPoOpen] = React.useState(false);
  const [poError, setPoError] = React.useState<string | null>(null);
  const [editingSupplier, setEditingSupplier] = React.useState<Supplier | null>(null);
  const [supplierEditError, setSupplierEditError] = React.useState<string | null>(null);

  const [from, setFrom] = React.useState(startOfMonth());
  const [to, setTo] = React.useState(today());
  const [appliedFrom, setAppliedFrom] = React.useState(from);
  const [appliedTo, setAppliedTo] = React.useState(to);

  const { data: suppliers, isLoading: suppliersLoading } = useQuery({
    queryKey: ["suppliers", currentFarmId],
    queryFn: () => listSuppliers(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const { data: purchaseOrders, isLoading: ordersLoading } = useQuery({
    queryKey: ["purchase-orders", currentFarmId],
    queryFn: () => listPurchaseOrders(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const { data: summary } = useQuery({
    queryKey: ["procurement-summary", currentFarmId, appliedFrom, appliedTo],
    queryFn: () => getProcurementSummary(currentFarmId!, appliedFrom, appliedTo),
    enabled: !!currentFarmId,
  });

  const supplierForm = useForm<SupplierFormValues>({ resolver: zodResolver(supplierSchema) });
  const supplierEditForm = useForm<SupplierFormValues>({ resolver: zodResolver(supplierSchema) });
  const poForm = useForm<PoFormValues>({
    resolver: zodResolver(poSchema),
    defaultValues: {
      items: [{ item_name: "", category: "", quantity: "", unit: "", unit_price: "" }],
    },
  });
  const { fields, append, remove } = useFieldArray({ control: poForm.control, name: "items" });

  const createSupplierMutation = useMutation({
    mutationFn: (values: SupplierFormValues) => createSupplier(currentFarmId!, values),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["suppliers", currentFarmId] });
      setSupplierOpen(false);
      supplierForm.reset();
    },
    onError: (err) =>
      setSupplierError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add supplier." : "Something went wrong."
      ),
  });

  const editSupplierMutation = useMutation({
    mutationFn: (values: SupplierFormValues) =>
      updateSupplier(editingSupplier!.id, {
        name: values.name,
        category: values.category || undefined,
        phone: values.phone || undefined,
        email: values.email || undefined,
        address: values.address || undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["suppliers", currentFarmId] });
      setEditingSupplier(null);
    },
    onError: (err) =>
      setSupplierEditError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not update supplier." : "Something went wrong."
      ),
  });

  const createPoMutation = useMutation({
    mutationFn: (values: PoFormValues) =>
      createPurchaseOrder(currentFarmId!, {
        supplier_id: Number(values.supplier_id),
        order_date: values.order_date,
        expected_delivery_date: values.expected_delivery_date || undefined,
        notes: values.notes || undefined,
        items: values.items.map((item) => ({
          item_name: item.item_name,
          category: item.category || undefined,
          quantity: Number(item.quantity),
          unit: item.unit,
          unit_price: Number(item.unit_price),
        })),
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["purchase-orders", currentFarmId] });
      queryClient.invalidateQueries({ queryKey: ["procurement-summary", currentFarmId] });
      setPoOpen(false);
      poForm.reset({ items: [{ item_name: "", category: "", quantity: "", unit: "", unit_price: "" }] });
    },
    onError: (err) =>
      setPoError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not create purchase order." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Procurement</h1>
        <p className="text-sm text-muted-foreground">Suppliers, purchase orders, and spend.</p>
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Procurement summary</CardTitle>
          <form
            className="flex items-end gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              setAppliedFrom(from);
              setAppliedTo(to);
            }}
          >
            <div className="flex flex-col gap-1">
              <Label htmlFor="proc-from" className="text-xs">
                From
              </Label>
              <Input id="proc-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="h-8 w-36" />
            </div>
            <div className="flex flex-col gap-1">
              <Label htmlFor="proc-to" className="text-xs">
                To
              </Label>
              <Input id="proc-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} className="h-8 w-36" />
            </div>
            <Button type="submit" size="sm">
              Apply
            </Button>
          </form>
        </CardHeader>
        <CardContent className="pb-6">
          {summary ? (
            <div className="grid grid-cols-2 gap-4 md:grid-cols-5">
              <div>
                <p className="text-xs text-muted-foreground">Orders</p>
                <p className="text-lg font-semibold">{summary.order_count}</p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground">Total ordered</p>
                <p className="text-lg font-semibold">{summary.total_ordered}</p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground">Total paid</p>
                <p className="text-lg font-semibold">{summary.total_paid}</p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground">Outstanding</p>
                <p className="text-lg font-semibold">{summary.outstanding_balance}</p>
              </div>
              <div className="col-span-2 flex flex-wrap gap-2 md:col-span-1">
                {Object.entries(summary.orders_by_status).map(([status, count]) => (
                  <Badge key={status} variant={STATUS_VARIANT[status] ?? "secondary"}>
                    {formatRole(status)}: {count}
                  </Badge>
                ))}
              </div>
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">Loading…</p>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Suppliers</CardTitle>
          {canManageProcurement && (
          <Dialog open={supplierOpen} onOpenChange={setSupplierOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add supplier
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Add a supplier</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={supplierForm.handleSubmit((values) => {
                  setSupplierError(null);
                  createSupplierMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="supplier-name">Name</Label>
                  <Input id="supplier-name" {...supplierForm.register("name")} />
                  {supplierForm.formState.errors.name && (
                    <p className="text-xs text-destructive">{supplierForm.formState.errors.name.message}</p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="supplier-category">Category</Label>
                    <Input id="supplier-category" {...supplierForm.register("category")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="supplier-phone">Phone</Label>
                    <Input id="supplier-phone" {...supplierForm.register("phone")} />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="supplier-email">Email</Label>
                    <Input id="supplier-email" type="email" {...supplierForm.register("email")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="supplier-address">Address</Label>
                    <Input id="supplier-address" {...supplierForm.register("address")} />
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="supplier-notes">Notes</Label>
                  <Textarea id="supplier-notes" rows={2} {...supplierForm.register("notes")} />
                </div>
                {supplierError && <p className="text-sm text-destructive">{supplierError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createSupplierMutation.isPending}>
                    {createSupplierMutation.isPending ? "Adding…" : "Add supplier"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
          )}
        </CardHeader>
        <CardContent className="pb-6">
          {suppliersLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !suppliers || suppliers.length === 0 ? (
            <p className="text-sm text-muted-foreground">No suppliers yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Category</TableHead>
                  <TableHead>Phone</TableHead>
                  <TableHead>Email</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {suppliers.map((supplier) => (
                  <TableRow key={supplier.id}>
                    <TableCell className="font-medium">{supplier.name}</TableCell>
                    <TableCell className="text-muted-foreground">{supplier.category ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{supplier.phone ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{supplier.email ?? "—"}</TableCell>
                    <TableCell>
                      <Badge variant={supplier.is_active ? "success" : "secondary"}>
                        {supplier.is_active ? "Active" : "Inactive"}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      {canManageProcurement && (
                        <Button
                          variant="ghost"
                          size="icon"
                          aria-label={`Edit supplier ${supplier.name}`}
                          onClick={() => {
                            setEditingSupplier(supplier);
                            setSupplierEditError(null);
                            supplierEditForm.reset({
                              name: supplier.name,
                              category: supplier.category ?? "",
                              phone: supplier.phone ?? "",
                              email: supplier.email ?? "",
                              address: supplier.address ?? "",
                              notes: supplier.notes ?? "",
                            });
                          }}
                        >
                          <Pencil className="size-4" />
                        </Button>
                      )}
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
          <CardTitle>Purchase orders</CardTitle>
          {canManageProcurement && (
          <Dialog open={poOpen} onOpenChange={setPoOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2" disabled={!suppliers || suppliers.length === 0}>
                <Plus className="size-4" />
                New purchase order
              </Button>
            </DialogTrigger>
            <DialogContent className="max-w-2xl">
              <DialogHeader>
                <DialogTitle>Create a purchase order</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={poForm.handleSubmit((values) => {
                  setPoError(null);
                  createPoMutation.mutate(values);
                })}
                className="flex max-h-[70vh] flex-col gap-4 overflow-y-auto"
              >
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label>Supplier</Label>
                    <Select onValueChange={(v) => poForm.setValue("supplier_id", v)}>
                      <SelectTrigger>
                        <SelectValue placeholder="Select a supplier" />
                      </SelectTrigger>
                      <SelectContent>
                        {(suppliers ?? []).map((supplier) => (
                          <SelectItem key={supplier.id} value={String(supplier.id)}>
                            {supplier.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {poForm.formState.errors.supplier_id && (
                      <p className="text-xs text-destructive">{poForm.formState.errors.supplier_id.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="order_date">Order date</Label>
                    <Input id="order_date" type="date" {...poForm.register("order_date")} />
                    {poForm.formState.errors.order_date && (
                      <p className="text-xs text-destructive">{poForm.formState.errors.order_date.message}</p>
                    )}
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="expected_delivery_date">Expected delivery date</Label>
                  <Input
                    id="expected_delivery_date"
                    type="date"
                    {...poForm.register("expected_delivery_date")}
                  />
                </div>

                <div className="flex flex-col gap-2">
                  <div className="flex items-center justify-between">
                    <Label>Items</Label>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={() =>
                        append({ item_name: "", category: "", quantity: "", unit: "", unit_price: "" })
                      }
                    >
                      <Plus className="size-4" />
                      Add item
                    </Button>
                  </div>
                  {poForm.formState.errors.items?.message && (
                    <p className="text-xs text-destructive">{poForm.formState.errors.items.message}</p>
                  )}
                  {fields.map((field, index) => (
                    <div key={field.id} className="grid grid-cols-12 items-end gap-2 rounded-md border p-2">
                      <div className="col-span-4 flex flex-col gap-1">
                        <Label className="text-xs">Item</Label>
                        <Input {...poForm.register(`items.${index}.item_name`)} />
                      </div>
                      <div className="col-span-2 flex flex-col gap-1">
                        <Label className="text-xs">Qty</Label>
                        <Input type="number" step="any" {...poForm.register(`items.${index}.quantity`)} />
                      </div>
                      <div className="col-span-2 flex flex-col gap-1">
                        <Label className="text-xs">Unit</Label>
                        <Input {...poForm.register(`items.${index}.unit`)} />
                      </div>
                      <div className="col-span-3 flex flex-col gap-1">
                        <Label className="text-xs">Unit price</Label>
                        <Input type="number" step="any" {...poForm.register(`items.${index}.unit_price`)} />
                      </div>
                      <div className="col-span-1">
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          aria-label={`Remove line ${index + 1}`}
                          disabled={fields.length === 1}
                          onClick={() => remove(index)}
                        >
                          <Trash2 className="size-4 text-destructive" />
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>

                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="po-notes">Notes</Label>
                  <Textarea id="po-notes" rows={2} {...poForm.register("notes")} />
                </div>
                {poError && <p className="text-sm text-destructive">{poError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createPoMutation.isPending}>
                    {createPoMutation.isPending ? "Creating…" : "Create purchase order"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
          )}
        </CardHeader>
        <CardContent className="pb-6">
          {ordersLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !purchaseOrders || purchaseOrders.length === 0 ? (
            <p className="text-sm text-muted-foreground">No purchase orders yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Order date</TableHead>
                  <TableHead>Supplier</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Total</TableHead>
                  <TableHead>Paid</TableHead>
                  <TableHead>Balance</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {purchaseOrders.map((order) => (
                  <TableRow key={order.id}>
                    <TableCell className="font-medium">
                      <Link href={`/dashboard/procurement/orders/${order.id}`} className="hover:underline">
                        {new Date(order.order_date).toLocaleDateString()}
                      </Link>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{order.supplier.name}</TableCell>
                    <TableCell>
                      <Badge variant={STATUS_VARIANT[order.status] ?? "secondary"}>{formatRole(order.status)}</Badge>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{order.total_amount ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{order.total_paid ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{order.balance ?? "—"}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Dialog
        open={!!editingSupplier}
        onOpenChange={(open) => {
          if (!open) {
            setEditingSupplier(null);
            setSupplierEditError(null);
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit supplier</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={supplierEditForm.handleSubmit((values) => {
              setSupplierEditError(null);
              editSupplierMutation.mutate(values);
            })}
            className="flex flex-col gap-4"
          >
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="edit-supplier-name">Name</Label>
              <Input id="edit-supplier-name" {...supplierEditForm.register("name")} />
              {supplierEditForm.formState.errors.name && (
                <p className="text-xs text-destructive">{supplierEditForm.formState.errors.name.message}</p>
              )}
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-supplier-category">Category</Label>
                <Input id="edit-supplier-category" {...supplierEditForm.register("category")} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-supplier-phone">Phone</Label>
                <Input id="edit-supplier-phone" {...supplierEditForm.register("phone")} />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-supplier-email">Email</Label>
                <Input id="edit-supplier-email" type="email" {...supplierEditForm.register("email")} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-supplier-address">Address</Label>
                <Input id="edit-supplier-address" {...supplierEditForm.register("address")} />
              </div>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="edit-supplier-notes">Notes</Label>
              <Textarea id="edit-supplier-notes" rows={2} {...supplierEditForm.register("notes")} />
            </div>
            {supplierEditError && <p className="text-sm text-destructive">{supplierEditError}</p>}
            <DialogFooter>
              <Button type="submit" disabled={editSupplierMutation.isPending}>
                {editSupplierMutation.isPending ? "Saving…" : "Save changes"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
