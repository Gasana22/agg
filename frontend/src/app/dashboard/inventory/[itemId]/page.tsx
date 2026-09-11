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
  getInventoryItem,
  listInventoryTransactions,
  createInventoryTransaction,
  INVENTORY_TRANSACTION_TYPES,
} from "@/lib/modules/inventory";
import { formatRole } from "@/lib/utils";
import { usePermissions } from "@/lib/permissions";

const transactionSchema = z.object({
  type: z.string().min(1, "Pick a type"),
  quantity: z.string().min(1, "Quantity is required"),
  date: z.string().min(1, "Date is required"),
  reference: z.string().optional(),
  notes: z.string().optional(),
});
type TransactionFormValues = z.infer<typeof transactionSchema>;

export default function InventoryItemDetailPage() {
  const params = useParams<{ itemId: string }>();
  const itemId = Number(params.itemId);
  const queryClient = useQueryClient();
  const { canManageInventory } = usePermissions();

  const [transactionOpen, setTransactionOpen] = React.useState(false);
  const [transactionError, setTransactionError] = React.useState<string | null>(null);

  const { data: item } = useQuery({
    queryKey: ["inventory-item", itemId],
    queryFn: () => getInventoryItem(itemId),
  });

  const { data: transactions, isLoading: transactionsLoading } = useQuery({
    queryKey: ["inventory-transactions", itemId],
    queryFn: () => listInventoryTransactions(itemId),
  });

  const transactionForm = useForm<TransactionFormValues>({ resolver: zodResolver(transactionSchema) });

  const createTransactionMutation = useMutation({
    mutationFn: (values: TransactionFormValues) =>
      createInventoryTransaction(itemId, {
        type: values.type,
        quantity: Number(values.quantity),
        date: values.date,
        reference: values.reference || undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["inventory-transactions", itemId] });
      queryClient.invalidateQueries({ queryKey: ["inventory-item", itemId] });
      queryClient.invalidateQueries({ queryKey: ["inventory-items"] });
      queryClient.invalidateQueries({ queryKey: ["inventory-low-stock"] });
      setTransactionOpen(false);
      transactionForm.reset();
    },
    onError: (err) =>
      setTransactionError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not record transaction." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{item?.name ?? "Inventory item"}</h1>
          <p className="text-sm text-muted-foreground">{item?.category ?? "No category"}</p>
        </div>
        {item && (
          <Badge variant={item.is_low_stock ? "destructive" : "success"}>
            {item.is_low_stock ? "Low stock" : "OK"}
          </Badge>
        )}
      </div>

      {item && (
        <div className="grid grid-cols-3 gap-4 md:max-w-md">
          <div>
            <p className="text-xs text-muted-foreground">Current quantity</p>
            <p className="text-lg font-semibold">
              {item.current_quantity} {item.unit}
            </p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Reorder level</p>
            <p className="text-lg font-semibold">{item.reorder_level ?? "—"}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Status</p>
            <p className="text-lg font-semibold">{item.is_active ? "Active" : "Inactive"}</p>
          </div>
        </div>
      )}

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Transactions</CardTitle>
          {canManageInventory && (
          <Dialog open={transactionOpen} onOpenChange={setTransactionOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add transaction
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Record a stock movement</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={transactionForm.handleSubmit((values) => {
                  setTransactionError(null);
                  createTransactionMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label>Type</Label>
                  <Select onValueChange={(v) => transactionForm.setValue("type", v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select a type" />
                    </SelectTrigger>
                    <SelectContent>
                      {INVENTORY_TRANSACTION_TYPES.map((type) => (
                        <SelectItem key={type} value={type}>
                          {formatRole(type)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {transactionForm.formState.errors.type && (
                    <p className="text-xs text-destructive">{transactionForm.formState.errors.type.message}</p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="transaction-quantity">Quantity</Label>
                    <Input
                      id="transaction-quantity"
                      type="number"
                      step="any"
                      {...transactionForm.register("quantity")}
                    />
                    {transactionForm.formState.errors.quantity && (
                      <p className="text-xs text-destructive">
                        {transactionForm.formState.errors.quantity.message}
                      </p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="transaction-date">Date</Label>
                    <Input id="transaction-date" type="date" {...transactionForm.register("date")} />
                    {transactionForm.formState.errors.date && (
                      <p className="text-xs text-destructive">{transactionForm.formState.errors.date.message}</p>
                    )}
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="transaction-reference">Reference</Label>
                  <Input id="transaction-reference" {...transactionForm.register("reference")} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="transaction-notes">Notes</Label>
                  <Textarea id="transaction-notes" rows={2} {...transactionForm.register("notes")} />
                </div>
                {transactionError && <p className="text-sm text-destructive">{transactionError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createTransactionMutation.isPending}>
                    {createTransactionMutation.isPending ? "Recording…" : "Add transaction"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
          )}
        </CardHeader>
        <CardContent className="pb-6">
          {transactionsLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !transactions || transactions.length === 0 ? (
            <p className="text-sm text-muted-foreground">No transactions yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Type</TableHead>
                  <TableHead>Quantity</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Reference</TableHead>
                  <TableHead>Recorded by</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {transactions.map((transaction) => (
                  <TableRow key={transaction.id}>
                    <TableCell>
                      <Badge variant={transaction.type === "stock_in" ? "success" : "warning"}>
                        {formatRole(transaction.type)}
                      </Badge>
                    </TableCell>
                    <TableCell className="font-medium">
                      {transaction.type === "stock_in" ? "+" : "-"}
                      {transaction.quantity} {item?.unit}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {new Date(transaction.date).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{transaction.reference ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{transaction.recorder.name}</TableCell>
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
