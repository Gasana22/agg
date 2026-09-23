"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FieldError, Input, Label, Select } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api, type components } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { humanize } from "@/lib/format";
import { cn } from "@/lib/utils";

type CatalogKey = "crops" | "crop-varieties" | "animal-species" | "animal-breeds" | "units" | "inventory-categories" | "activity-types";
type Entry = components["schemas"]["CatalogEntry"] & Record<string, unknown>;

/** Extra columns and create-form fields per catalogue (mirrors the API registry). */
const CATALOGS: { key: CatalogKey; label: string; parent?: { key: CatalogKey; field: string; label: string }; fields: { name: string; label: string; options?: string[]; type?: string }[] }[] = [
  { key: "crops", label: "Crops", fields: [{ name: "scientific_name", label: "Scientific name" }, { name: "category", label: "Category", options: ["cereal", "legume", "root_tuber", "vegetable", "fruit", "cash_crop", "fodder", "tree", "other"] }] },
  { key: "crop-varieties", label: "Varieties", parent: { key: "crops", field: "crop_id", label: "Crop" }, fields: [{ name: "maturity_days", label: "Days to maturity", type: "number" }] },
  { key: "animal-species", label: "Species", fields: [] },
  { key: "animal-breeds", label: "Breeds", parent: { key: "animal-species", field: "species_id", label: "Species" }, fields: [{ name: "purpose", label: "Purpose", options: ["dairy", "beef", "dual", "meat", "eggs", "wool", "draught", "other"] }] },
  { key: "units", label: "Units", fields: [{ name: "dimension", label: "Dimension", options: ["mass", "volume", "area", "count", "length"] }, { name: "to_base", label: "× base unit", type: "number" }] },
  { key: "inventory-categories", label: "Inventory", fields: [{ name: "kind", label: "Kind", options: ["seed", "fertilizer", "feed", "chemical", "drug", "tool", "equipment", "harvest", "packaging", "fuel", "other"] }] },
  { key: "activity-types", label: "Activities", fields: [{ name: "module", label: "Module", options: ["crops", "livestock", "assets", "inventory", "general"] }] },
];

export default function AdminCatalogPage() {
  const queryClient = useQueryClient();
  const [active, setActive] = useState<CatalogKey>("crops");
  const [adding, setAdding] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const def = CATALOGS.find((c) => c.key === active)!;

  const list = useQuery({
    queryKey: ["admin-catalog", active],
    queryFn: async () => (await api.GET("/admin/catalog/{catalog}", { params: { path: { catalog: active } } })).data!.data! as Entry[],
  });
  const parents = useQuery({
    queryKey: ["admin-catalog", def.parent?.key],
    enabled: !!def.parent,
    queryFn: async () => (await api.GET("/admin/catalog/{catalog}", { params: { path: { catalog: def.parent!.key } } })).data!.data! as Entry[],
  });
  const parentName = (id: unknown) => parents.data?.find((p) => p.id === id)?.name ?? "—";

  async function toggle(entry: Entry) {
    setError(null);
    try {
      await api.PATCH("/admin/catalog/{catalog}/{id}", { params: { path: { catalog: active, id: entry.id! } }, body: { is_active: !entry.is_active } });
      queryClient.invalidateQueries({ queryKey: ["admin-catalog", active] });
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  return (
    <>
      <PageHeader title="Global catalogues" description="Reference data every farm picks from. Entries are retired, never deleted; codes are permanent." actions={<Button onClick={() => setAdding(true)}><Plus /> Add</Button>} />
      <nav aria-label="Catalogues" className="mb-4 flex gap-1 overflow-x-auto border-b border-border">
        {CATALOGS.map((c) => (
          <button key={c.key} type="button" onClick={() => setActive(c.key)} aria-current={c.key === active ? "page" : undefined}
            className={cn("whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium", c.key === active ? "border-primary text-primary" : "border-transparent text-muted hover:text-foreground")}>
            {c.label}
          </button>
        ))}
      </nav>
      {error ? <div className="mb-4"><ErrorNotice error={error} /></div> : null}
      {list.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : list.error ? (
        <ErrorNotice error={list.error} />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Name</Th>
              <Th>Code</Th>
              {def.parent ? <Th>{def.parent.label}</Th> : null}
              {def.fields.map((f) => <Th key={f.name}>{f.label}</Th>)}
              <Th>Status</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {list.data!.map((e) => (
              <tr key={e.id} className={e.is_active ? "" : "opacity-60"}>
                <Td className="font-medium">{e.name}</Td>
                <Td className="font-mono text-xs">{e.code}</Td>
                {def.parent ? <Td>{parentName(e[def.parent.field])}</Td> : null}
                {def.fields.map((f) => <Td key={f.name} className="text-sm">{e[f.name] == null ? "—" : humanize(String(e[f.name]))}</Td>)}
                <Td>{e.is_active ? <Badge tone="primary">active</Badge> : <Badge>retired</Badge>}</Td>
                <Td className="text-right"><Button size="sm" variant="secondary" onClick={() => toggle(e)}>{e.is_active ? "Retire" : "Restore"}</Button></Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      <AddDialog open={adding} def={def} parents={parents.data ?? []} onClose={() => setAdding(false)} onDone={() => { setAdding(false); queryClient.invalidateQueries({ queryKey: ["admin-catalog", active] }); }} />
    </>
  );
}

function AddDialog({ open, def, parents, onClose, onDone }: { open: boolean; def: (typeof CATALOGS)[number]; parents: Entry[]; onClose: () => void; onDone: () => void }) {
  const [error, setError] = useState<ApiError | null>(null);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const body: Record<string, unknown> = {};
    f.forEach((v, k) => { if (v !== "") body[k] = def.fields.find((x) => x.name === k)?.type === "number" ? Number(v) : v; });
    setError(null);
    try {
      await api.POST("/admin/catalog/{catalog}", { params: { path: { catalog: def.key } }, body: body as Entry });
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title={`Add to ${def.label.toLowerCase()}`}>
      <form key={def.key} onSubmit={onSubmit} className="space-y-3">
        {def.parent ? (
          <div>
            <Label htmlFor="parent">{def.parent.label}</Label>
            <Select id="parent" name={def.parent.field} required>
              {parents.filter((p) => p.is_active).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </Select>
          </div>
        ) : null}
        <div className="grid grid-cols-2 gap-3">
          <div><Label htmlFor="name">Name</Label><Input id="name" name="name" required /></div>
          <div><Label htmlFor="code">Code</Label><Input id="code" name="code" required pattern="[a-z0-9_]+" placeholder="lower_snake" aria-invalid={!!error?.fieldError("code")} /></div>
        </div>
        {def.fields.map((field) => (
          <div key={field.name}>
            <Label htmlFor={field.name}>{field.label}</Label>
            {field.options ? (
              <Select id={field.name} name={field.name}>{field.options.map((o) => <option key={o} value={o}>{humanize(o)}</option>)}</Select>
            ) : (
              <Input id={field.name} name={field.name} type={field.type ?? "text"} step="any" />
            )}
          </div>
        ))}
        <FieldError>{error ? (Object.values(error.problem.errors ?? {})[0]?.[0] ?? error.problem.title) : null}</FieldError>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit">Add</Button>
        </div>
      </form>
    </Dialog>
  );
}
