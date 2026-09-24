"use client";

import { useInfiniteQuery, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import Link from "next/link";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { TabBar, useActions } from "@/components/inventory/common";
import { ReasonDialog } from "@/components/inventory/dialogs";
import { useFarmCurrency, useSuppliers } from "@/components/inventory/queries";
import { OrderDialog, PurchaseRequestDialog, SupplierDialog } from "@/components/procurement/dialogs";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { formatDateTime } from "@/lib/format";
import { formatMoney, formatQty, ORDER_STATUS, PURCHASE_REQUEST_STATUS, RECEIVABLE, type PurchaseRequest, type Supplier } from "@/lib/inventory";
import { can } from "@/lib/permissions";

const TABS = [
  { key: "orders", label: "Orders", permission: "procurement.orders.manage|procurement.orders.approve|procurement.deliveries.receive" },
  { key: "requests", label: "Requests", permission: "procurement.requests.create|procurement.requests.approve|procurement.orders.manage" },
  { key: "suppliers", label: "Suppliers", permission: "suppliers.view" },
  { key: "invoices", label: "Invoices", permission: "procurement.orders.manage|finance.view" },
] as const;

export default function ProcurementPage() {
  return (
    <Suspense>
      <Procurement />
    </Suspense>
  );
}

function Procurement() {
  const { farmId } = useParams<{ farmId: string }>();
  const search = useSearchParams();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const visible = TABS.filter((t) => can(perms, t.permission));
  const tab = visible.find((t) => t.key === search.get("tab"))?.key ?? visible[0]?.key;
  const currency = useFarmCurrency(farmId);
  const writable = workspace?.type === "farm";
  const seesPrices = can(perms, "procurement.orders.manage|finance.values.view");

  return (
    <>
      <PageHeader title="Purchasing" description="From a request to an approved order, the delivery into stock, and the supplier's invoice." />
      <TabBar base={`/farms/${farmId}/procurement`} tabs={visible} active={tab ?? ""} label="Purchasing sections" />
      {tab === "orders" ? <OrdersPanel farmId={farmId} canBuy={writable && can(perms, "procurement.orders.manage")} seesPrices={seesPrices} currency={currency} initialStatus={search.get("status") ?? "open"} /> : null}
      {tab === "requests" ? (
        <RequestsPanel
          farmId={farmId}
          canRequest={writable && can(perms, "procurement.requests.create")}
          canApprove={writable && can(perms, "procurement.requests.approve")}
          startNew={search.get("new") === "1"}
        />
      ) : null}
      {tab === "suppliers" ? <SuppliersPanel farmId={farmId} canManage={writable && can(perms, "suppliers.manage")} /> : null}
      {tab === "invoices" ? <InvoicesPanel farmId={farmId} currency={currency} /> : null}
    </>
  );
}

function OrdersPanel({ farmId, canBuy, seesPrices, currency, initialStatus }: { farmId: string; canBuy: boolean; seesPrices: boolean; currency: string; initialStatus: string }) {
  const router = useRouter();
  const [status, setStatus] = useState(initialStatus);
  const [creating, setCreating] = useState(false);
  const statuses = status === "open" ? ["draft", ...RECEIVABLE].join(",") : status === "all" ? undefined : status;
  const orders = useInfiniteQuery({
    queryKey: ["purchase-orders", farmId, status],
    queryFn: async ({ pageParam }) =>
      (await api.GET("/farms/{farm}/purchase-orders", { params: { path: { farm: farmId }, query: { "filter[status]": statuses, cursor: pageParam ?? undefined, per_page: 50 } } })).data!,
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta?.next_cursor ?? null,
  });
  const rows = orders.data?.pages.flatMap((p) => p.data ?? []) ?? [];

  return (
    <>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <Select aria-label="Status" className="h-9 w-48" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="open">Open</option>
          {Object.entries(ORDER_STATUS).map(([k, v]) => (
            <option key={k} value={k}>
              {v.label}
            </option>
          ))}
          <option value="all">All</option>
        </Select>
        {canBuy ? (
          <Button size="sm" onClick={() => setCreating(true)}>
            <Plus /> New order
          </Button>
        ) : null}
      </div>
      {orders.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : orders.error ? (
        <ErrorNotice error={orders.error} />
      ) : rows.length === 0 ? (
        <EmptyState title="No purchase orders here" />
      ) : (
        <>
          <Table>
            <thead>
              <tr>
                <Th>Order</Th>
                <Th>Supplier</Th>
                <Th>Items</Th>
                <Th>Expected</Th>
                {seesPrices ? <Th className="text-right">Total</Th> : null}
                <Th>Status</Th>
              </tr>
            </thead>
            <tbody>
              {rows.map((o) => {
                const st = ORDER_STATUS[o.status ?? ""];
                return (
                  <tr key={o.id} className="hover:bg-surface-muted/50">
                    <Td>
                      <Link href={`/farms/${farmId}/procurement/orders/${o.id}`} className="font-medium text-primary hover:underline">
                        {o.code}
                      </Link>
                      <span className="block text-xs text-muted">{formatDateTime(o.created_at)}</span>
                    </Td>
                    <Td>{o.supplier?.name}</Td>
                    <Td className="text-muted">
                      {(o.lines ?? []).map((l) => (
                        <span key={l.id} className="block">
                          {formatQty(l.quantity, l.item?.unit)} {l.item?.name}
                        </span>
                      ))}
                    </Td>
                    <Td className="text-muted">{o.expected_on ?? "—"}</Td>
                    {seesPrices ? <Td className="text-right tabular-nums">{formatMoney(o.total_amount, o.currency)}</Td> : null}
                    <Td>
                      <Badge tone={st?.tone ?? "neutral"}>{st?.label ?? o.status}</Badge>
                    </Td>
                  </tr>
                );
              })}
            </tbody>
          </Table>
          {orders.hasNextPage ? (
            <div className="mt-3 text-center">
              <Button variant="secondary" size="sm" onClick={() => orders.fetchNextPage()}>
                Load more
              </Button>
            </div>
          ) : null}
        </>
      )}
      {creating ? (
        <OrderDialog
          farmId={farmId}
          currency={currency}
          onClose={() => setCreating(false)}
          onDone={() => {
            setCreating(false);
            router.push(`/farms/${farmId}/procurement?tab=orders&status=draft`);
            orders.refetch();
          }}
        />
      ) : null}
    </>
  );
}

function RequestsPanel({ farmId, canRequest, canApprove, startNew }: { farmId: string; canRequest: boolean; canApprove: boolean; startNew: boolean }) {
  const queryClient = useQueryClient();
  const [show, setShow] = useState("open");
  const [creating, setCreating] = useState(startNew && canRequest);
  const [rejecting, setRejecting] = useState<PurchaseRequest | null>(null);
  const requests = useQuery({
    queryKey: ["purchase-requests", farmId, show],
    queryFn: async () =>
      (await api.GET("/farms/{farm}/purchase-requests", { params: { path: { farm: farmId }, query: { "filter[status]": show === "open" ? "submitted,approved" : undefined } } })).data!.data!,
  });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["purchase-requests", farmId] });
  const { error, busy, run } = useActions(refresh);
  const path = (r: PurchaseRequest) => ({ path: { farm: farmId, purchaseRequest: r.id! } });

  return (
    <>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <Select aria-label="Show" className="h-9 w-40" value={show} onChange={(e) => setShow(e.target.value)}>
          <option value="open">Open</option>
          <option value="all">All</option>
        </Select>
        {canRequest ? (
          <Button size="sm" onClick={() => setCreating(true)}>
            <Plus /> Purchase request
          </Button>
        ) : null}
      </div>
      {error ? <div className="mb-3"><ErrorNotice error={error} /></div> : null}
      {requests.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : requests.error ? (
        <ErrorNotice error={requests.error} />
      ) : requests.data!.length === 0 ? (
        <EmptyState title="No purchase requests" />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Request</Th>
              <Th>Items</Th>
              <Th>Needed by</Th>
              <Th>Status</Th>
              <Th className="text-right">Actions</Th>
            </tr>
          </thead>
          <tbody>
            {requests.data!.map((r) => {
              const st = PURCHASE_REQUEST_STATUS[r.status ?? ""];
              return (
                <tr key={r.id}>
                  <Td>
                    <span className="font-medium">{r.code}</span>
                    <span className="block text-xs text-muted">
                      {r.requested_by?.name} · {formatDateTime(r.created_at)}
                    </span>
                    {r.reason ? <span className="block text-xs text-muted">{r.reason}</span> : null}
                  </Td>
                  <Td>
                    {(r.lines ?? []).map((l) => (
                      <span key={l.id} className="block">
                        {formatQty(l.quantity, l.item?.unit ?? l.unit)} {l.item?.name ?? l.description}
                      </span>
                    ))}
                  </Td>
                  <Td className="text-muted">{r.needed_by ?? "—"}</Td>
                  <Td>
                    <Badge tone={st?.tone ?? "neutral"}>{st?.label ?? r.status}</Badge>
                    {r.decision_note ? <span className="mt-1 block text-xs text-muted">{r.decision_note}</span> : null}
                  </Td>
                  <Td className="text-right">
                    <div className="flex flex-wrap justify-end gap-2">
                      {canApprove && r.status === "submitted" ? (
                        <>
                          <Button size="sm" disabled={busy === r.id} onClick={() => run(r.id!, () => api.POST("/farms/{farm}/purchase-requests/{purchaseRequest}/approve", { params: path(r), body: {} }))}>
                            Approve
                          </Button>
                          <Button size="sm" variant="secondary" onClick={() => setRejecting(r)}>
                            Reject
                          </Button>
                        </>
                      ) : null}
                      {r.status === "submitted" || r.status === "approved" ? (
                        <Button size="sm" variant="ghost" disabled={busy === r.id} onClick={() => run(r.id!, () => api.POST("/farms/{farm}/purchase-requests/{purchaseRequest}/cancel", { params: path(r) }))}>
                          Cancel
                        </Button>
                      ) : null}
                    </div>
                  </Td>
                </tr>
              );
            })}
          </tbody>
        </Table>
      )}
      {creating ? <PurchaseRequestDialog farmId={farmId} onClose={() => setCreating(false)} onDone={async () => { setCreating(false); await refresh(); }} /> : null}
      {rejecting ? (
        <ReasonDialog
          title={`Reject ${rejecting.code}`}
          submitLabel="Reject"
          onClose={() => setRejecting(null)}
          onSubmit={async (note) => {
            await api.POST("/farms/{farm}/purchase-requests/{purchaseRequest}/reject", { params: path(rejecting), body: { note } });
            setRejecting(null);
            await refresh();
          }}
        />
      ) : null}
    </>
  );
}

function SuppliersPanel({ farmId, canManage }: { farmId: string; canManage: boolean }) {
  const queryClient = useQueryClient();
  const suppliers = useSuppliers(farmId);
  const [editing, setEditing] = useState<Supplier | "new" | null>(null);
  return (
    <>
      {canManage ? (
        <div className="mb-3 flex justify-end">
          <Button size="sm" onClick={() => setEditing("new")}>
            <Plus /> New supplier
          </Button>
        </div>
      ) : null}
      {suppliers.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : suppliers.error ? (
        <ErrorNotice error={suppliers.error} />
      ) : suppliers.data!.length === 0 ? (
        <EmptyState title="No suppliers yet" />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Supplier</Th>
              <Th>Contact</Th>
              <Th>Terms</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {suppliers.data!.map((s) => (
              <tr key={s.id}>
                <Td>
                  <span className="font-medium">{s.name}</span>
                  <span className="block text-xs text-muted">
                    {s.code}
                    {s.tax_id ? ` · TIN ${s.tax_id}` : ""}
                  </span>
                </Td>
                <Td className="text-muted">
                  {[s.contact_person, s.phone, s.email].filter(Boolean).join(" · ") || "—"}
                  {s.address ? <span className="block text-xs">{s.address}</span> : null}
                </Td>
                <Td className="text-muted">{s.payment_terms_days != null ? `${s.payment_terms_days} days` : "—"}</Td>
                <Td className="text-right">
                  {canManage ? (
                    <Button size="sm" variant="ghost" onClick={() => setEditing(s)}>
                      Edit
                    </Button>
                  ) : null}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {editing ? (
        <SupplierDialog
          farmId={farmId}
          supplier={editing === "new" ? undefined : editing}
          onClose={() => setEditing(null)}
          onDone={async () => {
            setEditing(null);
            await queryClient.invalidateQueries({ queryKey: ["suppliers", farmId] });
          }}
        />
      ) : null}
    </>
  );
}

function InvoicesPanel({ farmId, currency }: { farmId: string; currency: string }) {
  const invoices = useQuery({
    queryKey: ["supplier-invoices", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/supplier-invoices", { params: { path: { farm: farmId } } })).data!.data!,
  });
  if (invoices.isLoading) return <Skeleton className="h-40 w-full" />;
  if (invoices.error) return <ErrorNotice error={invoices.error} />;
  if (invoices.data!.length === 0) return <EmptyState title="No supplier invoices yet">Record them from the purchase order once goods are received.</EmptyState>;
  return (
    <Table>
      <thead>
        <tr>
          <Th>Invoice</Th>
          <Th>Supplier</Th>
          <Th>Order</Th>
          <Th>Due</Th>
          <Th className="text-right">Amount</Th>
          <Th>Status</Th>
        </tr>
      </thead>
      <tbody>
        {invoices.data!.map((i) => (
          <tr key={i.id}>
            <Td>
              <span className="font-medium">{i.invoice_number}</span>
              <span className="block text-xs text-muted">
                {i.code} · {i.invoice_date}
              </span>
            </Td>
            <Td>{i.supplier?.name}</Td>
            <Td>
              <Link href={`/farms/${farmId}/procurement/orders/${i.order?.id}`} className="text-primary hover:underline">
                {i.order?.code}
              </Link>
            </Td>
            <Td className="text-muted">{i.due_on ?? "—"}</Td>
            <Td className="text-right tabular-nums">{formatMoney(i.amount, currency)}</Td>
            <Td>
              <Badge tone={i.status === "paid" ? "success" : i.status === "cancelled" ? "neutral" : "warning"}>{i.status === "recorded" ? "Unpaid" : i.status}</Badge>
            </Td>
          </tr>
        ))}
      </tbody>
    </Table>
  );
}
