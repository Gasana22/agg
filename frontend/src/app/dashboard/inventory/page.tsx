"use client";

import * as React from "react";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { Plus, AlertTriangle, Pencil } from "lucide-react";

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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import {
  listInventoryItems,
  createInventoryItem,
  updateInventoryItem,
  getLowStockItems,
  type InventoryItem,
} from "@/lib/modules/inventory";
import { useFarm } from "@/lib/farm-context";

const itemSchema = z.object({
  name: z.string().min(1, "Name is required"),
  category: z.string().optional(),
  unit: z.string().min(1, "Unit is required"),
  reorder_level: z.string().optional(),
  notes: z.string().optional(),
});
type ItemFormValues = z.infer<typeof itemSchema>;

export default function InventoryPage() {
  const { currentFarmId } = useFarm();
  const queryClient = useQueryClient();
  const [itemOpen, setItemOpen] = React.useState(false);
  const [itemError, setItemError] = React.useState<string | null>(null);
  const [editingItem, setEditingItem] = React.useState<InventoryItem | null>(null);
  const [itemEditError, setItemEditError] = React.useState<string | null>(null);

  const { data: items, isLoading: itemsLoading } = useQuery({
    queryKey: ["inventory-items", currentFarmId],
    queryFn: () => listInventoryItems(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const { data: lowStock } = useQuery({
    queryKey: ["inventory-low-stock", currentFarmId],
    queryFn: () => getLowStockItems(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const itemForm = useForm<ItemFormValues>({ resolver: zodResolver(itemSchema) });
  const itemEditForm = useForm<ItemFormValues>({ resolver: zodResolver(itemSchema) });

  const createItemMutation = useMutation({
    mutationFn: (values: ItemFormValues) =>
      createInventoryItem(currentFarmId!, {
        name: values.name,
        category: values.category || undefined,
        unit: values.unit,
        reorder_level: values.reorder_level ? Number(values.reorder_level) : undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["inventory-items", currentFarmId] });
      queryClient.invalidateQueries({ queryKey: ["inventory-low-stock", currentFarmId] });
      setItemOpen(false);
      itemForm.reset();
    },
    onError: (err) =>
      setItemError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add item." : "Something went wrong."
      ),
  });

  const editItemMutation = useMutation({
    mutationFn: (values: ItemFormValues) =>
      updateInventoryItem(editingItem!.id, {
        name: values.name,
        category: values.category || undefined,
        unit: values.unit,
        reorder_level: values.reorder_level ? Number(values.reorder_level) : undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["inventory-items", currentFarmId] });
      queryClient.invalidateQueries({ queryKey: ["inventory-low-stock", currentFarmId] });
      setEditingItem(null);
    },
    onError: (err) =>
      setItemEditError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not update item." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Inventory</h1>
        <p className="text-sm text-muted-foreground">Stock items and stock movements.</p>
      </div>

      {lowStock && lowStock.length > 0 && (
        <Card className="border-destructive/40">
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-destructive">
              <AlertTriangle className="size-4" />
              Low stock
            </CardTitle>
          </CardHeader>
          <CardContent className="pb-6">
            <div className="flex flex-wrap gap-2">
              {lowStock.map((item) => (
                <Link key={item.id} href={`/dashboard/inventory/${item.id}`}>
                  <Badge variant="destructive" className="px-3 py-1.5 text-sm">
                    {item.name}: {item.current_quantity} {item.unit} (reorder at {item.reorder_level})
                  </Badge>
                </Link>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Inventory items</CardTitle>
          <Dialog open={itemOpen} onOpenChange={setItemOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add item
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Add an inventory item</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={itemForm.handleSubmit((values) => {
                  setItemError(null);
                  createItemMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="item-name">Name</Label>
                  <Input id="item-name" {...itemForm.register("name")} />
                  {itemForm.formState.errors.name && (
                    <p className="text-xs text-destructive">{itemForm.formState.errors.name.message}</p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="item-category">Category</Label>
                    <Input id="item-category" {...itemForm.register("category")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="item-unit">Unit</Label>
                    <Input id="item-unit" placeholder="kg, bags, litres..." {...itemForm.register("unit")} />
                    {itemForm.formState.errors.unit && (
                      <p className="text-xs text-destructive">{itemForm.formState.errors.unit.message}</p>
                    )}
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="reorder_level">Reorder level</Label>
                  <Input id="reorder_level" type="number" step="any" {...itemForm.register("reorder_level")} />
                  <p className="text-xs text-muted-foreground">
                    Get flagged as low stock once quantity falls to or below this level. Leave blank to never flag.
                  </p>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="item-notes">Notes</Label>
                  <Textarea id="item-notes" rows={2} {...itemForm.register("notes")} />
                </div>
                {itemError && <p className="text-sm text-destructive">{itemError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createItemMutation.isPending}>
                    {createItemMutation.isPending ? "Adding…" : "Add item"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {itemsLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !items || items.length === 0 ? (
            <p className="text-sm text-muted-foreground">No inventory items yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Category</TableHead>
                  <TableHead>Quantity</TableHead>
                  <TableHead>Reorder level</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell className="font-medium">
                      <Link href={`/dashboard/inventory/${item.id}`} className="hover:underline">
                        {item.name}
                      </Link>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{item.category ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {item.current_quantity} {item.unit}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{item.reorder_level ?? "—"}</TableCell>
                    <TableCell>
                      {item.is_low_stock ? (
                        <Badge variant="destructive">Low stock</Badge>
                      ) : (
                        <Badge variant="success">OK</Badge>
                      )}
                    </TableCell>
                    <TableCell>
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => {
                          setEditingItem(item);
                          setItemEditError(null);
                          itemEditForm.reset({
                            name: item.name,
                            category: item.category ?? "",
                            unit: item.unit,
                            reorder_level: item.reorder_level ?? "",
                            notes: item.notes ?? "",
                          });
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
        open={!!editingItem}
        onOpenChange={(open) => {
          if (!open) {
            setEditingItem(null);
            setItemEditError(null);
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit inventory item</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={itemEditForm.handleSubmit((values) => {
              setItemEditError(null);
              editItemMutation.mutate(values);
            })}
            className="flex flex-col gap-4"
          >
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="edit-item-name">Name</Label>
              <Input id="edit-item-name" {...itemEditForm.register("name")} />
              {itemEditForm.formState.errors.name && (
                <p className="text-xs text-destructive">{itemEditForm.formState.errors.name.message}</p>
              )}
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-item-category">Category</Label>
                <Input id="edit-item-category" {...itemEditForm.register("category")} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-item-unit">Unit</Label>
                <Input id="edit-item-unit" {...itemEditForm.register("unit")} />
                {itemEditForm.formState.errors.unit && (
                  <p className="text-xs text-destructive">{itemEditForm.formState.errors.unit.message}</p>
                )}
              </div>
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="edit-reorder_level">Reorder level</Label>
              <Input
                id="edit-reorder_level"
                type="number"
                step="any"
                {...itemEditForm.register("reorder_level")}
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="edit-item-notes">Notes</Label>
              <Textarea id="edit-item-notes" rows={2} {...itemEditForm.register("notes")} />
            </div>
            {itemEditError && <p className="text-sm text-destructive">{itemEditError}</p>}
            <DialogFooter>
              <Button type="submit" disabled={editItemMutation.isPending}>
                {editItemMutation.isPending ? "Saving…" : "Save changes"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
