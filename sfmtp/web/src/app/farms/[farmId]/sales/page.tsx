"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { NewOrderDialog, ProductDialog, useProducts } from "@/components/sales/dialogs";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { formatDateTime } from "@/lib/format";
import { formatMoney, formatQty } from "@/lib/inventory";
import { can } from "@/lib/permissions";
import { SALES_ORDER_STATUS, type Product } from "@/lib/portal";
import { cn } from "@/lib/utils";

const FILTERS: Record<string, string | undefined> = { open: "requested,approved,invoiced,dispatched", requested: "requested", delivered: "delivered", closed: "rejected,cancelled", all: undefined };

export default function SalesPage() {
  const { farmId } = useParams<{ farmId: string }>();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const writable = workspace?.type === "farm";
  const canPrice = writable && can(perms, "sales.pricing.manage");
  const canOrder = writable && can(perms, "sales.orders.create");
  const seesProducts = can(perms, "sales.view|sales.orders.create|sales.pricing.manage");
  const [tab, setTab] = useState<"orders" | "products">("orders");
  const [filter, setFilter] = useState("open");
  const [dialog, setDialog] = useState<null | "order" | "product" | { edit: Product }>(null);
  const queryClient = useQueryClient();
  const orders = useQuery({
    queryKey: ["sales-orders", farmId, filter],
    queryFn: async () => (await api.GET("/farms/{farm}/sales-orders", { params: { path: { farm: farmId }, query: { "filter[status]": FILTERS[filter] } } })).data!.data ?? [],
  });
  const products = useProducts(farmId, seesProducts && tab === "products");
  const done = async () => {
    setDialog(null);
    await queryClient.invalidateQueries({ predicate: (q) => ["sales-orders", "products"].includes(String(q.queryKey[0])) });
  };

  return (
    <>
      <PageHeader
        title="Sales"
        description="Orders from the customer portal and from staff, and the products customers can order."
        actions={
          <div className="flex gap-2">
            {canPrice ? (
              <Button variant="secondary" onClick={() => setDialog("product")}>
                <Plus /> Product
              </Button>
            ) : null}
            {canOrder ? (
              <Button onClick={() => setDialog("order")}>
                <Plus /> Order
              </Button>
            ) : null}
          </div>
        }
      />
      {seesProducts ? (
        <div role="tablist" className="mb-4 flex gap-1 border-b border-border">
          {(["orders", "products"] as const).map((t) => (
            <button
              key={t}
              role="tab"
              aria-selected={tab === t}
              onClick={() => setTab(t)}
              className={cn("-mb-px border-b-2 px-3 py-2 text-sm font-medium", tab === t ? "border-primary text-primary" : "border-transparent text-muted hover:text-foreground")}
            >
              {t === "orders" ? "Orders" : "Products"}
            </button>
          ))}
        </div>
      ) : null}

      {tab === "orders" ? (
        <>
          <Select aria-label="Status" className="mb-4 w-52" value={filter} onChange={(e) => setFilter(e.target.value)}>
            <option value="open">Open</option>
            <option value="requested">Waiting for approval</option>
            <option value="delivered">Delivered</option>
            <option value="closed">Declined or cancelled</option>
            <option value="all">All</option>
          </Select>
          {orders.isLoading ? (
            <Skeleton className="h-64 w-full" />
          ) : orders.error ? (
            <ErrorNotice error={orders.error} />
          ) : (orders.data ?? []).length === 0 ? (
            <EmptyState title="No orders here">Orders placed in the customer portal, or recorded here, appear in this list.</EmptyState>
          ) : (
            <Table>
              <thead>
                <tr>
                  <Th>Order</Th>
                  <Th>Customer</Th>
                  <Th>Items</Th>
                  <Th className="text-right">Total</Th>
                  <Th>Status</Th>
                </tr>
              </thead>
              <tbody>
                {orders.data!.map((o) => {
                  const st = SALES_ORDER_STATUS[o.status ?? ""] ?? { label: o.status, tone: "neutral" as const };
                  return (
                    <tr key={o.id}>
                      <Td>
                        <Link href={`/farms/${farmId}/sales/${o.id}`} className="font-medium text-primary hover:underline">
                          {o.code}
                        </Link>
                        <p className="text-xs text-muted">
                          {formatDateTime(o.created_at)}
                          {o.source === "portal" ? " · portal" : ""}
                        </p>
                      </Td>
                      <Td>{o.customer?.name}</Td>
                      <Td className="text-xs">{(o.lines ?? []).map((l) => `${formatQty(l.quantity, l.unit)} ${l.description}`).join(", ")}</Td>
                      <Td className="text-right tabular-nums">{o.total_amount !== undefined ? formatMoney(o.total_amount, o.currency) : "—"}</Td>
                      <Td>
                        <Badge tone={st.tone}>{st.label}</Badge>
                      </Td>
                    </tr>
                  );
                })}
              </tbody>
            </Table>
          )}
        </>
      ) : products.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : (products.data ?? []).length === 0 ? (
        <EmptyState title="No products yet">{canPrice ? "Add what the farm sells, with its list price, and publish it to the customer portal." : null}</EmptyState>
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Product</Th>
              <Th>Unit</Th>
              <Th className="text-right">List price</Th>
              <Th>Minimum</Th>
              <Th>Portal</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {products.data!.map((p) => (
              <tr key={p.id} className={cn(!p.is_active && "opacity-60")}>
                <Td>
                  <p className="font-medium">{p.name}</p>
                  <p className="text-xs text-muted">{[p.code, p.category, p.availability_note].filter(Boolean).join(" · ")}</p>
                </Td>
                <Td>{p.unit}</Td>
                <Td className="text-right tabular-nums">{formatMoney(p.list_price, p.currency)}</Td>
                <Td>{p.min_order_quantity ? formatQty(p.min_order_quantity, p.unit) : "—"}</Td>
                <Td>{p.is_published ? <Badge tone="success">published</Badge> : <Badge tone="neutral">{p.is_active ? "hidden" : "inactive"}</Badge>}</Td>
                <Td className="text-right">
                  {canPrice ? (
                    <Button size="sm" variant="ghost" onClick={() => setDialog({ edit: p })}>
                      Edit
                    </Button>
                  ) : null}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}

      {dialog === "order" ? <NewOrderDialog farmId={farmId} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "product" ? <ProductDialog farmId={farmId} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog && typeof dialog === "object" ? <ProductDialog farmId={farmId} product={dialog.edit} onClose={() => setDialog(null)} onDone={done} /> : null}
    </>
  );
}
