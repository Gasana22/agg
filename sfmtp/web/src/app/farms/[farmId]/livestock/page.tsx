"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import Link from "next/link";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { HealthDialog, RegisterAnimalDialog, SaleRequestDialog, WeightDialog } from "@/components/livestock/dialogs";
import { BreedingPanel, GroupsPanel, ProductionPanel, SalesPanel } from "@/components/livestock/panels";
import { useGroups } from "@/components/livestock/queries";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input, Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { formatAge, STATUS_TONE } from "@/lib/livestock";
import { can } from "@/lib/permissions";
import { cn } from "@/lib/utils";

const TABS = [
  { key: "animals", label: "Animals" },
  { key: "groups", label: "Groups" },
  { key: "breeding", label: "Breeding" },
  { key: "production", label: "Milk & eggs" },
  { key: "sales", label: "Sales" },
] as const;

export default function LivestockPage() {
  return (
    <Suspense>
      <Livestock />
    </Suspense>
  );
}

function Livestock() {
  const { farmId } = useParams<{ farmId: string }>();
  const search = useSearchParams();
  const router = useRouter();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const writable = workspace?.type === "farm";
  const perms = workspace?.permissions;
  const canManage = writable && can(perms, "livestock.animals.manage");
  const canRecord = writable && perms?.["livestock.records.record"] === "all";
  const seesMoney = can(perms, "finance.values.view");

  const tab = (search.get("tab") as (typeof TABS)[number]["key"]) ?? "animals";
  const initialAction = search.get("action");
  const [dialog, setDialog] = useState<string | null>(search.get("new") === "animal" ? "register" : initialAction);
  const [status, setStatus] = useState("active");
  const [group, setGroup] = useState(search.get("group") ?? "");
  const [q, setQ] = useState("");
  const [flag, setFlag] = useState("");
  const groups = useGroups(farmId);

  const animals = useQuery({
    queryKey: ["animals", farmId, status, group, q, flag],
    queryFn: async () =>
      (
        await api.GET("/farms/{farm}/animals", {
          params: {
            path: { farm: farmId },
            query: {
              "filter[status]": status as "active",
              "filter[group_id]": group || undefined,
              "filter[withdrawal]": flag === "withdrawal" ? true : undefined,
              "filter[pregnant]": flag === "pregnant" ? true : undefined,
              q: q || undefined,
              per_page: 200,
            },
          },
        })
      ).data!.data!,
    enabled: tab === "animals",
  });
  const farm = useQuery({
    queryKey: ["farm", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}", { params: { path: { farm: farmId } } })).data!.data!,
    enabled: tab === "sales",
  });

  const setTab = (key: string) => router.replace(`/farms/${farmId}/livestock${key === "animals" ? "" : `?tab=${key}`}`);
  const done = async () => {
    setDialog(null);
    await queryClient.invalidateQueries({ queryKey: ["animals", farmId] });
    await queryClient.invalidateQueries({ queryKey: ["animal-sales", farmId] });
  };

  return (
    <>
      <PageHeader
        title="Livestock"
        description="Every animal's history, from birth or arrival to sale, is kept in traceability."
        actions={
          canManage && tab === "animals" ? (
            <Button onClick={() => setDialog("register")}>
              <Plus /> Register animal
            </Button>
          ) : null
        }
      />

      <div role="tablist" aria-label="Livestock sections" className="mb-4 flex gap-1 overflow-x-auto border-b border-border">
        {TABS.map((t) => (
          <button
            key={t.key}
            role="tab"
            type="button"
            aria-selected={tab === t.key}
            onClick={() => setTab(t.key)}
            className={cn("-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm", tab === t.key ? "border-primary font-medium text-foreground" : "border-transparent text-muted hover:text-foreground")}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === "animals" ? (
        <>
          <div className="mb-3 flex flex-wrap gap-2">
            <Input aria-label="Search" placeholder="Code, tag or name" className="h-9 w-52" value={q} onChange={(e) => setQ(e.target.value)} />
            <Select aria-label="Status" className="h-9 w-36" value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="active">In the herd</option>
              <option value="sold">Sold</option>
              <option value="dead">Died</option>
              <option value="culled">Culled</option>
              <option value="transferred">Transferred</option>
              <option value="all">All</option>
            </Select>
            <Select aria-label="Group" className="h-9 w-44" value={group} onChange={(e) => setGroup(e.target.value)}>
              <option value="">All groups</option>
              {groups.data?.map((g) => (
                <option key={g.id} value={g.id}>
                  {g.name}
                </option>
              ))}
            </Select>
            <Select aria-label="Show" className="h-9 w-44" value={flag} onChange={(e) => setFlag(e.target.value)}>
              <option value="">Everyone</option>
              <option value="withdrawal">Under withdrawal</option>
              <option value="pregnant">Pregnant</option>
            </Select>
          </div>
          {animals.isLoading ? (
            <Skeleton className="h-48 w-full" />
          ) : animals.error ? (
            <ErrorNotice error={animals.error} />
          ) : animals.data!.length === 0 ? (
            <EmptyState title="No animals here">{canManage ? "Register animals as they arrive or are born." : null}</EmptyState>
          ) : (
            <Table>
              <thead>
                <tr>
                  <Th>Animal</Th>
                  <Th>Species / breed</Th>
                  <Th>Sex · age</Th>
                  <Th>Group · location</Th>
                  <Th className="text-right">Last weight</Th>
                  <Th>Status</Th>
                </tr>
              </thead>
              <tbody>
                {animals.data!.map((a) => (
                  <tr key={a.id} className="hover:bg-surface-muted/50">
                    <Td>
                      <Link href={`/farms/${farmId}/livestock/animals/${a.id}${dialog && dialog !== "register" ? `?action=${dialog}` : ""}`} className="font-medium text-primary hover:underline">
                        {a.animal_code}
                        {a.name ? ` “${a.name}”` : ""}
                      </Link>
                      {a.tag_number ? <span className="block text-xs text-muted">tag {a.tag_number}</span> : null}
                    </Td>
                    <Td>
                      {a.species?.name}
                      <span className="block text-xs text-muted">{a.breed?.name ?? a.breed_note ?? "—"}</span>
                    </Td>
                    <Td className="capitalize">
                      {a.sex} · {formatAge(a.birth_date)}
                    </Td>
                    <Td className="text-muted">
                      {a.group?.name ?? "—"}
                      <span className="block text-xs">{a.location?.name ?? ""}</span>
                    </Td>
                    <Td className="text-right tabular-nums">{a.last_weight_kg != null ? `${a.last_weight_kg} kg` : "—"}</Td>
                    <Td>
                      <div className="flex flex-wrap gap-1">
                        <Badge tone={STATUS_TONE[a.status ?? ""] ?? "neutral"}>{a.status}</Badge>
                        {a.pregnancy ? <Badge tone="primary">due {a.pregnancy.expected_due_on}</Badge> : null}
                        {a.milk_withdrawal_until || a.meat_withdrawal_until ? <Badge tone="warning">withdrawal</Badge> : null}
                      </div>
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </>
      ) : null}
      {tab === "groups" ? <GroupsPanel farmId={farmId} canManage={canManage} /> : null}
      {tab === "breeding" ? <BreedingPanel farmId={farmId} canRecord={canRecord} /> : null}
      {tab === "production" ? <ProductionPanel farmId={farmId} canRecord={canRecord} /> : null}
      {tab === "sales" ? <SalesPanel farmId={farmId} canApprove={writable && can(perms, "livestock.sales.approve")} seesMoney={seesMoney} currency={farm.data?.currency ?? ""} /> : null}

      {dialog === "register" && canManage ? (
        <RegisterAnimalDialog
          farmId={farmId}
          onClose={() => setDialog(null)}
          onDone={(animal) => {
            setDialog(null);
            if (animal) router.push(`/farms/${farmId}/livestock/animals/${animal.id}`);
          }}
        />
      ) : null}
      {dialog === "health" && canRecord ? <HealthDialog farmId={farmId} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "weight" && canRecord ? <WeightDialog farmId={farmId} onClose={() => setDialog(null)} onDone={done} /> : null}
      {dialog === "sale" && writable && can(perms, "livestock.sales.request") ? <SaleRequestDialog farmId={farmId} seesMoney={seesMoney} onClose={() => setDialog(null)} onDone={done} /> : null}
    </>
  );
}
