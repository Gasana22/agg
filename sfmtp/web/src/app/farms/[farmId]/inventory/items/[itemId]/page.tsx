"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, Pencil } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";

import { ItemDialog } from "@/components/inventory/dialogs";
import { MovementsPanel } from "@/components/inventory/panels";
import { useFarmCurrency } from "@/components/inventory/queries";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { daysUntil, formatMoney, formatQty, type InventoryItem } from "@/lib/inventory";
import { can } from "@/lib/permissions";

export default function ItemPage() {
  const { farmId, itemId } = useParams<{ farmId: string; itemId: string }>();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const perms = workspace?.permissions;
  const canManage = workspace?.type === "farm" && can(perms, "inventory.manage");
  const seesValues = can(perms, "inventory.values.view");
  const seesTrace = can(perms, "trace.batches.view");
  const currency = useFarmCurrency(farmId);
  const [editing, setEditing] = useState(false);

  const item = useQuery({
    queryKey: ["inventory-items", farmId, itemId],
    queryFn: async () => (await api.GET("/farms/{farm}/inventory/items/{item}", { params: { path: { farm: farmId, item: itemId } } })).data!.data!,
  });

  if (item.isLoading) return <Skeleton className="h-64 w-full" />;
  if (item.error) return <ErrorNotice error={item.error} />;
  const i = item.data!;
  const balances = i.balances ?? [];

  return (
    <>
      <Link href={`/farms/${farmId}/inventory`} className="mb-3 inline-flex items-center gap-1 text-sm text-muted hover:text-foreground">
        <ArrowLeft className="size-4" /> Inventory
      </Link>
      <PageHeader
        title={i.name ?? ""}
        description={[i.code, i.category?.name, i.sku].filter(Boolean).join(" · ")}
        actions={
          canManage ? (
            <Button variant="secondary" onClick={() => setEditing(true)}>
              <Pencil /> Edit
            </Button>
          ) : null
        }
      />
      <div className="mb-6 grid gap-3 sm:grid-cols-4">
        <Fact label="On hand" value={formatQty(i.on_hand ?? 0, i.unit)} />
        <Fact label="Reorder at" value={formatQty(i.reorder_level, i.unit)} />
        {seesValues ? <Fact label="Value" value={formatMoney(i.stock_value ?? 0, currency)} /> : null}
        <Fact
          label="Tracking"
          value={
            <span className="flex flex-wrap gap-1">
              {i.tracks_lots ? <Badge tone="primary">lots</Badge> : <Badge>no lots</Badge>}
              {i.tracks_expiry ? <Badge tone="primary">expiry</Badge> : null}
              {!i.is_active ? <Badge tone="danger">archived</Badge> : null}
              {i.low_stock ? <Badge tone="warning">low</Badge> : null}
            </span>
          }
        />
      </div>

      <h2 className="mb-2 text-lg font-semibold">Where it is</h2>
      {balances.length === 0 ? (
        <EmptyState title="None in stock" />
      ) : (
        <Table className="mb-6">
          <thead>
            <tr>
              <Th>Store</Th>
              <Th>Lot</Th>
              <Th>Expires</Th>
              <Th className="text-right">Quantity</Th>
              {seesValues ? <Th className="text-right">Value</Th> : null}
            </tr>
          </thead>
          <tbody>
            {balances.map((b) => {
              const days = b.lot?.expires_on ? daysUntil(b.lot.expires_on) : null;
              return (
                <tr key={b.id}>
                  <Td>{b.location?.name}</Td>
                  <Td>
                    {b.lot ? (
                      seesTrace && b.lot.trace_batch_id ? (
                        <Link href={`/farms/${farmId}/traceability/batches/${b.lot.trace_batch_id}`} className="text-primary hover:underline">
                          {b.lot.code}
                        </Link>
                      ) : (
                        b.lot.code
                      )
                    ) : (
                      "—"
                    )}
                    {b.lot?.lot_number ? <span className="block text-xs text-muted">supplier lot {b.lot.lot_number}</span> : null}
                  </Td>
                  <Td>
                    {b.lot?.expires_on ?? "—"}
                    {days !== null && days <= 30 ? (
                      <Badge tone={days < 0 ? "danger" : "warning"} className="ml-2">
                        {days < 0 ? "expired" : `${days} d`}
                      </Badge>
                    ) : null}
                  </Td>
                  <Td className="text-right tabular-nums">{formatQty(b.quantity, i.unit)}</Td>
                  {seesValues ? <Td className="text-right tabular-nums">{formatMoney(b.value, currency)}</Td> : null}
                </tr>
              );
            })}
          </tbody>
        </Table>
      )}

      <h2 className="mb-2 text-lg font-semibold">Movements</h2>
      <MovementsPanel farmId={farmId} itemId={itemId} seesValues={seesValues} currency={currency} />

      {editing ? (
        <ItemDialog
          farmId={farmId}
          item={i as InventoryItem}
          onClose={() => setEditing(false)}
          onDone={async () => {
            setEditing(false);
            await queryClient.invalidateQueries({ queryKey: ["inventory-items", farmId] });
          }}
        />
      ) : null}
    </>
  );
}

function Fact({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="rounded-xl border border-border bg-surface px-4 py-3">
      <p className="text-xs uppercase tracking-wide text-muted">{label}</p>
      <div className="mt-1 text-lg font-semibold tabular-nums">{value}</div>
    </div>
  );
}
