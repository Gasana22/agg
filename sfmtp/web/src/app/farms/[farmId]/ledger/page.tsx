"use client";

import { useInfiniteQuery, useQuery, useQueryClient } from "@tanstack/react-query";
import { useParams, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { TabBar } from "@/components/inventory/common";
import { ReasonDialog } from "@/components/inventory/dialogs";
import { useFarmCurrency } from "@/components/inventory/queries";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input, Label, Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { humanize } from "@/lib/format";
import { formatMoney, type LedgerEntry } from "@/lib/inventory";
import { can } from "@/lib/permissions";

const TABS = [
  { key: "balances", label: "Trial balance" },
  { key: "journal", label: "Journal" },
] as const;

const TYPE_ORDER = ["asset", "liability", "equity", "income", "expense"];

export default function LedgerPage() {
  return (
    <Suspense>
      <Ledger />
    </Suspense>
  );
}

function Ledger() {
  const { farmId } = useParams<{ farmId: string }>();
  const search = useSearchParams();
  const { workspace } = useFarmWorkspace(farmId);
  const canManage = workspace?.type === "farm" && can(workspace?.permissions, "finance.manage");
  const currency = useFarmCurrency(farmId);
  const tab = TABS.find((t) => t.key === search.get("tab"))?.key ?? "balances";

  return (
    <>
      <PageHeader title="Ledger" description="Double-entry books. Stock, deliveries and supplier invoices post here. Entries are never edited: stock is corrected by a count, manual entries by a reversal." />
      <TabBar base={`/farms/${farmId}/ledger`} tabs={TABS} active={tab} label="Ledger sections" />
      {tab === "balances" ? <TrialBalance farmId={farmId} currency={currency} /> : <Journal farmId={farmId} currency={currency} canManage={canManage} initialAccount={search.get("account") ?? ""} />}
    </>
  );
}

function TrialBalance({ farmId, currency }: { farmId: string; currency: string }) {
  const [asOf, setAsOf] = useState("");
  const accounts = useQuery({
    queryKey: ["ledger-accounts", farmId, asOf],
    queryFn: async () => (await api.GET("/farms/{farm}/ledger/accounts", { params: { path: { farm: farmId }, query: { as_of: asOf || undefined } } })).data!,
  });
  const rows = [...(accounts.data?.data ?? [])].sort((a, b) => TYPE_ORDER.indexOf(a.type ?? "") - TYPE_ORDER.indexOf(b.type ?? "") || (a.code ?? "").localeCompare(b.code ?? ""));
  const meta = accounts.data?.meta;

  return (
    <>
      <div className="mb-3 flex items-end gap-3">
        <div>
          <Label htmlFor="as_of">As of</Label>
          <Input id="as_of" type="date" className="h-9 w-44" value={asOf} onChange={(e) => setAsOf(e.target.value)} />
        </div>
        {meta ? (
          <p className="pb-2 text-sm text-muted">
            {meta.total_debit === meta.total_credit ? <Badge tone="success">Balanced</Badge> : <Badge tone="danger">Out of balance</Badge>}
          </p>
        ) : null}
      </div>
      {accounts.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : accounts.error ? (
        <ErrorNotice error={accounts.error} />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Account</Th>
              <Th>Type</Th>
              <Th className="text-right">Debit</Th>
              <Th className="text-right">Credit</Th>
              <Th className="text-right">Balance</Th>
            </tr>
          </thead>
          <tbody>
            {rows.map((a) => (
              <tr key={a.id} className={(a.debit ?? 0) === 0 && (a.credit ?? 0) === 0 ? "text-muted" : ""}>
                <Td>
                  <span className="tabular-nums text-muted">{a.code}</span> {a.name}
                </Td>
                <Td className="text-muted">{humanize(a.type ?? "")}</Td>
                <Td className="text-right tabular-nums">{a.debit ? formatMoney(a.debit) : ""}</Td>
                <Td className="text-right tabular-nums">{a.credit ? formatMoney(a.credit) : ""}</Td>
                <Td className="text-right tabular-nums font-medium">{formatMoney(a.balance, currency)}</Td>
              </tr>
            ))}
            <tr>
              <Td colSpan={2} className="font-medium">
                Totals
              </Td>
              <Td className="text-right font-semibold tabular-nums">{formatMoney(meta?.total_debit)}</Td>
              <Td className="text-right font-semibold tabular-nums">{formatMoney(meta?.total_credit)}</Td>
              <Td />
            </tr>
          </tbody>
        </Table>
      )}
    </>
  );
}

function Journal({ farmId, currency, canManage, initialAccount }: { farmId: string; currency: string; canManage: boolean; initialAccount: string }) {
  const queryClient = useQueryClient();
  const [account, setAccount] = useState(initialAccount);
  const [reversing, setReversing] = useState<LedgerEntry | null>(null);
  const accounts = useQuery({
    queryKey: ["ledger-accounts", farmId, ""],
    queryFn: async () => (await api.GET("/farms/{farm}/ledger/accounts", { params: { path: { farm: farmId } } })).data!,
  });
  const entries = useInfiniteQuery({
    queryKey: ["ledger-entries", farmId, account],
    queryFn: async ({ pageParam }) =>
      (await api.GET("/farms/{farm}/ledger/entries", { params: { path: { farm: farmId }, query: { "filter[account_id]": account || undefined, cursor: pageParam ?? undefined, per_page: 50 } } })).data!,
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta?.next_cursor ?? null,
  });
  const rows = entries.data?.pages.flatMap((p) => p.data ?? []) ?? [];

  return (
    <>
      <div className="mb-3">
        <Select aria-label="Account" className="h-9 w-64" value={account} onChange={(e) => setAccount(e.target.value)}>
          <option value="">All accounts</option>
          {(accounts.data?.data ?? []).map((a) => (
            <option key={a.id} value={a.id}>
              {a.code} {a.name}
            </option>
          ))}
        </Select>
      </div>
      {entries.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : entries.error ? (
        <ErrorNotice error={entries.error} />
      ) : rows.length === 0 ? (
        <EmptyState title="No entries yet">Stock received, issued or counted, and supplier invoices, post here.</EmptyState>
      ) : (
        <div className="space-y-3">
          {rows.map((e) => (
            <div key={e.id} className="rounded-xl border border-border bg-surface p-4">
              <div className="mb-2 flex flex-wrap items-start justify-between gap-2">
                <div>
                  <span className="font-medium tabular-nums">{e.number}</span> · {e.posted_on}
                  <span className="block text-sm">{e.memo}</span>
                  <span className="block text-xs text-muted">
                    {humanize(e.source?.type ?? "")}
                    {e.posted_by ? ` · ${e.posted_by.name}` : ""}
                  </span>
                </div>
                <div className="flex items-center gap-2">
                  {e.reverses_entry_id ? <Badge tone="info">Reversal</Badge> : null}
                  {e.reversed_by ? <Badge>Reversed by {e.reversed_by.number}</Badge> : null}
                  {canManage && e.source?.type === "manual" && !e.reversed_by && !e.reverses_entry_id ? (
                    <Button size="sm" variant="ghost" onClick={() => setReversing(e)}>
                      Reverse
                    </Button>
                  ) : null}
                </div>
              </div>
              <table className="w-full text-sm">
                <tbody>
                  {(e.lines ?? []).map((l, i) => (
                    <tr key={i} className="border-t border-border">
                      <td className={`py-1.5 ${l.credit ? "pl-8" : ""}`}>
                        <span className="tabular-nums text-muted">{l.account?.code}</span> {l.account?.name}
                        {l.memo ? <span className="block text-xs text-muted">{l.memo}</span> : null}
                      </td>
                      <td className="w-36 py-1.5 text-right tabular-nums">{l.debit ? formatMoney(l.debit, currency) : ""}</td>
                      <td className="w-36 py-1.5 text-right tabular-nums">{l.credit ? formatMoney(l.credit, currency) : ""}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ))}
          {entries.hasNextPage ? (
            <div className="text-center">
              <Button variant="secondary" size="sm" onClick={() => entries.fetchNextPage()}>
                Load more
              </Button>
            </div>
          ) : null}
        </div>
      )}
      {reversing ? (
        <ReasonDialog
          title={`Reverse ${reversing.number}`}
          submitLabel="Post reversal"
          field="reason"
          onClose={() => setReversing(null)}
          onSubmit={async (reason) => {
            await api.POST("/farms/{farm}/ledger/entries/{entry}/reverse", { params: { path: { farm: farmId, entry: reversing.id! } }, body: { reason } });
            setReversing(null);
            await Promise.all(["ledger-entries", "ledger-accounts"].map((k) => queryClient.invalidateQueries({ queryKey: [k, farmId] })));
          }}
        />
      ) : null}
    </>
  );
}
