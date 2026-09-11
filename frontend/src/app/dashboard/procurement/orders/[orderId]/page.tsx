"use client";

import * as React from "react";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { Plus, Trash2 } from "lucide-react";

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
  getPurchaseOrder,
  updatePurchaseOrderStatus,
  deletePurchaseOrderItem,
  createDelivery,
  createPayment,
  PURCHASE_ORDER_STATUSES,
  PAYMENT_METHODS,
} from "@/lib/modules/procurement";
import { formatRole } from "@/lib/utils";

const deliverySchema = z.object({
  delivery_date: z.string().min(1, "Delivery date is required"),
  is_complete: z.string(),
  notes: z.string().optional(),
});
type DeliveryFormValues = z.infer<typeof deliverySchema>;

const paymentSchema = z.object({
  amount: z.string().min(1, "Amount is required"),
  payment_date: z.string().min(1, "Payment date is required"),
  method: z.string().min(1, "Pick a method"),
  reference: z.string().optional(),
});
type PaymentFormValues = z.infer<typeof paymentSchema>;

const STATUS_VARIANT: Record<string, "default" | "secondary" | "warning" | "success" | "destructive"> = {
  ordered: "secondary",
  partially_delivered: "warning",
  delivered: "success",
  cancelled: "destructive",
};

export default function PurchaseOrderDetailPage() {
  const params = useParams<{ orderId: string }>();
  const orderId = Number(params.orderId);
  const queryClient = useQueryClient();

  const [deliveryOpen, setDeliveryOpen] = React.useState(false);
  const [deliveryError, setDeliveryError] = React.useState<string | null>(null);
  const [paymentOpen, setPaymentOpen] = React.useState(false);
  const [paymentError, setPaymentError] = React.useState<string | null>(null);

  const { data: order } = useQuery({
    queryKey: ["purchase-order", orderId],
    queryFn: () => getPurchaseOrder(orderId),
  });

  const deliveryForm = useForm<DeliveryFormValues>({
    resolver: zodResolver(deliverySchema),
    defaultValues: { is_complete: "false" },
  });
  const paymentForm = useForm<PaymentFormValues>({ resolver: zodResolver(paymentSchema) });

  const statusMutation = useMutation({
    mutationFn: (status: string) => updatePurchaseOrderStatus(orderId, status as never),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["purchase-order", orderId] });
      queryClient.invalidateQueries({ queryKey: ["purchase-orders"] });
    },
  });

  const deleteItemMutation = useMutation({
    mutationFn: (itemId: number) => deletePurchaseOrderItem(itemId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["purchase-order", orderId] });
      queryClient.invalidateQueries({ queryKey: ["purchase-orders"] });
    },
  });

  const createDeliveryMutation = useMutation({
    mutationFn: (values: DeliveryFormValues) =>
      createDelivery(orderId, {
        delivery_date: values.delivery_date,
        is_complete: values.is_complete === "true",
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["purchase-order", orderId] });
      setDeliveryOpen(false);
      deliveryForm.reset({ is_complete: "false" });
    },
    onError: (err) =>
      setDeliveryError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not record delivery." : "Something went wrong."
      ),
  });

  const createPaymentMutation = useMutation({
    mutationFn: (values: PaymentFormValues) =>
      createPayment(orderId, {
        amount: Number(values.amount),
        payment_date: values.payment_date,
        method: values.method,
        reference: values.reference || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["purchase-order", orderId] });
      queryClient.invalidateQueries({ queryKey: ["purchase-orders"] });
      setPaymentOpen(false);
      paymentForm.reset();
    },
    onError: (err) =>
      setPaymentError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not record payment." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{order?.supplier.name ?? "Purchase order"}</h1>
          <p className="text-sm text-muted-foreground">
            {order ? `Ordered ${new Date(order.order_date).toLocaleDateString()}` : ""}
          </p>
        </div>
        {order && (
          <div className="flex items-center gap-2">
            <Badge variant={STATUS_VARIANT[order.status] ?? "secondary"}>{formatRole(order.status)}</Badge>
            <Select value={order.status} onValueChange={(v) => statusMutation.mutate(v)}>
              <SelectTrigger className="h-8 w-44">
                <SelectValue placeholder="Change status" />
              </SelectTrigger>
              <SelectContent>
                {PURCHASE_ORDER_STATUSES.map((status) => (
                  <SelectItem key={status} value={status}>
                    {formatRole(status)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}
      </div>

      {order && (
        <div className="grid grid-cols-3 gap-4 md:max-w-md">
          <div>
            <p className="text-xs text-muted-foreground">Total</p>
            <p className="text-lg font-semibold">{order.total_amount}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Paid</p>
            <p className="text-lg font-semibold">{order.total_paid}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Balance</p>
            <p className="text-lg font-semibold">{order.balance}</p>
          </div>
        </div>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Items</CardTitle>
        </CardHeader>
        <CardContent className="pb-6">
          {!order || order.items.length === 0 ? (
            <p className="text-sm text-muted-foreground">No items.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Item</TableHead>
                  <TableHead>Category</TableHead>
                  <TableHead>Quantity</TableHead>
                  <TableHead>Unit price</TableHead>
                  <TableHead>Line total</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {order.items.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell className="font-medium">{item.item_name}</TableCell>
                    <TableCell className="text-muted-foreground">{item.category ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {item.quantity} {item.unit}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{item.unit_price}</TableCell>
                    <TableCell className="text-muted-foreground">{item.line_total}</TableCell>
                    <TableCell>
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => deleteItemMutation.mutate(item.id)}
                      >
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

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Deliveries</CardTitle>
          <Dialog open={deliveryOpen} onOpenChange={setDeliveryOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Record delivery
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Record a delivery</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={deliveryForm.handleSubmit((values) => {
                  setDeliveryError(null);
                  createDeliveryMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="delivery_date">Delivery date</Label>
                  <Input id="delivery_date" type="date" {...deliveryForm.register("delivery_date")} />
                  {deliveryForm.formState.errors.delivery_date && (
                    <p className="text-xs text-destructive">
                      {deliveryForm.formState.errors.delivery_date.message}
                    </p>
                  )}
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Complete?</Label>
                  <Select
                    defaultValue="false"
                    onValueChange={(v) => deliveryForm.setValue("is_complete", v)}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="false">Partial delivery</SelectItem>
                      <SelectItem value="true">Fully delivered</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="delivery-notes">Notes</Label>
                  <Textarea id="delivery-notes" rows={2} {...deliveryForm.register("notes")} />
                </div>
                {deliveryError && <p className="text-sm text-destructive">{deliveryError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createDeliveryMutation.isPending}>
                    {createDeliveryMutation.isPending ? "Recording…" : "Record delivery"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {!order || order.deliveries.length === 0 ? (
            <p className="text-sm text-muted-foreground">No deliveries recorded yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Date</TableHead>
                  <TableHead>Complete</TableHead>
                  <TableHead>Received by</TableHead>
                  <TableHead>Notes</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {order.deliveries.map((delivery) => (
                  <TableRow key={delivery.id}>
                    <TableCell className="font-medium">
                      {new Date(delivery.delivery_date).toLocaleDateString()}
                    </TableCell>
                    <TableCell>
                      <Badge variant={delivery.is_complete ? "success" : "warning"}>
                        {delivery.is_complete ? "Complete" : "Partial"}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{delivery.receiver?.name ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{delivery.notes ?? "—"}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Payments</CardTitle>
          <Dialog open={paymentOpen} onOpenChange={setPaymentOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Record payment
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Record a payment</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={paymentForm.handleSubmit((values) => {
                  setPaymentError(null);
                  createPaymentMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="payment-amount">Amount</Label>
                    <Input id="payment-amount" type="number" step="any" {...paymentForm.register("amount")} />
                    {paymentForm.formState.errors.amount && (
                      <p className="text-xs text-destructive">{paymentForm.formState.errors.amount.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="payment-date">Payment date</Label>
                    <Input id="payment-date" type="date" {...paymentForm.register("payment_date")} />
                    {paymentForm.formState.errors.payment_date && (
                      <p className="text-xs text-destructive">
                        {paymentForm.formState.errors.payment_date.message}
                      </p>
                    )}
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Method</Label>
                  <Select onValueChange={(v) => paymentForm.setValue("method", v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select a method" />
                    </SelectTrigger>
                    <SelectContent>
                      {PAYMENT_METHODS.map((method) => (
                        <SelectItem key={method} value={method}>
                          {formatRole(method)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {paymentForm.formState.errors.method && (
                    <p className="text-xs text-destructive">{paymentForm.formState.errors.method.message}</p>
                  )}
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="payment-reference">Reference</Label>
                  <Input id="payment-reference" {...paymentForm.register("reference")} />
                </div>
                {paymentError && <p className="text-sm text-destructive">{paymentError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createPaymentMutation.isPending}>
                    {createPaymentMutation.isPending ? "Recording…" : "Record payment"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {!order || order.payments.length === 0 ? (
            <p className="text-sm text-muted-foreground">No payments recorded yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Amount</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Method</TableHead>
                  <TableHead>Reference</TableHead>
                  <TableHead>Recorded by</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {order.payments.map((payment) => (
                  <TableRow key={payment.id}>
                    <TableCell className="font-medium">{payment.amount}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {new Date(payment.payment_date).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{formatRole(payment.method)}</TableCell>
                    <TableCell className="text-muted-foreground">{payment.reference ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{payment.recorder.name}</TableCell>
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
