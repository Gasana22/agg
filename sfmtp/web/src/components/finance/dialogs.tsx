"use client";

import { useQuery } from "@tanstack/react-query";
import { Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { FormDialog, num, text } from "@/components/forms/form-dialog";
import { SubjectPicker } from "@/components/inventory/pickers";
import { Button } from "@/components/ui/button";
import { FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { api } from "@/lib/api/client";
import type { ApiError } from "@/lib/api/errors";
import { accountsOf, balanceDue, PAYABLE_LABEL, PAYMENT_METHODS, type Account, type Customer } from "@/lib/finance";
import { formatMoney } from "@/lib/inventory";
import type { Permissions } from "@/lib/permissions";

import { useAccounts, useCustomers } from "./queries";

type Base = { farmId: string; onClose: () => void; onDone: () => void };
const today = () => new Date().toISOString().slice(0, 10);
const center = (type: string, id: string) => (type === "general" ? { cost_center_type: null, cost_center_id: null } : { cost_center_type: type as "crop_cycle", cost_center_id: id || null });

export function AccountSelect({ id, name, label, accounts, error, placeholder = "Choose…", ...props }: React.SelectHTMLAttributes<HTMLSelectElement> & { label: string; accounts: Account[]; error?: string; placeholder?: string }) {
  return (
    <div>
      <Label htmlFor={id}>{label}</Label>
      <Select id={id} name={name} aria-invalid={!!error} {...props}>
        <option value="">{placeholder}</option>
        {accounts.map((a) => (
          <option key={a.id} value={a.id}>
            {a.code} {a.name}
          </option>
        ))}
      </Select>
      <FieldError>{error}</FieldError>
    </div>
  );
}

export function ExpenseDialog({ farmId, perms, canRecord, onClose, onDone }: Base & { perms: Permissions | undefined; canRecord: boolean }) {
  const accounts = useAccounts(farmId);
  const [ccType, setCcType] = useState("general");
  const [ccId, setCcId] = useState("");
  const [paid, setPaid] = useState(false);
  return (
    <FormDialog
      title={canRecord ? "Expense" : "Request an expense"}
      description={canRecord ? "Within the farm's threshold your own entries are posted at once; above it they wait for the owner." : "Finance approves it, or the owner above the farm's threshold."}
      submitLabel={canRecord ? "Record" : "Send request"}
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/expenses", {
          params: { path: { farm: farmId } },
          body: {
            account_id: String(f.get("account_id")),
            amount: Number(f.get("amount")),
            spent_on: String(f.get("spent_on")),
            payee: text(f, "payee"),
            description: String(f.get("description")),
            ...center(ccType, ccId),
            paid_from_account_id: paid ? text(f, "paid_from_account_id") : null,
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="description">What for</Label>
            <Input id="description" name="description" required minLength={3} placeholder="Diesel for the tractor" aria-invalid={!!error?.fieldError("description")} />
            <FieldError>{error?.fieldError("description")}</FieldError>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <AccountSelect id="account_id" name="account_id" label="Kind of expense" required accounts={accountsOf(accounts.data, ["expense"])} error={error?.fieldError("account_id")} />
            <div>
              <Label htmlFor="amount">Amount</Label>
              <Input id="amount" name="amount" type="number" step="any" min="0" required aria-invalid={!!error?.fieldError("amount")} />
              <FieldError>{error?.fieldError("amount")}</FieldError>
            </div>
            <div>
              <Label htmlFor="spent_on">Date</Label>
              <Input id="spent_on" name="spent_on" type="date" required defaultValue={today()} max={today()} />
            </div>
            <div>
              <Label htmlFor="payee">Paid to</Label>
              <Input id="payee" name="payee" placeholder="Shop, person or company" />
            </div>
          </div>
          <SubjectPicker farmId={farmId} perms={perms} type={ccType} onType={setCcType} id={ccId} onId={setCcId} error={error} />
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" className="size-4" checked={paid} onChange={(e) => setPaid(e.target.checked)} /> Already paid
          </label>
          {paid ? <AccountSelect id="paid_from_account_id" name="paid_from_account_id" label="Paid from" required accounts={accountsOf(accounts.data, ["asset"], { cash: true })} error={error?.fieldError("paid_from_account_id")} /> : null}
        </>
      )}
    </FormDialog>
  );
}

export function IncomeDialog({ farmId, perms, onClose, onDone }: Base & { perms: Permissions | undefined }) {
  const accounts = useAccounts(farmId);
  const [ccType, setCcType] = useState("general");
  const [ccId, setCcId] = useState("");
  return (
    <FormDialog
      title="Income"
      description="Money received without an invoice. Use an invoice when a customer pays later."
      submitLabel="Record"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/income", {
          params: { path: { farm: farmId } },
          body: {
            account_id: String(f.get("account_id")),
            received_into_account_id: String(f.get("received_into_account_id")),
            amount: Number(f.get("amount")),
            received_on: String(f.get("received_on")),
            payer: text(f, "payer"),
            description: String(f.get("description")),
            ...center(ccType, ccId),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="description">What for</Label>
            <Input id="description" name="description" required minLength={3} placeholder="Manure, 3 tractor loads" />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <AccountSelect id="account_id" name="account_id" label="Kind of income" required accounts={accountsOf(accounts.data, ["income"])} error={error?.fieldError("account_id")} />
            <AccountSelect id="received_into_account_id" name="received_into_account_id" label="Received into" required accounts={accountsOf(accounts.data, ["asset"], { cash: true })} error={error?.fieldError("received_into_account_id")} />
            <div>
              <Label htmlFor="amount">Amount</Label>
              <Input id="amount" name="amount" type="number" step="any" min="0" required />
            </div>
            <div>
              <Label htmlFor="received_on">Date</Label>
              <Input id="received_on" name="received_on" type="date" required defaultValue={today()} max={today()} />
            </div>
          </div>
          <div>
            <Label htmlFor="payer">From</Label>
            <Input id="payer" name="payer" />
          </div>
          <SubjectPicker farmId={farmId} perms={perms} type={ccType} onType={setCcType} id={ccId} onId={setCcId} error={error} />
        </>
      )}
    </FormDialog>
  );
}

type OpenDoc = { id: string; label: string; due: number; party?: string | null };

/** Open documents of a kind, with what is still due. */
function useOpenDocuments(farmId: string, type: string) {
  return useQuery({
    queryKey: ["open-documents", farmId, type],
    queryFn: async (): Promise<OpenDoc[]> => {
      const path = { farm: farmId };
      switch (type) {
        case "customer_invoice":
          return ((await api.GET("/farms/{farm}/customer-invoices", { params: { path, query: { "filter[status]": "issued", per_page: 200 } } })).data!.data ?? []).map((i) => ({
            id: i.id!,
            label: `${i.code} · ${i.customer?.name ?? ""}${i.due_on ? ` · due ${i.due_on}` : ""}`,
            due: balanceDue(i),
            party: i.customer?.name,
          }));
        case "supplier_invoice":
          return ((await api.GET("/farms/{farm}/supplier-invoices", { params: { path } })).data!.data ?? [])
            .filter((i) => i.status === "recorded")
            .map((i) => ({ id: i.id!, label: `${i.supplier?.name ?? ""} · ${i.invoice_number}${i.due_on ? ` · due ${i.due_on}` : ""}`, due: balanceDue(i), party: i.supplier?.name }));
        case "expense":
          return ((await api.GET("/farms/{farm}/expenses", { params: { path, query: { "filter[status]": "approved", per_page: 200 } } })).data!.data ?? []).map((e) => ({
            id: e.id!,
            label: `${e.code} · ${e.description}${e.payee ? ` (${e.payee})` : ""}`,
            due: balanceDue(e),
            party: e.payee,
          }));
        default:
          return ((await api.GET("/farms/{farm}/payroll-runs", { params: { path } })).data!.data ?? [])
            .filter((r) => r.status === "approved")
            .map((r) => ({ id: r.id!, label: `${r.code} · ${r.period_start} – ${r.period_end}`, due: Math.round(((r.total_net ?? 0) - (r.paid_amount ?? 0)) * 100) / 100 }));
      }
    },
  });
}

export function PaymentDialog({
  farmId,
  currency,
  types,
  preset,
  onClose,
  onDone,
}: Base & { currency: string; types: string[]; preset?: { type: string; id: string; label: string; due: number } }) {
  const accounts = useAccounts(farmId);
  const [type, setType] = useState(preset?.type ?? types[0]);
  const docs = useOpenDocuments(farmId, type);
  const [docId, setDocId] = useState(preset?.id ?? "");
  const doc = preset && preset.id === docId ? { id: preset.id, label: preset.label, due: preset.due } : docs.data?.find((d) => d.id === docId);
  const [amount, setAmount] = useState(preset ? String(preset.due) : "");
  const incoming = type === "customer_invoice";
  return (
    <FormDialog
      title={incoming ? "Customer payment" : "Payment"}
      description={incoming ? "Money received against an invoice." : "Money paid out against a supplier invoice, an expense or payroll."}
      submitLabel="Record payment"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/payments", {
          params: { path: { farm: farmId } },
          body: {
            payable_type: type as "expense",
            payable_id: docId,
            amount: Number(amount),
            paid_on: String(f.get("paid_on")),
            method: String(f.get("method")) as "cash",
            account_id: String(f.get("account_id")),
            reference: text(f, "reference"),
            note: text(f, "note"),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          {preset ? (
            <p className="rounded-lg bg-surface-muted px-3 py-2 text-sm">
              {PAYABLE_LABEL[preset.type]} {preset.label} · {formatMoney(preset.due, currency)} due
            </p>
          ) : (
            <>
              {types.length > 1 ? (
                <div>
                  <Label htmlFor="payable_type">For</Label>
                  <Select id="payable_type" value={type} onChange={(e) => { setType(e.target.value); setDocId(""); setAmount(""); }}>
                    {types.map((t) => (
                      <option key={t} value={t}>
                        {PAYABLE_LABEL[t]}
                      </option>
                    ))}
                  </Select>
                </div>
              ) : null}
              <div>
                <Label htmlFor="payable_id">{PAYABLE_LABEL[type]}</Label>
                <Select id="payable_id" required value={docId} onChange={(e) => { setDocId(e.target.value); setAmount(String(docs.data?.find((d) => d.id === e.target.value)?.due ?? "")); }} aria-invalid={!!error?.fieldError("payable_id")}>
                  <option value="">{docs.isLoading ? "Loading…" : docs.data?.length ? "Choose…" : "Nothing open to pay"}</option>
                  {(docs.data ?? []).map((d) => (
                    <option key={d.id} value={d.id}>
                      {d.label} · {formatMoney(d.due, currency)}
                    </option>
                  ))}
                </Select>
                <FieldError>{error?.fieldError("payable_id")}</FieldError>
              </div>
            </>
          )}
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="amount">Amount</Label>
              <Input id="amount" type="number" step="any" min="0" max={doc?.due} required value={amount} onChange={(e) => setAmount(e.target.value)} aria-invalid={!!error?.fieldError("amount")} />
              <FieldError>{error?.fieldError("amount")}</FieldError>
            </div>
            <div>
              <Label htmlFor="paid_on">Date</Label>
              <Input id="paid_on" name="paid_on" type="date" required defaultValue={today()} max={today()} />
            </div>
            <div>
              <Label htmlFor="method">Method</Label>
              <Select id="method" name="method" defaultValue="mobile_money">
                {PAYMENT_METHODS.map((m) => (
                  <option key={m.key} value={m.key}>
                    {m.label}
                  </option>
                ))}
              </Select>
            </div>
            <AccountSelect id="account_id" name="account_id" label={incoming ? "Into" : "From"} required accounts={accountsOf(accounts.data, ["asset"], { cash: true })} error={error?.fieldError("account_id")} />
          </div>
          <div>
            <Label htmlFor="reference">Reference</Label>
            <Input id="reference" name="reference" placeholder="Mobile money or bank reference" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function CustomerDialog({ farmId, customer, onClose, onDone }: Base & { customer?: Customer }) {
  return (
    <FormDialog
      title={customer ? `Edit ${customer.name}` : "New customer"}
      submitLabel={customer ? "Save" : "Add customer"}
      onClose={onClose}
      onSubmit={async (f) => {
        const body = {
          name: String(f.get("name")),
          contact_person: text(f, "contact_person"),
          phone: text(f, "phone"),
          email: text(f, "email"),
          address: text(f, "address"),
          tax_id: text(f, "tax_id"),
          payment_terms_days: num(f, "payment_terms_days"),
          notes: text(f, "notes"),
        };
        if (customer) {
          await api.PATCH("/farms/{farm}/customers/{customer}", { params: { path: { farm: farmId, customer: customer.id! } }, body: { ...body, is_active: f.get("is_active") === "on", version: customer.version } });
        } else {
          await api.POST("/farms/{farm}/customers", { params: { path: { farm: farmId } }, body });
        }
        onDone();
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="name">Name</Label>
            <Input id="name" name="name" required minLength={2} defaultValue={customer?.name} aria-invalid={!!error?.fieldError("name")} />
            <FieldError>{error?.fieldError("name")}</FieldError>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="contact_person">Contact person</Label>
              <Input id="contact_person" name="contact_person" defaultValue={customer?.contact_person ?? ""} />
            </div>
            <div>
              <Label htmlFor="phone">Phone</Label>
              <Input id="phone" name="phone" type="tel" defaultValue={customer?.phone ?? ""} />
            </div>
            <div>
              <Label htmlFor="email">Email</Label>
              <Input id="email" name="email" type="email" defaultValue={customer?.email ?? ""} aria-invalid={!!error?.fieldError("email")} />
            </div>
            <div>
              <Label htmlFor="payment_terms_days">Pays in (days)</Label>
              <Input id="payment_terms_days" name="payment_terms_days" type="number" min="0" max="365" defaultValue={customer?.payment_terms_days ?? ""} />
            </div>
            <div>
              <Label htmlFor="tax_id">Tax ID (TIN)</Label>
              <Input id="tax_id" name="tax_id" defaultValue={customer?.tax_id ?? ""} />
            </div>
            <div>
              <Label htmlFor="address">Address</Label>
              <Input id="address" name="address" defaultValue={customer?.address ?? ""} />
            </div>
          </div>
          {customer ? (
            <label className="inline-flex items-center gap-2 text-sm">
              <input type="checkbox" name="is_active" defaultChecked={customer.is_active} className="size-4" /> Active
            </label>
          ) : null}
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" defaultValue={customer?.notes ?? ""} className="min-h-16" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

type InvLine = { key: string; description: string; quantity: string; unit: string; unit_price: string; account_id: string; animal_sale_id: string };
const blankLine = (account = ""): InvLine => ({ key: crypto.randomUUID(), description: "", quantity: "1", unit: "", unit_price: "", account_id: account, animal_sale_id: "" });

export function InvoiceDialog({ farmId, currency, onClose, onDone }: Omit<Base, "onDone"> & { currency: string; onDone: (id: string) => void }) {
  const accounts = useAccounts(farmId);
  const customers = useCustomers(farmId);
  const income = accountsOf(accounts.data, ["income"]);
  const sales = useQuery({
    queryKey: ["billable-sales", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/customer-invoices/billable/livestock-sales", { params: { path: { farm: farmId } } })).data!.data!,
  });
  const [lines, setLines] = useState<InvLine[]>([blankLine()]);
  const set = (key: string, patch: Partial<InvLine>) => setLines(lines.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const total = lines.reduce((s, l) => s + Math.round(Number(l.quantity || 0) * Number(l.unit_price || 0) * 100), 0) / 100;
  const addSale = (id: string) => {
    const s = sales.data?.find((x) => x.id === id);
    if (!s) return;
    const account = income.find((a) => a.code === "4200")?.id ?? income[0]?.id ?? "";
    setLines([...lines.filter((l) => l.description || l.unit_price || l.animal_sale_id), { ...blankLine(account), animal_sale_id: s.id!, unit: "head", unit_price: String(s.sale_price ?? ""), description: `Sale ${s.code}: ${s.animal?.animal_code}${s.animal?.tag_number ? ` (tag ${s.animal.tag_number})` : ""}` }]);
  };

  return (
    <FormDialog
      title="New invoice"
      description="Saved as a draft; nothing is posted until you issue it."
      submitLabel="Save draft"
      onClose={onClose}
      onSubmit={async (f) => {
        const res = await api.POST("/farms/{farm}/customer-invoices", {
          params: { path: { farm: farmId } },
          body: {
            customer_id: String(f.get("customer_id")),
            invoice_date: String(f.get("invoice_date")),
            due_on: text(f, "due_on"),
            notes: text(f, "notes"),
            lines: lines.map((l) => ({
              description: l.description || null,
              quantity: Number(l.quantity),
              unit: l.unit || null,
              unit_price: Number(l.unit_price),
              account_id: l.account_id,
              animal_sale_id: l.animal_sale_id || null,
            })),
          },
        });
        onDone(res.data!.data!.id!);
      }}
    >
      {(error) => (
        <>
          <div className="grid grid-cols-3 gap-3">
            <div className="col-span-3 sm:col-span-1">
              <Label htmlFor="customer_id">Customer</Label>
              <Select id="customer_id" name="customer_id" required aria-invalid={!!error?.fieldError("customer_id")}>
                <option value="">Choose…</option>
                {(customers.data ?? []).map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </Select>
              <FieldError>{error?.fieldError("customer_id")}</FieldError>
            </div>
            <div>
              <Label htmlFor="invoice_date">Date</Label>
              <Input id="invoice_date" name="invoice_date" type="date" required defaultValue={today()} max={today()} />
            </div>
            <div>
              <Label htmlFor="due_on">Due</Label>
              <Input id="due_on" name="due_on" type="date" />
            </div>
          </div>
          {(sales.data ?? []).length > 0 ? (
            <div>
              <Label htmlFor="sale">Add a livestock sale</Label>
              <Select id="sale" value="" onChange={(e) => addSale(e.target.value)}>
                <option value="">{sales.data!.length} sold, not yet invoiced…</option>
                {sales.data!.filter((s) => !lines.some((l) => l.animal_sale_id === s.id)).map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.code} · {s.animal?.animal_code} · {formatMoney(s.sale_price, currency)}
                  </option>
                ))}
              </Select>
            </div>
          ) : null}
          <fieldset className="space-y-2">
            <legend className="mb-1.5 text-sm font-medium">Lines</legend>
            {lines.map((l, i) => (
              <div key={l.key} className="space-y-1.5 rounded-lg border border-border p-2">
                <div className="flex gap-2">
                  <Input aria-label={`Description ${i + 1}`} placeholder="Description" value={l.description} onChange={(e) => set(l.key, { description: e.target.value })} required={!l.animal_sale_id} className="min-w-0 flex-1" />
                  <Button type="button" variant="ghost" size="icon" aria-label={`Remove line ${i + 1}`} disabled={lines.length === 1} onClick={() => setLines(lines.filter((x) => x.key !== l.key))}>
                    <Trash2 />
                  </Button>
                </div>
                <div className="grid grid-cols-4 gap-2">
                  <Input aria-label={`Quantity ${i + 1}`} type="number" step="any" min="0" required value={l.quantity} onChange={(e) => set(l.key, { quantity: e.target.value })} />
                  <Input aria-label={`Unit ${i + 1}`} placeholder="Unit" value={l.unit} onChange={(e) => set(l.key, { unit: e.target.value })} />
                  <Input aria-label={`Unit price ${i + 1}`} type="number" step="any" min="0" required placeholder="Price" value={l.unit_price} onChange={(e) => set(l.key, { unit_price: e.target.value })} />
                  <Select aria-label={`Income account ${i + 1}`} required value={l.account_id} onChange={(e) => set(l.key, { account_id: e.target.value })}>
                    <option value="">Account…</option>
                    {income.map((a) => (
                      <option key={a.id} value={a.id}>
                        {a.code} {a.name}
                      </option>
                    ))}
                  </Select>
                </div>
                <FieldError>{error?.fieldError(`lines.${i}.description`) ?? error?.fieldError(`lines.${i}.account_id`) ?? error?.fieldError(`lines.${i}.animal_sale_id`)}</FieldError>
              </div>
            ))}
            <Button type="button" variant="secondary" size="sm" onClick={() => setLines([...lines, blankLine(lines[lines.length - 1]?.account_id)])}>
              <Plus /> Add line
            </Button>
          </fieldset>
          <p className="text-right text-sm">
            Total <strong className="tabular-nums">{formatMoney(total, currency)}</strong>
          </p>
          <div>
            <Label htmlFor="notes">Notes on the invoice</Label>
            <Input id="notes" name="notes" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function PayrollDialog({ farmId, onClose, onDone }: Omit<Base, "onDone"> & { onDone: (id: string) => void }) {
  const d = new Date();
  const lastWeek = new Date(d.getTime() - 7 * 86400000).toISOString().slice(0, 10);
  const yesterday = new Date(d.getTime() - 86400000).toISOString().slice(0, 10);
  return (
    <FormDialog
      title="Prepare payroll"
      description="Days worked come from attendance at each worker's daily rate; wages are charged to the work verified in the period."
      submitLabel="Prepare"
      onClose={onClose}
      onSubmit={async (f) => {
        const res = await api.POST("/farms/{farm}/payroll-runs", {
          params: { path: { farm: farmId } },
          body: { period_start: String(f.get("period_start")), period_end: String(f.get("period_end")), notes: text(f, "notes") },
        });
        onDone(res.data!.data!.id!);
      }}
    >
      {(error) => (
        <>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="period_start">From</Label>
              <Input id="period_start" name="period_start" type="date" required defaultValue={lastWeek} />
            </div>
            <div>
              <Label htmlFor="period_end">To</Label>
              <Input id="period_end" name="period_end" type="date" required defaultValue={yesterday} max={today()} aria-invalid={!!error?.fieldError("period_end")} />
              <FieldError>{error?.fieldError("period_end")}</FieldError>
            </div>
          </div>
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Input id="notes" name="notes" placeholder="Weekly casual wages" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

type BLine = { key: string; account_id: string; amount: string };

export function BudgetDialog({ farmId, perms, onClose, onDone }: Omit<Base, "onDone"> & { perms: Permissions | undefined; onDone: (id: string) => void }) {
  const accounts = useAccounts(farmId);
  const usable = accountsOf(accounts.data, ["expense", "income"]);
  const [ccType, setCcType] = useState("general");
  const [ccId, setCcId] = useState("");
  const [lines, setLines] = useState<BLine[]>([{ key: crypto.randomUUID(), account_id: "", amount: "" }]);
  const now = new Date();
  const q = Math.floor(now.getMonth() / 3);
  const start = new Date(Date.UTC(now.getFullYear(), q * 3, 1)).toISOString().slice(0, 10);
  const end = new Date(Date.UTC(now.getFullYear(), q * 3 + 3, 0)).toISOString().slice(0, 10);
  return (
    <FormDialog
      title="New budget"
      description="Planned amounts per account. Actual figures come from the books."
      submitLabel="Create budget"
      onClose={onClose}
      onSubmit={async (f) => {
        const res = await api.POST("/farms/{farm}/budgets", {
          params: { path: { farm: farmId } },
          body: {
            name: String(f.get("name")),
            period_start: String(f.get("period_start")),
            period_end: String(f.get("period_end")),
            scope_type: ccType === "general" ? null : (ccType as "crop_cycle"),
            scope_id: ccType === "general" ? null : ccId,
            lines: lines.filter((l) => l.account_id).map((l) => ({ account_id: l.account_id, amount: Number(l.amount || 0) })),
          },
        });
        onDone(res.data!.data!.id!);
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="name">Name</Label>
            <Input id="name" name="name" required minLength={2} placeholder="Maize season B" />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="period_start">From</Label>
              <Input id="period_start" name="period_start" type="date" required defaultValue={start} />
            </div>
            <div>
              <Label htmlFor="period_end">To</Label>
              <Input id="period_end" name="period_end" type="date" required defaultValue={end} />
            </div>
          </div>
          <SubjectPicker farmId={farmId} perms={perms} type={ccType} onType={setCcType} id={ccId} onId={setCcId} error={error} />
          <fieldset className="space-y-2">
            <legend className="mb-1.5 text-sm font-medium">Lines</legend>
            {lines.map((l, i) => (
              <div key={l.key} className="flex gap-2">
                <Select aria-label={`Account ${i + 1}`} className="min-w-0 flex-1" required value={l.account_id} onChange={(e) => setLines(lines.map((x) => (x.key === l.key ? { ...x, account_id: e.target.value } : x)))}>
                  <option value="">Account…</option>
                  {usable.map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.code} {a.name}
                    </option>
                  ))}
                </Select>
                <Input aria-label={`Amount ${i + 1}`} type="number" step="any" min="0" required className="w-36" value={l.amount} onChange={(e) => setLines(lines.map((x) => (x.key === l.key ? { ...x, amount: e.target.value } : x)))} />
                <Button type="button" variant="ghost" size="icon" aria-label={`Remove line ${i + 1}`} disabled={lines.length === 1} onClick={() => setLines(lines.filter((x) => x.key !== l.key))}>
                  <Trash2 />
                </Button>
              </div>
            ))}
            <Button type="button" variant="secondary" size="sm" onClick={() => setLines([...lines, { key: crypto.randomUUID(), account_id: "", amount: "" }])}>
              <Plus /> Add line
            </Button>
            <FieldError>{error?.fieldError("lines") ?? error?.fieldError("lines.0.account_id")}</FieldError>
          </fieldset>
        </>
      )}
    </FormDialog>
  );
}

export function AccountDialog({ farmId, onClose, onDone }: Base) {
  const [type, setType] = useState("expense");
  const digit: Record<string, string> = { asset: "1", liability: "2", equity: "3", income: "4", expense: "5" };
  return (
    <FormDialog
      title="New account"
      description="Codes are four digits and start with the type's digit: 1 assets, 2 liabilities, 3 equity, 4 income, 5 expenses."
      submitLabel="Add account"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/ledger/accounts", {
          params: { path: { farm: farmId } },
          body: { code: String(f.get("code")), name: String(f.get("name")), type: type as "asset", description: text(f, "description"), is_cash: type === "asset" && f.get("is_cash") === "on" },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label htmlFor="type">Type</Label>
              <Select id="type" value={type} onChange={(e) => setType(e.target.value)}>
                {Object.keys(digit).map((t) => (
                  <option key={t} value={t}>
                    {t[0].toUpperCase() + t.slice(1)}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="code">Code</Label>
              <Input id="code" name="code" required pattern={`${digit[type]}[0-9]{3}`} placeholder={`${digit[type]}…`} aria-invalid={!!error?.fieldError("code")} />
              <FieldError>{error?.fieldError("code")}</FieldError>
            </div>
            <div>
              <Label htmlFor="name">Name</Label>
              <Input id="name" name="name" required minLength={2} />
            </div>
          </div>
          {type === "asset" ? (
            <label className="inline-flex items-center gap-2 text-sm">
              <input type="checkbox" name="is_cash" className="size-4" /> Holds money (cash, mobile money or bank)
            </label>
          ) : null}
          <div>
            <Label htmlFor="description">Description</Label>
            <Input id="description" name="description" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

type JLine = { key: string; account_id: string; debit: string; credit: string };

export function JournalDialog({ farmId, currency, onClose, onDone }: Base & { currency: string }) {
  const accounts = useAccounts(farmId);
  const usable = (accounts.data ?? []).filter((a) => a.is_active !== false && !a.is_control);
  const [lines, setLines] = useState<JLine[]>([
    { key: crypto.randomUUID(), account_id: "", debit: "", credit: "" },
    { key: crypto.randomUUID(), account_id: "", debit: "", credit: "" },
  ]);
  const set = (key: string, patch: Partial<JLine>) => setLines(lines.map((l) => (l.key === key ? { ...l, ...patch } : l)));
  const sum = (k: "debit" | "credit") => lines.reduce((s, l) => s + Math.round(Number(l[k] || 0) * 100), 0) / 100;
  const balanced = sum("debit") === sum("credit") && sum("debit") > 0;
  return (
    <FormDialog
      title="Journal entry"
      description="For what no document covers: capital paid in, a loan, moving money between accounts. Stock, customers, suppliers and wages are kept by their documents."
      submitLabel="Post entry"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/ledger/entries", {
          params: { path: { farm: farmId } },
          body: {
            posted_on: String(f.get("posted_on")),
            memo: String(f.get("memo")),
            lines: lines.filter((l) => l.account_id).map((l) => ({ account_id: l.account_id, debit: l.debit ? Number(l.debit) : null, credit: l.credit ? Number(l.credit) : null })),
          },
        });
        onDone();
      }}
    >
      {(error: ApiError | null) => (
        <>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label htmlFor="posted_on">Date</Label>
              <Input id="posted_on" name="posted_on" type="date" required defaultValue={today()} max={today()} />
            </div>
            <div className="col-span-2">
              <Label htmlFor="memo">Memo</Label>
              <Input id="memo" name="memo" required minLength={3} placeholder="Owner capital paid in" />
            </div>
          </div>
          <div className="space-y-2">
            {lines.map((l, i) => (
              <div key={l.key} className="flex gap-2">
                <Select aria-label={`Account ${i + 1}`} className="min-w-0 flex-1" value={l.account_id} onChange={(e) => set(l.key, { account_id: e.target.value })}>
                  <option value="">Account…</option>
                  {usable.map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.code} {a.name}
                    </option>
                  ))}
                </Select>
                <Input aria-label={`Debit ${i + 1}`} type="number" step="any" min="0" placeholder="Debit" className="w-28" value={l.debit} onChange={(e) => set(l.key, { debit: e.target.value, credit: e.target.value ? "" : l.credit })} />
                <Input aria-label={`Credit ${i + 1}`} type="number" step="any" min="0" placeholder="Credit" className="w-28" value={l.credit} onChange={(e) => set(l.key, { credit: e.target.value, debit: e.target.value ? "" : l.debit })} />
              </div>
            ))}
            <Button type="button" variant="secondary" size="sm" onClick={() => setLines([...lines, { key: crypto.randomUUID(), account_id: "", debit: "", credit: "" }])}>
              <Plus /> Add line
            </Button>
          </div>
          <p className={`text-right text-sm ${balanced ? "text-success" : "text-muted"}`}>
            Debits {formatMoney(sum("debit"), currency)} · Credits {formatMoney(sum("credit"), currency)}
          </p>
          <FieldError>{error?.fieldError("lines") ?? error?.fieldError("lines.0.account_id") ?? error?.fieldError("lines.1.account_id")}</FieldError>
        </>
      )}
    </FormDialog>
  );
}
