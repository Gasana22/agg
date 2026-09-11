"use client";

import * as React from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { Plus, Pencil } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
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
import {
  listExpenses,
  createExpense,
  updateExpense,
  listPayrollPayments,
  createPayrollPayment,
  payPayrollPayment,
  getFinanceIncome,
  getFinanceExpensesBreakdown,
  getFinanceProfitAndLoss,
  EXPENSE_CATEGORIES,
  type Expense,
} from "@/lib/modules/finance";
import { listWorkerProfiles } from "@/lib/modules/worker-management";
import { useFarm } from "@/lib/farm-context";
import { usePermissions } from "@/lib/permissions";
import { formatRole } from "@/lib/utils";

const expenseSchema = z.object({
  category: z.string().min(1, "Pick a category"),
  description: z.string().min(1, "Description is required"),
  amount: z.string().min(1, "Amount is required"),
  date: z.string().min(1, "Date is required"),
});
type ExpenseFormValues = z.infer<typeof expenseSchema>;

const payrollSchema = z.object({
  period_start: z.string().min(1, "Start date is required"),
  period_end: z.string().min(1, "End date is required"),
  deductions: z.string().optional(),
});
type PayrollFormValues = z.infer<typeof payrollSchema>;

function SummaryRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between py-1 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  );
}

function startOfMonth() {
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

export default function FinancePage() {
  const { currentFarmId } = useFarm();
  const { canManageFinance } = usePermissions();
  const queryClient = useQueryClient();

  const [from, setFrom] = React.useState(startOfMonth());
  const [to, setTo] = React.useState(today());
  const [appliedFrom, setAppliedFrom] = React.useState(from);
  const [appliedTo, setAppliedTo] = React.useState(to);

  const [expenseOpen, setExpenseOpen] = React.useState(false);
  const [expenseError, setExpenseError] = React.useState<string | null>(null);
  const [editingExpense, setEditingExpense] = React.useState<Expense | null>(null);
  const [expenseEditError, setExpenseEditError] = React.useState<string | null>(null);
  const [editCategory, setEditCategory] = React.useState("");
  const [payrollOpen, setPayrollOpen] = React.useState(false);
  const [payrollError, setPayrollError] = React.useState<string | null>(null);
  const [selectedWorkerId, setSelectedWorkerId] = React.useState<string>("");

  const { data: income } = useQuery({
    queryKey: ["finance-income", currentFarmId, appliedFrom, appliedTo],
    queryFn: () => getFinanceIncome(currentFarmId!, appliedFrom, appliedTo),
    enabled: !!currentFarmId,
  });

  const { data: expensesBreakdown } = useQuery({
    queryKey: ["finance-expenses-breakdown", currentFarmId, appliedFrom, appliedTo],
    queryFn: () => getFinanceExpensesBreakdown(currentFarmId!, appliedFrom, appliedTo),
    enabled: !!currentFarmId,
  });

  const { data: profitAndLoss } = useQuery({
    queryKey: ["finance-profit-loss", currentFarmId, appliedFrom, appliedTo],
    queryFn: () => getFinanceProfitAndLoss(currentFarmId!, appliedFrom, appliedTo),
    enabled: !!currentFarmId,
  });

  const { data: expenses, isLoading: expensesLoading } = useQuery({
    queryKey: ["expenses", currentFarmId],
    queryFn: () => listExpenses(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const { data: workerProfiles } = useQuery({
    queryKey: ["worker-profiles", currentFarmId],
    queryFn: () => listWorkerProfiles(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const selectedWorkerProfileId = selectedWorkerId ? Number(selectedWorkerId) : null;

  const { data: payrollPayments, isLoading: payrollLoading } = useQuery({
    queryKey: ["payroll-payments", selectedWorkerProfileId],
    queryFn: () => listPayrollPayments(selectedWorkerProfileId!),
    enabled: !!selectedWorkerProfileId,
  });

  const expenseForm = useForm<ExpenseFormValues>({ resolver: zodResolver(expenseSchema) });
  const payrollForm = useForm<PayrollFormValues>({ resolver: zodResolver(payrollSchema) });
  const expenseEditForm = useForm<ExpenseFormValues>({ resolver: zodResolver(expenseSchema) });

  const createExpenseMutation = useMutation({
    mutationFn: (values: ExpenseFormValues) =>
      createExpense(currentFarmId!, {
        category: values.category,
        description: values.description,
        amount: Number(values.amount),
        date: values.date,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["expenses", currentFarmId] });
      queryClient.invalidateQueries({ queryKey: ["finance-expenses-breakdown", currentFarmId] });
      queryClient.invalidateQueries({ queryKey: ["finance-profit-loss", currentFarmId] });
      setExpenseOpen(false);
      expenseForm.reset();
    },
    onError: (err) =>
      setExpenseError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add expense." : "Something went wrong."
      ),
  });

  const editExpenseMutation = useMutation({
    mutationFn: (values: ExpenseFormValues) =>
      updateExpense(editingExpense!.id, {
        category: editCategory || undefined,
        description: values.description,
        amount: values.amount ? Number(values.amount) : undefined,
        date: values.date,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["expenses", currentFarmId] });
      queryClient.invalidateQueries({ queryKey: ["finance-expenses-breakdown", currentFarmId] });
      queryClient.invalidateQueries({ queryKey: ["finance-profit-loss", currentFarmId] });
      setEditingExpense(null);
    },
    onError: (err) =>
      setExpenseEditError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not update expense." : "Something went wrong."
      ),
  });

  const createPayrollMutation = useMutation({
    mutationFn: (values: PayrollFormValues) =>
      createPayrollPayment(selectedWorkerProfileId!, {
        period_start: values.period_start,
        period_end: values.period_end,
        deductions: values.deductions ? Number(values.deductions) : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll-payments", selectedWorkerProfileId] });
      setPayrollOpen(false);
      payrollForm.reset();
    },
    onError: (err) =>
      setPayrollError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not run payroll." : "Something went wrong."
      ),
  });

  const payMutation = useMutation({
    mutationFn: (id: number) => payPayrollPayment(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payroll-payments", selectedWorkerProfileId] });
      queryClient.invalidateQueries({ queryKey: ["finance-expenses-breakdown", currentFarmId] });
      queryClient.invalidateQueries({ queryKey: ["finance-profit-loss", currentFarmId] });
    },
  });

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Finance</h1>
        <p className="text-sm text-muted-foreground">Income, expenses, and payroll.</p>
      </div>

      <Card>
        <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <CardTitle>Financial summary</CardTitle>
          <form
            className="flex flex-col gap-2 sm:flex-row sm:items-end"
            onSubmit={(e) => {
              e.preventDefault();
              setAppliedFrom(from);
              setAppliedTo(to);
            }}
          >
            <div className="grid grid-cols-2 gap-2 sm:flex">
              <div className="flex flex-col gap-1">
                <Label htmlFor="finance-from" className="text-xs">
                  From
                </Label>
                <Input
                  id="finance-from"
                  type="date"
                  value={from}
                  onChange={(e) => setFrom(e.target.value)}
                  className="h-8 w-full sm:w-36"
                />
              </div>
              <div className="flex flex-col gap-1">
                <Label htmlFor="finance-to" className="text-xs">
                  To
                </Label>
                <Input
                  id="finance-to"
                  type="date"
                  value={to}
                  onChange={(e) => setTo(e.target.value)}
                  className="h-8 w-full sm:w-36"
                />
              </div>
            </div>
            <Button type="submit" size="sm" className="w-full sm:w-auto">
              Apply
            </Button>
          </form>
        </CardHeader>
        <CardContent className="grid grid-cols-1 gap-6 pb-6 md:grid-cols-3">
          <div>
            <h3 className="mb-2 text-sm font-semibold">Income</h3>
            {income ? (
              <>
                <SummaryRow label="Crop sales" value={income.crop_sales} />
                <SummaryRow label="Livestock sales" value={income.livestock_sales} />
                <SummaryRow label="Total" value={income.total} />
              </>
            ) : (
              <p className="text-sm text-muted-foreground">Loading…</p>
            )}
          </div>
          <div>
            <h3 className="mb-2 text-sm font-semibold">Expenses</h3>
            {expensesBreakdown ? (
              <>
                <SummaryRow label="Crop operations" value={expensesBreakdown.crop_operations} />
                <SummaryRow label="Livestock care" value={expensesBreakdown.livestock_care} />
                <SummaryRow label="General tasks" value={expensesBreakdown.general_tasks} />
                <SummaryRow label="Payroll" value={expensesBreakdown.payroll} />
                <SummaryRow label="Asset maintenance" value={expensesBreakdown.asset_maintenance} />
                <SummaryRow label="Other" value={expensesBreakdown.other_expenses} />
                <SummaryRow label="Total" value={expensesBreakdown.total} />
              </>
            ) : (
              <p className="text-sm text-muted-foreground">Loading…</p>
            )}
          </div>
          <div>
            <h3 className="mb-2 text-sm font-semibold">Profit &amp; loss</h3>
            {profitAndLoss ? (
              <>
                <SummaryRow label="Income" value={profitAndLoss.income_total} />
                <SummaryRow label="Expenses" value={profitAndLoss.expenses_total} />
                <SummaryRow
                  label="Net profit"
                  value={
                    <span className={profitAndLoss.net_profit >= 0 ? "text-emerald-600" : "text-destructive"}>
                      {profitAndLoss.net_profit}
                    </span>
                  }
                />
              </>
            ) : (
              <p className="text-sm text-muted-foreground">Loading…</p>
            )}
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Expenses</CardTitle>
          {canManageFinance && (
          <Dialog open={expenseOpen} onOpenChange={setExpenseOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add expense
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Log an expense</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={expenseForm.handleSubmit((values) => {
                  setExpenseError(null);
                  createExpenseMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label>Category</Label>
                  <Select onValueChange={(v) => expenseForm.setValue("category", v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select a category" />
                    </SelectTrigger>
                    <SelectContent>
                      {EXPENSE_CATEGORIES.map((category) => (
                        <SelectItem key={category} value={category}>
                          {formatRole(category)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {expenseForm.formState.errors.category && (
                    <p className="text-xs text-destructive">{expenseForm.formState.errors.category.message}</p>
                  )}
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="expense-description">Description</Label>
                  <Input id="expense-description" {...expenseForm.register("description")} />
                  {expenseForm.formState.errors.description && (
                    <p className="text-xs text-destructive">
                      {expenseForm.formState.errors.description.message}
                    </p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="expense-amount">Amount</Label>
                    <Input id="expense-amount" type="number" step="any" {...expenseForm.register("amount")} />
                    {expenseForm.formState.errors.amount && (
                      <p className="text-xs text-destructive">{expenseForm.formState.errors.amount.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="expense-date">Date</Label>
                    <Input id="expense-date" type="date" {...expenseForm.register("date")} />
                    {expenseForm.formState.errors.date && (
                      <p className="text-xs text-destructive">{expenseForm.formState.errors.date.message}</p>
                    )}
                  </div>
                </div>
                {expenseError && <p className="text-sm text-destructive">{expenseError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createExpenseMutation.isPending}>
                    {createExpenseMutation.isPending ? "Adding…" : "Add expense"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
          )}
        </CardHeader>
        <CardContent className="pb-6">
          {expensesLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !expenses || expenses.length === 0 ? (
            <p className="text-sm text-muted-foreground">No expenses logged yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Category</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead>Amount</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Recorded by</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {expenses.map((expense) => (
                  <TableRow key={expense.id}>
                    <TableCell className="font-medium">{formatRole(expense.category)}</TableCell>
                    <TableCell className="text-muted-foreground">{expense.description}</TableCell>
                    <TableCell className="text-muted-foreground">{expense.amount}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {new Date(expense.date).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{expense.recorder.name}</TableCell>
                    <TableCell>
                      {canManageFinance && (
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => {
                            setEditingExpense(expense);
                            expenseEditForm.reset({
                              category: expense.category,
                              description: expense.description,
                              amount: expense.amount,
                              date: expense.date.slice(0, 10),
                            });
                            setEditCategory(expense.category);
                            setExpenseEditError(null);
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
          <CardTitle>Payroll</CardTitle>
          <div className="flex items-center gap-2">
            <Select value={selectedWorkerId} onValueChange={setSelectedWorkerId}>
              <SelectTrigger className="h-8 w-52">
                <SelectValue placeholder="Select a worker" />
              </SelectTrigger>
              <SelectContent>
                {(workerProfiles ?? []).map((profile) => (
                  <SelectItem key={profile.id} value={String(profile.id)}>
                    {profile.user.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {canManageFinance && (
            <Dialog open={payrollOpen} onOpenChange={setPayrollOpen}>
              <DialogTrigger asChild>
                <Button size="sm" className="gap-2" disabled={!selectedWorkerProfileId}>
                  <Plus className="size-4" />
                  Run payroll
                </Button>
              </DialogTrigger>
              <DialogContent>
                <DialogHeader>
                  <DialogTitle>Run payroll</DialogTitle>
                </DialogHeader>
                <form
                  onSubmit={payrollForm.handleSubmit((values) => {
                    setPayrollError(null);
                    createPayrollMutation.mutate(values);
                  })}
                  className="flex flex-col gap-4"
                >
                  <p className="text-xs text-muted-foreground">
                    Days worked and gross pay are computed automatically from this worker&apos;s approved
                    attendance in the period.
                  </p>
                  <div className="grid grid-cols-2 gap-4">
                    <div className="flex flex-col gap-1.5">
                      <Label htmlFor="period_start">Period start</Label>
                      <Input id="period_start" type="date" {...payrollForm.register("period_start")} />
                      {payrollForm.formState.errors.period_start && (
                        <p className="text-xs text-destructive">
                          {payrollForm.formState.errors.period_start.message}
                        </p>
                      )}
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <Label htmlFor="period_end">Period end</Label>
                      <Input id="period_end" type="date" {...payrollForm.register("period_end")} />
                      {payrollForm.formState.errors.period_end && (
                        <p className="text-xs text-destructive">
                          {payrollForm.formState.errors.period_end.message}
                        </p>
                      )}
                    </div>
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="deductions">Deductions</Label>
                    <Input id="deductions" type="number" step="any" {...payrollForm.register("deductions")} />
                  </div>
                  {payrollError && <p className="text-sm text-destructive">{payrollError}</p>}
                  <DialogFooter>
                    <Button type="submit" disabled={createPayrollMutation.isPending}>
                      {createPayrollMutation.isPending ? "Running…" : "Run payroll"}
                    </Button>
                  </DialogFooter>
                </form>
              </DialogContent>
            </Dialog>
            )}
          </div>
        </CardHeader>
        <CardContent className="pb-6">
          {!selectedWorkerProfileId ? (
            <p className="text-sm text-muted-foreground">Select a worker to view their payroll history.</p>
          ) : payrollLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !payrollPayments || payrollPayments.length === 0 ? (
            <p className="text-sm text-muted-foreground">No payroll payments for this worker yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Period</TableHead>
                  <TableHead>Days worked</TableHead>
                  <TableHead>Gross</TableHead>
                  <TableHead>Deductions</TableHead>
                  <TableHead>Net</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {payrollPayments.map((payment) => (
                  <TableRow key={payment.id}>
                    <TableCell className="font-medium">
                      {new Date(payment.period_start).toLocaleDateString()} –{" "}
                      {new Date(payment.period_end).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{payment.days_worked}</TableCell>
                    <TableCell className="text-muted-foreground">{payment.gross_amount}</TableCell>
                    <TableCell className="text-muted-foreground">{payment.deductions}</TableCell>
                    <TableCell className="text-muted-foreground">{payment.net_amount}</TableCell>
                    <TableCell>
                      <Badge variant={payment.status === "paid" ? "success" : "secondary"}>
                        {formatRole(payment.status)}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      {canManageFinance && payment.status === "pending" && (
                        <Button size="sm" variant="outline" onClick={() => payMutation.mutate(payment.id)}>
                          Mark paid
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

      <Dialog
        open={!!editingExpense}
        onOpenChange={(open) => {
          if (!open) {
            setEditingExpense(null);
            setExpenseEditError(null);
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit expense</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={expenseEditForm.handleSubmit((values) => {
              setExpenseEditError(null);
              editExpenseMutation.mutate(values);
            })}
            className="flex flex-col gap-4"
          >
            <div className="flex flex-col gap-1.5">
              <Label>Category</Label>
              <Select
                value={editCategory}
                onValueChange={(v) => {
                  setEditCategory(v);
                  expenseEditForm.setValue("category", v);
                }}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select a category" />
                </SelectTrigger>
                <SelectContent>
                  {EXPENSE_CATEGORIES.map((category) => (
                    <SelectItem key={category} value={category}>
                      {formatRole(category)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {expenseEditForm.formState.errors.category && (
                <p className="text-xs text-destructive">{expenseEditForm.formState.errors.category.message}</p>
              )}
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="edit-expense-description">Description</Label>
              <Input id="edit-expense-description" {...expenseEditForm.register("description")} />
              {expenseEditForm.formState.errors.description && (
                <p className="text-xs text-destructive">
                  {expenseEditForm.formState.errors.description.message}
                </p>
              )}
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-expense-amount">Amount</Label>
                <Input id="edit-expense-amount" type="number" step="any" {...expenseEditForm.register("amount")} />
                {expenseEditForm.formState.errors.amount && (
                  <p className="text-xs text-destructive">{expenseEditForm.formState.errors.amount.message}</p>
                )}
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-expense-date">Date</Label>
                <Input id="edit-expense-date" type="date" {...expenseEditForm.register("date")} />
                {expenseEditForm.formState.errors.date && (
                  <p className="text-xs text-destructive">{expenseEditForm.formState.errors.date.message}</p>
                )}
              </div>
            </div>
            {expenseEditError && <p className="text-sm text-destructive">{expenseEditError}</p>}
            <DialogFooter>
              <Button type="submit" disabled={editExpenseMutation.isPending}>
                {editExpenseMutation.isPending ? "Saving…" : "Save changes"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
