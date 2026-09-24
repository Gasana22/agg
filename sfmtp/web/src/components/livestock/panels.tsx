"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import Link from "next/link";
import { useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input, Label, Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { relativeDays } from "@/lib/crops";
import { humanize } from "@/lib/format";
import { type AnimalGroup, type Breeding, SALE_TONE, type SaleRequest } from "@/lib/livestock";

import { BirthDialog, CompleteSaleDialog, GroupDialog, ServeDialog } from "./dialogs";
import { useActiveAnimals, useGroups } from "./queries";

export function GroupsPanel({ farmId, canManage }: { farmId: string; canManage: boolean }) {
  const queryClient = useQueryClient();
  const groups = useGroups(farmId);
  const [editing, setEditing] = useState<AnimalGroup | "new" | null>(null);

  return (
    <>
      {canManage ? (
        <div className="mb-3 flex justify-end">
          <Button size="sm" onClick={() => setEditing("new")}>
            <Plus /> New group
          </Button>
        </div>
      ) : null}
      {groups.isLoading ? (
        <Skeleton className="h-32 w-full" />
      ) : groups.data?.length === 0 ? (
        <EmptyState title="No groups yet">Groups hold herds, flocks and pens, so you can treat, feed and move them together.</EmptyState>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {groups.data?.map((g) => (
            <Card key={g.id}>
              <CardContent className="space-y-2 text-sm">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="font-semibold">{g.name}</p>
                    <p className="font-mono text-xs text-muted">{g.code}</p>
                  </div>
                  <Badge tone="neutral">{humanize(g.purpose ?? "")}</Badge>
                </div>
                <p>
                  {g.species?.name} · <span className="font-medium">{g.head_count ?? 0}</span> registered
                  {g.flock_size ? ` · flock of ${g.flock_size}` : ""}
                </p>
                <p className="text-muted">{g.location ? `${g.location.code} · ${g.location.name}` : "No location"}</p>
                <div className="flex gap-2">
                  <Link className="text-primary hover:underline" href={`/farms/${farmId}/livestock?group=${g.id}`}>
                    View animals
                  </Link>
                  {canManage ? (
                    <button type="button" className="text-muted hover:text-foreground" onClick={() => setEditing(g)}>
                      Edit
                    </button>
                  ) : null}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
      {editing ? (
        <GroupDialog
          farmId={farmId}
          group={editing === "new" ? undefined : editing}
          onClose={() => setEditing(null)}
          onDone={async () => {
            setEditing(null);
            await queryClient.invalidateQueries({ queryKey: ["animal-groups", farmId] });
          }}
        />
      ) : null}
    </>
  );
}

export function BreedingPanel({ farmId, canRecord }: { farmId: string; canRecord: boolean }) {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState("open");
  const [serving, setServing] = useState(false);
  const [birth, setBirth] = useState<Breeding | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const breedings = useQuery({
    queryKey: ["breedings", farmId, status],
    queryFn: async () => (await api.GET("/farms/{farm}/animal-breedings", { params: { path: { farm: farmId }, query: { "filter[status]": status as "open", per_page: 200 } } })).data!.data!,
  });
  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ["breedings", farmId] });
    await queryClient.invalidateQueries({ queryKey: ["animals", farmId] });
  };
  const setOutcome = async (b: Breeding, to: "pregnant" | "not_pregnant" | "aborted") => {
    setError(null);
    try {
      await api.PATCH("/farms/{farm}/animal-breedings/{breeding}", { params: { path: { farm: farmId, breeding: b.id! } }, body: { status: to } });
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  };

  return (
    <>
      <div className="mb-3 flex items-center justify-between gap-2">
        <Select aria-label="Status" className="h-9 w-48" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="open">Served or pregnant</option>
          <option value="pregnant">Pregnant</option>
          <option value="delivered">Delivered</option>
          <option value="not_pregnant">Not pregnant</option>
          <option value="aborted">Aborted</option>
        </Select>
        {canRecord ? (
          <Button size="sm" onClick={() => setServing(true)}>
            <Plus /> Record service
          </Button>
        ) : null}
      </div>
      {error ? <ErrorNotice error={error} /> : null}
      {breedings.isLoading ? (
        <Skeleton className="h-32 w-full" />
      ) : breedings.data?.length === 0 ? (
        <EmptyState title="Nothing here" />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Dam</Th>
              <Th>Sire</Th>
              <Th>Served</Th>
              <Th>Expected</Th>
              <Th>Status</Th>
              {canRecord ? <Th /> : null}
            </tr>
          </thead>
          <tbody>
            {breedings.data?.map((b) => (
              <tr key={b.id}>
                <Td>
                  <Link className="font-medium text-primary hover:underline" href={`/farms/${farmId}/livestock/animals/${b.dam?.id}`}>
                    {b.dam?.animal_code}
                  </Link>{" "}
                  <span className="text-muted">{b.dam?.name}</span>
                </Td>
                <Td className="text-muted">{b.sire ? b.sire.animal_code : b.sire_note ?? (b.method === "ai" ? "AI" : "—")}</Td>
                <Td>{b.served_on}</Td>
                <Td>{b.expected_due_on ? `${b.expected_due_on} (${relativeDays(b.expected_due_on)})` : "—"}</Td>
                <Td>
                  <Badge tone={b.status === "pregnant" ? "primary" : b.status === "served" ? "warning" : "neutral"}>{humanize(b.status ?? "")}</Badge>
                </Td>
                {canRecord ? (
                  <Td className="text-right">
                    {b.status === "served" ? (
                      <>
                        <Button size="sm" variant="ghost" onClick={() => setOutcome(b, "pregnant")}>
                          Pregnant
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setOutcome(b, "not_pregnant")}>
                          Not pregnant
                        </Button>
                      </>
                    ) : null}
                    {b.status === "served" || b.status === "pregnant" ? (
                      <Button size="sm" variant="secondary" onClick={() => setBirth(b)}>
                        Record birth
                      </Button>
                    ) : null}
                  </Td>
                ) : null}
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {serving ? (
        <ServeDialog
          farmId={farmId}
          onClose={() => setServing(false)}
          onDone={async () => {
            setServing(false);
            await refresh();
          }}
        />
      ) : null}
      {birth ? (
        <BirthDialog
          farmId={farmId}
          breeding={birth}
          onClose={() => setBirth(null)}
          onDone={async () => {
            setBirth(null);
            await refresh();
          }}
        />
      ) : null}
    </>
  );
}

/**
 * The milking sheet: one row per cow, litres for one session, saved
 * together. Cows under withdrawal are marked and their milk saved as
 * discarded. Eggs are recorded per flock.
 */
export function ProductionPanel({ farmId, canRecord }: { farmId: string; canRecord: boolean }) {
  const queryClient = useQueryClient();
  const animals = useActiveAnimals(farmId);
  const groups = useGroups(farmId);
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [session, setSession] = useState<"am" | "pm">(new Date().getHours() < 12 ? "am" : "pm");
  const [values, setValues] = useState<Record<string, string>>({});
  const [saved, setSaved] = useState<string | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);

  const recent = useQuery({
    queryKey: ["production", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/animal-production", { params: { path: { farm: farmId }, query: { per_page: 200 } } })).data!.data!,
  });

  const cows = (animals.data ?? []).filter((a) => a.sex === "female" && (a.age_months ?? 99) >= 18 && ["Cattle", "Goat", "Sheep"].includes(a.species?.name ?? ""));
  const flocks = (groups.data ?? []).filter((g) => g.purpose === "layers");

  async function save() {
    setBusy(true);
    setError(null);
    setSaved(null);
    let n = 0;
    try {
      for (const [key, value] of Object.entries(values)) {
        if (value === "") continue;
        const [kind, id] = key.split(":");
        const animal = kind === "animal" ? cows.find((c) => c.id === id) : undefined;
        await api.POST("/farms/{farm}/animal-production", {
          params: { path: { farm: farmId } },
          body:
            kind === "animal"
              ? { animal_id: id, product: "milk", produced_on: date, session, quantity: Number(value), unit: "l", discarded: Boolean(animal?.milk_withdrawal_until) }
              : { group_id: id, product: "eggs", produced_on: date, session: "day", quantity: Number(value), unit: "pcs" },
        });
        n++;
      }
      setValues({});
      setSaved(`${n} record${n === 1 ? "" : "s"} saved.`);
      await queryClient.invalidateQueries({ queryKey: ["production", farmId] });
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
      if (n) setSaved(`${n} saved before the error.`);
    } finally {
      setBusy(false);
    }
  }

  const byDay = new Map<string, { milk: number; discarded: number; eggs: number; lot?: string | null }>();
  for (const r of recent.data ?? []) {
    const d = byDay.get(r.produced_on ?? "") ?? { milk: 0, discarded: 0, eggs: 0 };
    if (r.product === "milk") {
      if (r.discarded) d.discarded += r.quantity ?? 0;
      else {
        d.milk += r.quantity ?? 0;
        d.lot = r.lot?.batch_code ?? d.lot;
      }
    }
    if (r.product === "eggs") d.eggs += r.quantity ?? 0;
    byDay.set(r.produced_on ?? "", d);
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
      <section>
        <h2 className="mb-3 text-lg font-semibold">Milking sheet</h2>
        {canRecord ? (
          <>
            <div className="mb-3 flex flex-wrap items-end gap-3">
              <div>
                <Label htmlFor="sheet-date">Date</Label>
                <Input id="sheet-date" type="date" value={date} max={new Date().toISOString().slice(0, 10)} onChange={(e) => setDate(e.target.value)} />
              </div>
              <div>
                <Label htmlFor="sheet-session">Session</Label>
                <Select id="sheet-session" value={session} onChange={(e) => setSession(e.target.value as "am" | "pm")}>
                  <option value="am">Morning</option>
                  <option value="pm">Evening</option>
                </Select>
              </div>
            </div>
            <Table>
              <thead>
                <tr>
                  <Th>Animal</Th>
                  <Th className="w-40">Litres</Th>
                </tr>
              </thead>
              <tbody>
                {cows.map((c) => (
                  <tr key={c.id}>
                    <Td>
                      <span className="font-medium">{c.label}</span>
                      {c.milk_withdrawal_until ? (
                        <Badge tone="warning" className="ml-2">
                          withdrawal to {c.milk_withdrawal_until}: discard
                        </Badge>
                      ) : null}
                    </Td>
                    <Td>
                      <Input
                        aria-label={`Litres from ${c.label}`}
                        type="number"
                        min="0"
                        step="0.1"
                        value={values[`animal:${c.id}`] ?? ""}
                        onChange={(e) => setValues((v) => ({ ...v, [`animal:${c.id}`]: e.target.value }))}
                      />
                    </Td>
                  </tr>
                ))}
                {flocks.map((g) => (
                  <tr key={g.id}>
                    <Td>
                      <span className="font-medium">{g.name}</span> <span className="text-muted">· eggs for the day</span>
                    </Td>
                    <Td>
                      <Input aria-label={`Eggs from ${g.name}`} type="number" min="0" step="1" value={values[`group:${g.id}`] ?? ""} onChange={(e) => setValues((v) => ({ ...v, [`group:${g.id}`]: e.target.value }))} />
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
            <div className="mt-3 flex items-center gap-3">
              <Button onClick={save} disabled={busy || Object.values(values).every((v) => v === "")}>
                {busy ? "Saving…" : "Save sheet"}
              </Button>
              {saved ? (
                <span className="text-sm text-success" role="status">
                  {saved}
                </span>
              ) : null}
            </div>
            {error ? (
              <div className="mt-3">
                <ErrorNotice error={error} />
              </div>
            ) : null}
          </>
        ) : (
          <p className="text-sm text-muted">You can view production but not record it.</p>
        )}
      </section>
      <section>
        <h2 className="mb-3 text-lg font-semibold">Recent days</h2>
        <Table>
          <thead>
            <tr>
              <Th>Day</Th>
              <Th className="text-right">Milk kept</Th>
              <Th className="text-right">Eggs</Th>
            </tr>
          </thead>
          <tbody>
            {[...byDay.entries()].slice(0, 14).map(([day, d]) => (
              <tr key={day}>
                <Td>
                  {day}
                  {d.lot ? <span className="block font-mono text-xs text-muted">{d.lot}</span> : null}
                </Td>
                <Td className="text-right tabular-nums">
                  {d.milk.toFixed(1)} l{d.discarded ? <span className="block text-xs text-warning">{d.discarded.toFixed(1)} l discarded</span> : null}
                </Td>
                <Td className="text-right tabular-nums">{d.eggs || "—"}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      </section>
    </div>
  );
}

export function SalesPanel({ farmId, canApprove, seesMoney, currency }: { farmId: string; canApprove: boolean; seesMoney: boolean; currency: string }) {
  const queryClient = useQueryClient();
  const [completing, setCompleting] = useState<SaleRequest | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const sales = useQuery({
    queryKey: ["animal-sales", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/animal-sales", { params: { path: { farm: farmId }, query: { per_page: 100 } } })).data!.data!,
  });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["animal-sales", farmId] });
  const decide = async (s: SaleRequest, approve: boolean) => {
    setError(null);
    try {
      if (approve) await api.POST("/farms/{farm}/animal-sales/{sale}/approve", { params: { path: { farm: farmId, sale: s.id! } }, body: {} });
      else {
        const note = window.prompt("Why is the sale rejected?");
        if (!note) return;
        await api.POST("/farms/{farm}/animal-sales/{sale}/reject", { params: { path: { farm: farmId, sale: s.id! } }, body: { note } });
      }
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  };

  return (
    <>
      {error ? <ErrorNotice error={error} /> : null}
      {sales.isLoading ? (
        <Skeleton className="h-32 w-full" />
      ) : sales.data?.length === 0 ? (
        <EmptyState title="No sale requests" />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Request</Th>
              <Th>Animal</Th>
              <Th>Reason / buyer</Th>
              {seesMoney ? <Th className="text-right">Price</Th> : null}
              <Th>Status</Th>
              {canApprove ? <Th /> : null}
            </tr>
          </thead>
          <tbody>
            {sales.data?.map((s) => (
              <tr key={s.id}>
                <Td className="font-mono text-xs">{s.code}</Td>
                <Td>
                  <Link className="text-primary hover:underline" href={`/farms/${farmId}/livestock/animals/${s.animal?.id}`}>
                    {s.animal?.label}
                  </Link>
                </Td>
                <Td className="text-muted">
                  {s.reason}
                  {s.buyer ? <span className="block text-xs">Buyer: {s.buyer}</span> : null}
                </Td>
                {seesMoney ? (
                  <Td className="text-right tabular-nums">
                    {s.sale_price != null ? `${currency} ${s.sale_price.toLocaleString()}` : s.expected_price != null ? <span className="text-muted">~{s.expected_price.toLocaleString()}</span> : "—"}
                  </Td>
                ) : null}
                <Td>
                  <Badge tone={SALE_TONE[s.status ?? ""] ?? "neutral"}>{s.status}</Badge>
                  {s.sold_on ? <span className="block text-xs text-muted">sold {s.sold_on}</span> : null}
                </Td>
                {canApprove ? (
                  <Td className="text-right">
                    {s.status === "requested" ? (
                      <>
                        <Button size="sm" variant="secondary" onClick={() => decide(s, true)}>
                          Approve
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => decide(s, false)}>
                          Reject
                        </Button>
                      </>
                    ) : null}
                    {s.status === "approved" ? (
                      <Button size="sm" onClick={() => setCompleting(s)}>
                        Record sale
                      </Button>
                    ) : null}
                  </Td>
                ) : null}
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {completing ? (
        <CompleteSaleDialog
          farmId={farmId}
          sale={completing}
          seesMoney={seesMoney}
          onClose={() => setCompleting(null)}
          onDone={async () => {
            setCompleting(null);
            await refresh();
            await queryClient.invalidateQueries({ queryKey: ["animals", farmId] });
          }}
        />
      ) : null}
    </>
  );
}
