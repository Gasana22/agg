"use client";

import { useState } from "react";

import { FormDialog, num, text } from "@/components/forms/form-dialog";
import { UnitSelect } from "@/components/forms/unit-select";
import { Checkbox, FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { api } from "@/lib/api/client";
import { useCatalog } from "@/lib/api/catalog";
import { formatQty, type InventoryItem, type InventoryRequest } from "@/lib/inventory";
import type { Permissions } from "@/lib/permissions";

import { LinesEditor, newLine, StoreSelect, SubjectPicker, type Line } from "./pickers";
import { useBalances, useItems } from "./queries";

type Base = { farmId: string; onClose: () => void; onDone: () => void };
const nowLocal = () => {
  const d = new Date();
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 16);
};
const iso = (local: string | null) => (local ? new Date(local).toISOString() : undefined);
const lines = (ls: Line[]) => ls.filter((l) => l.item_id && l.quantity !== "").map((l) => ({ item_id: l.item_id, quantity: Number(l.quantity), ...(l.lot_id ? { lot_id: l.lot_id } : {}) }));

export function ItemDialog({ farmId, item, onClose, onDone }: Base & { item?: InventoryItem }) {
  const categories = useCatalog("inventory-categories");
  const locked = item !== undefined && item.on_hand !== null && item.on_hand !== undefined && (item.on_hand ?? 0) !== 0;
  const [tracksLots, setTracksLots] = useState(item?.tracks_lots ?? true);
  const [category, setCategory] = useState(item?.category?.id ?? "");
  return (
    <FormDialog
      title={item ? `Edit ${item.name}` : "New stock item"}
      description={item ? undefined : "Items track lots by default, so every delivery can be traced to where it was used."}
      submitLabel={item ? "Save" : "Create item"}
      onClose={onClose}
      onSubmit={async (f) => {
        const body = {
          name: String(f.get("name")),
          category_id: String(f.get("category_id")),
          unit: String(f.get("unit")),
          sku: text(f, "sku"),
          reorder_level: num(f, "reorder_level"),
          tracks_lots: tracksLots,
          tracks_expiry: f.get("tracks_expiry") === "on",
          default_location_id: text(f, "default_location_id"),
          notes: text(f, "notes"),
        };
        if (item) {
          await api.PATCH("/farms/{farm}/inventory/items/{item}", {
            params: { path: { farm: farmId, item: item.id! } },
            body: { ...body, is_active: f.get("is_active") === "on", version: item.version },
          });
        } else {
          await api.POST("/farms/{farm}/inventory/items", { params: { path: { farm: farmId } }, body });
        }
        onDone();
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="name">Name</Label>
            <Input id="name" name="name" required minLength={2} defaultValue={item?.name} aria-invalid={!!error?.fieldError("name")} />
            <FieldError>{error?.fieldError("name")}</FieldError>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="category_id">Category</Label>
              <Select id="category_id" name="category_id" required value={category} onChange={(e) => setCategory(e.target.value)}>
                <option value="">Choose…</option>
                {(categories.data ?? []).map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="unit">Unit</Label>
              <UnitSelect id="unit" name="unit" defaultValue={item?.unit ?? "kg"} disabled={locked} aria-invalid={!!error?.fieldError("unit")} />
              {locked ? <input type="hidden" name="unit" value={item!.unit} /> : null}
              <FieldError>{error?.fieldError("unit")}</FieldError>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="reorder_level">Reorder at</Label>
              <Input id="reorder_level" name="reorder_level" type="number" step="any" min="0" defaultValue={item?.reorder_level ?? ""} />
            </div>
            <div>
              <Label htmlFor="sku">SKU / code</Label>
              <Input id="sku" name="sku" defaultValue={item?.sku ?? ""} />
            </div>
          </div>
          <StoreSelect farmId={farmId} id="default_location_id" name="default_location_id" label="Usual store" defaultValue={item?.default_location?.id ?? ""} />
          <div className="flex flex-wrap gap-4">
            <Checkbox label="Track lots" checked={tracksLots} disabled={locked} onChange={(e) => setTracksLots(e.target.checked)} />
            <Checkbox label="Track expiry dates" name="tracks_expiry" defaultChecked={item?.tracks_expiry ?? false} />
            {item ? <Checkbox label="Active" name="is_active" defaultChecked={item.is_active} /> : null}
          </div>
          <FieldError>{error?.fieldError("tracks_lots")}</FieldError>
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" defaultValue={item?.notes ?? ""} className="min-h-16" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

function ItemSelect({ items, value, onChange, error }: { items: InventoryItem[]; value: string; onChange: (id: string) => void; error?: string }) {
  return (
    <div>
      <Label htmlFor="item_id">Item</Label>
      <Select id="item_id" name="item_id" required value={value} onChange={(e) => onChange(e.target.value)} aria-invalid={!!error}>
        <option value="">Choose…</option>
        {items.map((i) => (
          <option key={i.id} value={i.id}>
            {i.name} ({formatQty(i.on_hand, i.unit)})
          </option>
        ))}
      </Select>
      <FieldError>{error}</FieldError>
    </div>
  );
}

export function StockInDialog({ farmId, seesValues, onClose, onDone }: Base & { seesValues: boolean }) {
  const items = useItems(farmId);
  const [itemId, setItemId] = useState("");
  const item = items.data?.find((i) => i.id === itemId);
  return (
    <FormDialog
      title="Stock in"
      description="Opening stock or stock found in a count. Purchases come in by receiving their order."
      submitLabel="Record"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/inventory/stock-in", {
          params: { path: { farm: farmId } },
          body: {
            item_id: itemId,
            location_id: String(f.get("location_id")),
            quantity: Number(f.get("quantity")),
            unit_cost: seesValues ? num(f, "unit_cost") : undefined,
            lot_number: text(f, "lot_number"),
            expires_on: text(f, "expires_on"),
            occurred_at: iso(text(f, "occurred_at")),
            note: text(f, "note"),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <ItemSelect items={items.data ?? []} value={itemId} onChange={setItemId} error={error?.fieldError("item_id")} />
          <StoreSelect farmId={farmId} id="location_id" name="location_id" required defaultValue={item?.default_location?.id ?? ""} key={item?.id} error={error?.fieldError("location_id")} />
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="quantity">Quantity{item ? ` (${item.unit})` : ""}</Label>
              <Input id="quantity" name="quantity" type="number" step="any" min="0" required aria-invalid={!!error?.fieldError("quantity")} />
              <FieldError>{error?.fieldError("quantity")}</FieldError>
            </div>
            {seesValues ? (
              <div>
                <Label htmlFor="unit_cost">Cost per unit</Label>
                <Input id="unit_cost" name="unit_cost" type="number" step="any" min="0" />
              </div>
            ) : null}
          </div>
          {item?.tracks_lots ? (
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label htmlFor="lot_number">Supplier lot number</Label>
                <Input id="lot_number" name="lot_number" />
              </div>
              <div>
                <Label htmlFor="expires_on">Expires on</Label>
                <Input id="expires_on" name="expires_on" type="date" required={item.tracks_expiry} aria-invalid={!!error?.fieldError("expires_on")} />
                <FieldError>{error?.fieldError("expires_on")}</FieldError>
              </div>
            </div>
          ) : null}
          <div>
            <Label htmlFor="occurred_at">When</Label>
            <Input id="occurred_at" name="occurred_at" type="datetime-local" defaultValue={nowLocal()} max={nowLocal()} />
          </div>
          <div>
            <Label htmlFor="note">Note</Label>
            <Input id="note" name="note" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function IssueDialog({ farmId, perms, onClose, onDone }: Base & { perms: Permissions | undefined }) {
  const items = useItems(farmId);
  const [itemId, setItemId] = useState("");
  const [storeId, setStoreId] = useState("");
  const [subjectType, setSubjectType] = useState("general");
  const [subjectId, setSubjectId] = useState("");
  const item = items.data?.find((i) => i.id === itemId);
  const balances = useBalances(farmId, itemId || undefined, Boolean(itemId));
  const here = (balances.data ?? []).filter((b) => b.location?.id === storeId);
  return (
    <FormDialog
      title="Issue stock"
      description="Oldest expiry first, unless you choose a lot. The issue is added to each lot's trace history."
      submitLabel="Issue"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/inventory/issues", {
          params: { path: { farm: farmId } },
          body: {
            item_id: itemId,
            location_id: storeId,
            quantity: Number(f.get("quantity")),
            lot_id: text(f, "lot_id"),
            subject_type: subjectType as "general",
            subject_id: subjectType === "general" ? null : subjectId,
            occurred_at: iso(text(f, "occurred_at")),
            note: text(f, "note"),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <ItemSelect items={(items.data ?? []).filter((i) => (i.on_hand ?? 0) > 0)} value={itemId} onChange={(id) => { setItemId(id); setStoreId(""); }} error={error?.fieldError("item_id")} />
          <div>
            <Label htmlFor="location_id">From store</Label>
            <Select id="location_id" required value={storeId} onChange={(e) => setStoreId(e.target.value)} aria-invalid={!!error?.fieldError("location_id")}>
              <option value="">Choose…</option>
              {[...new Map((balances.data ?? []).map((b) => [b.location!.id, b.location!])).values()].map((l) => (
                <option key={l.id} value={l.id}>
                  {l.name} ({formatQty((balances.data ?? []).filter((b) => b.location?.id === l.id).reduce((s, b) => s + (b.quantity ?? 0), 0), item?.unit)})
                </option>
              ))}
            </Select>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="quantity">Quantity{item ? ` (${item.unit})` : ""}</Label>
              <Input id="quantity" name="quantity" type="number" step="any" min="0" required aria-invalid={!!error?.fieldError("quantity")} />
              <FieldError>{error?.fieldError("quantity")}</FieldError>
            </div>
            {item?.tracks_lots ? (
              <div>
                <Label htmlFor="lot_id">Lot</Label>
                <Select id="lot_id" name="lot_id" defaultValue="">
                  <option value="">Oldest expiry first</option>
                  {here.map((b) => (
                    <option key={b.id} value={b.lot?.id ?? ""}>
                      {b.lot?.lot_number ?? b.lot?.code} · {formatQty(b.quantity)}
                      {b.lot?.expires_on ? ` · exp ${b.lot.expires_on}` : ""}
                    </option>
                  ))}
                </Select>
              </div>
            ) : null}
          </div>
          <SubjectPicker farmId={farmId} perms={perms} type={subjectType} onType={setSubjectType} id={subjectId} onId={setSubjectId} error={error} />
          <div>
            <Label htmlFor="occurred_at">When</Label>
            <Input id="occurred_at" name="occurred_at" type="datetime-local" defaultValue={nowLocal()} max={nowLocal()} />
          </div>
          <div>
            <Label htmlFor="note">Note</Label>
            <Input id="note" name="note" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function TransferDialog({ farmId, onClose, onDone }: Base) {
  const items = useItems(farmId);
  const [rows, setRows] = useState<Line[]>([newLine()]);
  return (
    <FormDialog
      title="Transfer between stores"
      description="Lots keep their identity; value moves at average cost."
      submitLabel="Transfer"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/inventory/transfers", {
          params: { path: { farm: farmId } },
          body: { from_location_id: String(f.get("from_location_id")), to_location_id: String(f.get("to_location_id")), note: text(f, "note"), lines: lines(rows) },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div className="grid grid-cols-2 gap-3">
            <StoreSelect farmId={farmId} id="from_location_id" name="from_location_id" label="From" required error={error?.fieldError("from_location_id")} />
            <StoreSelect farmId={farmId} id="to_location_id" name="to_location_id" label="To" required error={error?.fieldError("to_location_id")} />
          </div>
          <LinesEditor items={(items.data ?? []).filter((i) => (i.on_hand ?? 0) > 0)} lines={rows} onChange={setRows} error={error} />
          <div>
            <Label htmlFor="note">Note</Label>
            <Input id="note" name="note" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

/** A count for one store: every balance there, with the counted quantity. Only changed lines are sent. */
export function CountDialog({ farmId, onClose, onDone }: Base) {
  const [storeId, setStoreId] = useState("");
  const balances = useBalances(farmId, undefined, Boolean(storeId));
  const here = (balances.data ?? []).filter((b) => b.location?.id === storeId);
  const [counted, setCounted] = useState<Record<string, string>>({});
  return (
    <FormDialog
      title="Stock count"
      description="Enter what is on the shelf. Nothing changes until someone else approves the count."
      submitLabel="Submit count"
      onClose={onClose}
      onSubmit={async (f) => {
        const changed = here.filter((b) => counted[b.id!] !== undefined && counted[b.id!] !== "" && Number(counted[b.id!]) !== b.quantity);
        await api.POST("/farms/{farm}/inventory/adjustments", {
          params: { path: { farm: farmId } },
          body: {
            location_id: storeId,
            reason: String(f.get("reason")),
            lines: changed.map((b) => ({ item_id: b.item!.id!, lot_id: b.lot?.id ?? null, counted_quantity: Number(counted[b.id!]) })),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <StoreSelect farmId={farmId} id="location_id" required value={storeId} onChange={(e) => { setStoreId(e.target.value); setCounted({}); }} error={error?.fieldError("location_id")} />
          {storeId ? (
            here.length === 0 ? (
              <p className="text-sm text-muted">No stock recorded in this store.</p>
            ) : (
              <div className="space-y-2">
                {here.map((b) => (
                  <div key={b.id} className="flex items-center gap-3 text-sm">
                    <div className="min-w-0 flex-1">
                      {b.item?.name}
                      {b.lot ? <span className="block text-xs text-muted">{b.lot.lot_number ?? b.lot.code}</span> : null}
                    </div>
                    <span className="w-24 text-right tabular-nums text-muted">{formatQty(b.quantity, b.item?.unit)}</span>
                    <Input aria-label={`Counted ${b.item?.name} ${b.lot?.code ?? ""}`} type="number" step="any" min="0" className="w-28" placeholder="Counted" value={counted[b.id!] ?? ""} onChange={(e) => setCounted({ ...counted, [b.id!]: e.target.value })} />
                  </div>
                ))}
              </div>
            )
          ) : null}
          <FieldError>{error?.fieldError("lines")}</FieldError>
          <div>
            <Label htmlFor="reason">Reason</Label>
            <Input id="reason" name="reason" required minLength={3} placeholder="Monthly count" aria-invalid={!!error?.fieldError("reason")} />
            <FieldError>{error?.fieldError("reason")}</FieldError>
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function RequestDialog({ farmId, perms, onClose, onDone }: Base & { perms: Permissions | undefined }) {
  const items = useItems(farmId);
  const [rows, setRows] = useState<Line[]>([newLine()]);
  const [subjectType, setSubjectType] = useState("general");
  const [subjectId, setSubjectId] = useState("");
  return (
    <FormDialog
      title="Request stock"
      description="The store issues it once approved."
      submitLabel="Send request"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/inventory/requests", {
          params: { path: { farm: farmId } },
          body: {
            subject_type: subjectType as "general",
            subject_id: subjectType === "general" ? null : subjectId,
            location_id: text(f, "location_id"),
            needed_on: text(f, "needed_on"),
            note: text(f, "note"),
            lines: lines(rows).map(({ item_id, quantity }) => ({ item_id, quantity })),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <LinesEditor items={items.data ?? []} lines={rows} onChange={setRows} error={error} />
          <SubjectPicker farmId={farmId} perms={perms} type={subjectType} onType={setSubjectType} id={subjectId} onId={setSubjectId} error={error} />
          <div className="grid grid-cols-2 gap-3">
            <StoreSelect farmId={farmId} id="location_id" name="location_id" label="From store (optional)" />
            <div>
              <Label htmlFor="needed_on">Needed on</Label>
              <Input id="needed_on" name="needed_on" type="date" />
            </div>
          </div>
          <div>
            <Label htmlFor="note">Note</Label>
            <Input id="note" name="note" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

/** Issue what is still due on an approved request, in full or in part. */
export function IssueRequestDialog({ farmId, request, onClose, onDone }: Base & { request: InventoryRequest }) {
  const due = (request.lines ?? []).filter((l) => (l.quantity ?? 0) > (l.issued_quantity ?? 0));
  const [qty, setQty] = useState<Record<string, string>>(Object.fromEntries(due.map((l) => [l.id!, String(Math.round(((l.quantity ?? 0) - (l.issued_quantity ?? 0)) * 1000) / 1000)])));
  return (
    <FormDialog
      title={`Issue ${request.code}`}
      description={`For ${request.subject?.label ?? "general use"}. Oldest expiry first.`}
      submitLabel="Issue"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/inventory/requests/{inventoryRequest}/issue", {
          params: { path: { farm: farmId, inventoryRequest: request.id! } },
          body: {
            location_id: String(f.get("location_id")),
            note: text(f, "note"),
            lines: due.filter((l) => Number(qty[l.id!] || 0) > 0).map((l) => ({ line_id: l.id!, quantity: Number(qty[l.id!]) })),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <StoreSelect farmId={farmId} id="location_id" name="location_id" required defaultValue={request.location?.id ?? ""} error={error?.fieldError("location_id")} />
          <div className="space-y-2">
            {due.map((l, i) => (
              <div key={l.id} className="flex items-center gap-3 text-sm">
                <span className="min-w-0 flex-1">
                  {l.item?.name}
                  <span className="block text-xs text-muted">
                    {formatQty(l.issued_quantity, l.item?.unit)} of {formatQty(l.quantity, l.item?.unit)} issued
                  </span>
                </span>
                <Input aria-label={`Issue ${l.item?.name}`} type="number" step="any" min="0" className="w-28" value={qty[l.id!] ?? ""} onChange={(e) => setQty({ ...qty, [l.id!]: e.target.value })} />
                <FieldError>{error?.fieldError(`lines.${i}.quantity`)}</FieldError>
              </div>
            ))}
          </div>
          <div>
            <Label htmlFor="note">Note</Label>
            <Input id="note" name="note" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

/** A decision that needs a reason: rejecting, cancelling, reversing. */
export function ReasonDialog({
  title,
  label = "Reason",
  submitLabel,
  field = "note",
  onClose,
  onSubmit,
}: {
  title: string;
  label?: string;
  submitLabel: string;
  field?: string;
  onClose: () => void;
  onSubmit: (reason: string) => Promise<void>;
}) {
  return (
    <FormDialog title={title} submitLabel={submitLabel} onClose={onClose} onSubmit={async (f) => onSubmit(String(f.get("reason")))}>
      {(error) => (
        <div>
          <Label htmlFor="reason">{label}</Label>
          <Textarea id="reason" name="reason" required minLength={3} className="min-h-20" aria-invalid={!!error?.fieldError(field)} />
          <FieldError>{error?.fieldError(field)}</FieldError>
        </div>
      )}
    </FormDialog>
  );
}
