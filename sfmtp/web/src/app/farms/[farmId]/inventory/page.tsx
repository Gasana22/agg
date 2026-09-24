"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowRightLeft, ClipboardCheck, PackageMinus, PackagePlus, Plus } from "lucide-react";
import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { TabBar } from "@/components/inventory/common";
import { CountDialog, IssueDialog, ItemDialog, RequestDialog, StockInDialog, TransferDialog } from "@/components/inventory/dialogs";
import { CountsPanel, MovementsPanel, RequestsPanel, TransfersPanel } from "@/components/inventory/panels";
import { useFarmCurrency } from "@/components/inventory/queries";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox, Input } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { daysUntil, formatMoney, formatQty } from "@/lib/inventory";
import { can } from "@/lib/permissions";

const TABS = [
  { key: "stock", label: "Stock" },
  { key: "movements", label: "Movements" },
  { key: "requests", label: "Requests" },
  { key: "counts", label: "Counts" },
  { key: "transfers", label: "Transfers" },
] as const;

const ACTIONS: Record<string, string> = { "stock-in": "stock-in", issue: "issue", transfer: "transfer", count: "count", request: "request", item: "item" };

export default function InventoryPage() {
  return (
    <Suspense>
      <Inventory />
    </Suspense>
  );
}

function Inventory() {
  const { farmId } = useParams<{ farmId: string }>();
  const search = useSearchParams();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const writable = workspace?.type === "farm";
  const canManage = writable && can(perms, "inventory.manage");
  const canMove = writable && can(perms, "inventory.stock.move");
  const canAdjust = writable && can(perms, "inventory.stock.adjust");
  const canApprove = writable && can(perms, "inventory.stock.approve");
  const canRequest = writable && can(perms, "inventory.requests.create");
  const seesValues = can(perms, "inventory.values.view");
  const currency = useFarmCurrency(farmId);

  const tab = TABS.find((t) => t.key === search.get("tab"))?.key ?? "stock";
  const [dialog, setDialog] = useState<string | null>(ACTIONS[search.get("action") ?? ""] ?? null);
  const done = async () => {
    setDialog(null);
    await Promise.all(
      ["inventory-items", "inventory-stock", "inventory-movements", "inventory-requests", "inventory-adjustments", "inventory-transfers", "inventory-alerts"].map((k) => queryClient.invalidateQueries({ queryKey: [k, farmId] })),
    );
  };

  return (
    <>
      <PageHeader
        title="Inventory"
        description="Stock in every store, by lot. Every movement is kept, and lots stay traceable to where they were used."
        actions={
          writable ? (
            <div className="flex flex-wrap gap-2">
              {canMove ? (
                <>
                  <Button variant="secondary" onClick={() => setDialog("stock-in")}>
                    <PackagePlus /> Stock in
                  </Button>
                  <Button variant="secondary" onClick={() => setDialog("issue")}>
                    <PackageMinus /> Issue
                  </Button>
                  <Button variant="secondary" onClick={() => setDialog("transfer")}>
                    <ArrowRightLeft /> Transfer
                  </Button>
                </>
              ) : null}
              {canAdjust ? (
                <Button variant="secondary" onClick={() => setDialog("count")}>
                  <ClipboardCheck /> Count
                </Button>
              ) : null}
              {canRequest && !canMove ? (
                <Button variant="secondary" onClick={() => setDialog("request")}>
                  Request stock
                </Button>
              ) : null}
              {canManage ? (
                <Button onClick={() => setDialog("item")}>
                  <Plus /> New item
                </Button>
              ) : null}
            </div>
          ) : null
        }
      />
      <TabBar base={`/farms/${farmId}/inventory`} tabs={TABS} active={tab} label="Inventory sections" />

      {tab === "stock" ? <StockPanel farmId={farmId} seesValues={seesValues} currency={currency} /> : null}
      {tab === "movements" ? <MovementsPanel farmId={farmId} seesValues={seesValues} currency={currency} /> : null}
      {tab === "requests" ? (
        <>
          {canRequest ? (
            <div className="mb-3 flex justify-end">
              <Button size="sm" variant="secondary" onClick={() => setDialog("request")}>
                <Plus /> Request stock
              </Button>
            </div>
          ) : null}
          <RequestsPanel farmId={farmId} canMove={canMove} canApprove={canApprove} />
        </>
      ) : null}
      {tab === "counts" ? <CountsPanel farmId={farmId} canApprove={canApprove} seesValues={seesValues} currency={currency} /> : null}
      {tab === "transfers" ? <TransfersPanel farmId={farmId} /> : null}

      {dialog === "item" && canManage ? <ItemDialog farmId={farmId} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "stock-in" && canMove ? <StockInDialog farmId={farmId} seesValues={seesValues} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "issue" && canMove ? <IssueDialog farmId={farmId} perms={perms} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "transfer" && canMove ? <TransferDialog farmId={farmId} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "count" && canAdjust ? <CountDialog farmId={farmId} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "request" && canRequest ? <RequestDialog farmId={farmId} perms={perms} onClose={() => setDialog(null)} onDone={done} /> : null}
    </>
  );
}

function StockPanel({ farmId, seesValues, currency }: { farmId: string; seesValues: boolean; currency: string }) {
  const [q, setQ] = useState("");
  const [lowOnly, setLowOnly] = useState(false);
  const items = useQuery({
    queryKey: ["inventory-items", farmId, "list", q, lowOnly],
    queryFn: async () =>
      (await api.GET("/farms/{farm}/inventory/items", { params: { path: { farm: farmId }, query: { "filter[search]": q || undefined, "filter[low_stock]": lowOnly || undefined } } })).data!.data!,
  });
  const alerts = useQuery({
    queryKey: ["inventory-alerts", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/inventory/alerts", { params: { path: { farm: farmId } } })).data!.data!,
  });
  const lots = [...(alerts.data?.expired ?? []), ...(alerts.data?.expiring ?? [])];
  const total = (items.data ?? []).reduce((s, i) => s + (i.stock_value ?? 0), 0);

  return (
    <>
      {lots.length > 0 || (alerts.data?.out_of_stock?.length ?? 0) > 0 ? (
        <div className="mb-4 space-y-1 rounded-xl border border-accent/40 bg-accent/10 px-4 py-3 text-sm" role="status">
          {(alerts.data?.out_of_stock ?? []).map((a) => (
            <p key={a.item?.id}>
              <strong>{a.item?.name}</strong> is out of stock.
            </p>
          ))}
          {lots.map((a) => {
            const days = daysUntil(a.expires_on!);
            return (
              <p key={`${a.lot?.id}-${a.location?.id}`}>
                <strong>{a.item?.name}</strong> lot {a.lot?.lot_number ?? a.lot?.code} ({formatQty(a.quantity, a.item?.unit)} in {a.location?.name}){" "}
                {days < 0 ? <span className="text-danger">expired {a.expires_on}</span> : `expires in ${days} day${days === 1 ? "" : "s"}`}.
              </p>
            );
          })}
        </div>
      ) : null}
      <div className="mb-3 flex flex-wrap items-center gap-3">
        <Input aria-label="Search" placeholder="Name, code or SKU" className="h-9 w-56" value={q} onChange={(e) => setQ(e.target.value)} />
        <Checkbox label="Low stock only" checked={lowOnly} onChange={(e) => setLowOnly(e.target.checked)} />
        {seesValues && items.data ? <span className="ml-auto text-sm text-muted">Stock value {formatMoney(total, currency)}</span> : null}
      </div>
      {items.isLoading ? (
        <Skeleton className="h-48 w-full" />
      ) : items.error ? (
        <ErrorNotice error={items.error} />
      ) : items.data!.length === 0 ? (
        <EmptyState title={q || lowOnly ? "Nothing matches" : "No stock items yet"}>{q || lowOnly ? null : "Create items for the seeds, feed, drugs and fuel you keep."}</EmptyState>
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Item</Th>
              <Th className="hidden sm:table-cell">Category</Th>
              <Th className="text-right">On hand</Th>
              <Th className="text-right">Reorder at</Th>
              {seesValues ? <Th className="text-right">Value</Th> : null}
              <Th />
            </tr>
          </thead>
          <tbody>
            {items.data!.map((i) => (
              <tr key={i.id} className="hover:bg-surface-muted/50">
                <Td>
                  <Link href={`/farms/${farmId}/inventory/items/${i.id}`} className="font-medium text-primary hover:underline">
                    {i.name}
                  </Link>
                  <span className="block text-xs text-muted">
                    {i.code}
                    {i.sku ? ` · ${i.sku}` : ""}
                  </span>
                </Td>
                <Td className="hidden text-muted sm:table-cell">{i.category?.name ?? "—"}</Td>
                <Td className="text-right tabular-nums">{formatQty(i.on_hand ?? 0, i.unit)}</Td>
                <Td className="text-right tabular-nums text-muted">{formatQty(i.reorder_level)}</Td>
                {seesValues ? <Td className="text-right tabular-nums">{formatMoney(i.stock_value ?? 0, currency)}</Td> : null}
                <Td>
                  <div className="flex flex-wrap gap-1">
                    {(i.on_hand ?? 0) <= 0 ? <Badge tone="danger">Out</Badge> : i.low_stock ? <Badge tone="warning">Low</Badge> : null}
                    {i.tracks_lots ? <Badge>lots</Badge> : null}
                  </div>
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </>
  );
}
