"use client";

import { useQuery } from "@tanstack/react-query";
import { Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { FormDialog, num, text } from "@/components/forms/form-dialog";
import { KIND_LABELS } from "@/components/trace/labels";
import { Button } from "@/components/ui/button";
import { FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { api } from "@/lib/api/client";
import { formatQty, isUsable, type Batch, type Shipment, type TimelineEvent } from "@/lib/trace";

type Base = { farmId: string; onClose: () => void; onDone: (id?: string) => void };
const nowLocal = () => {
  const d = new Date();
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 16);
};
const when = (f: FormData, key = "occurred_at") => {
  const v = text(f, key);
  return v ? new Date(v).toISOString() : undefined;
};

/** Open batches that still hold something, for picking inputs. */
export function useUsableBatches(farmId: string, enabled = true) {
  return useQuery({
    queryKey: ["batches", farmId, "usable"],
    queryFn: async () =>
      ((await api.GET("/farms/{farm}/traceability/batches", { params: { path: { farm: farmId }, query: { "filter[status]": "open", per_page: 100 } } })).data!.data ?? []).filter(
        (b) => isUsable(b) && (b.available === null || Number(b.available?.value) > 0),
      ),
    enabled,
  });
}

function WhenField() {
  return (
    <div>
      <Label htmlFor="occurred_at">When</Label>
      <Input id="occurred_at" name="occurred_at" type="datetime-local" defaultValue={nowLocal()} max={nowLocal()} />
    </div>
  );
}

function Available({ batch }: { batch: Batch }) {
  return (
    <p className="rounded-md bg-surface-muted px-3 py-2 text-sm">
      <span className="font-mono">{batch.batch_code}</span> has <strong>{formatQty(batch.available ?? batch.quantity)}</strong> left.
    </p>
  );
}

export function SplitDialog({ farmId, batch, onClose, onDone }: Base & { batch: Batch }) {
  const [parts, setParts] = useState([0]);
  return (
    <FormDialog
      title={`Split ${batch.batch_code}`}
      description="Take part of this batch into new batches of the same kind, e.g. a portion for drying. What you do not take stays here."
      submitLabel="Split"
      onClose={onClose}
      onSubmit={async (f) => {
        const res = await api.POST("/farms/{farm}/traceability/batches/{batch}/split", {
          params: { path: { farm: farmId, batch: batch.id! } },
          body: { parts: parts.map((i) => ({ quantity: Number(f.get(`qty_${i}`)), name: text(f, `name_${i}`) })), occurred_at: when(f), notes: text(f, "notes") },
        });
        onDone(res.data?.data?.parts?.[0]?.id);
      }}
    >
      {(error) => (
        <>
          <Available batch={batch} />
          {parts.map((i, idx) => (
            <div key={i} className="grid grid-cols-[1fr_8rem_auto] items-end gap-2">
              <div>
                <Label htmlFor={`name_${i}`}>Part {idx + 1} name</Label>
                <Input id={`name_${i}`} name={`name_${i}`} placeholder={`${batch.name ?? batch.batch_code} · part ${idx + 1}`} />
              </div>
              <div>
                <Label htmlFor={`qty_${i}`}>Quantity ({batch.quantity?.unit})</Label>
                <Input id={`qty_${i}`} name={`qty_${i}`} type="number" step="any" min="0" required aria-invalid={!!error?.fieldError(`parts.${idx}.quantity`)} />
              </div>
              <Button type="button" variant="ghost" size="icon" aria-label={`Remove part ${idx + 1}`} disabled={parts.length === 1} onClick={() => setParts(parts.filter((p) => p !== i))}>
                <Trash2 />
              </Button>
            </div>
          ))}
          <Button type="button" variant="secondary" size="sm" onClick={() => setParts([...parts, Math.max(...parts) + 1])}>
            <Plus /> Another part
          </Button>
          <WhenField />
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" rows={2} />
          </div>
          <FieldError>{error?.fieldError("parts")}</FieldError>
        </>
      )}
    </FormDialog>
  );
}

type Mode = "process" | "package" | "merge";
const MODE_TEXT: Record<Mode, { title: string; description: string; submit: string; placeholder: string }> = {
  process: { title: "Process", description: "Dry, grade, mill or clean batches into a new product. Its weight can differ from what went in.", submit: "Record processing", placeholder: "Dried & graded maize" },
  package: { title: "Pack", description: "Pack batches into a packaged product ready to sell.", submit: "Record packing", placeholder: "Maize grain 50 kg bags" },
  merge: { title: "Merge", description: "Combine batches of the same kind and unit, e.g. two harvests into one store bin.", submit: "Merge", placeholder: "Maize store bin 2" },
};

export function TransformDialog({ farmId, batch, mode, onClose, onDone }: Base & { batch: Batch; mode: Mode }) {
  const usable = useUsableBatches(farmId);
  const [others, setOthers] = useState<{ key: number; id: string }[]>(mode === "merge" ? [{ key: 0, id: "" }] : []);
  const t = MODE_TEXT[mode];
  const candidates = (usable.data ?? []).filter((b) => b.id !== batch.id && (mode !== "merge" || (b.kind === batch.kind && b.quantity?.unit === batch.quantity?.unit)));
  const unit = batch.quantity?.unit ?? "";

  return (
    <FormDialog
      title={`${t.title} ${batch.batch_code}`}
      description={t.description}
      submitLabel={t.submit}
      onClose={onClose}
      onSubmit={async (f) => {
        const path = { farm: farmId, batch: batch.id! };
        const inputs = {
          quantity: num(f, "quantity"),
          with: others.some((o) => o.id) ? others.filter((o) => o.id).map((o) => ({ batch_id: o.id, quantity: num(f, `with_qty_${o.key}`) })) : undefined,
          occurred_at: when(f),
          notes: text(f, "notes"),
        };
        const output = { name: String(f.get("output_name")), quantity: num(f, "output_quantity"), unit: text(f, "output_unit") };
        const res =
          mode === "merge"
            ? await api.POST("/farms/{farm}/traceability/batches/{batch}/merge", { params: { path }, body: { ...inputs, with: inputs.with ?? [], name: text(f, "output_name") } })
            : mode === "process"
              ? await api.POST("/farms/{farm}/traceability/batches/{batch}/process", { params: { path }, body: { ...inputs, output: { ...output, method: text(f, "method") } } })
              : await api.POST("/farms/{farm}/traceability/batches/{batch}/package", {
                  params: { path },
                  body: { ...inputs, output: { ...output, package_count: num(f, "package_count"), package_size: text(f, "package_size") } },
                });
        onDone(res.data?.data?.id);
      }}
    >
      {(error) => (
        <>
          <Available batch={batch} />
          <div>
            <Label htmlFor="quantity">From this batch ({unit || "all of it"})</Label>
            <Input id="quantity" name="quantity" type="number" step="any" min="0" placeholder="All that is left" disabled={!batch.quantity} aria-invalid={!!error?.fieldError("quantity")} />
            <FieldError>{error?.fieldError("quantity")}</FieldError>
          </div>
          <FieldError>{error?.fieldError("with")}</FieldError>
          {others.map((o, idx) => (
            <div key={o.key} className="grid grid-cols-[1fr_7rem_auto] items-end gap-2">
              <div>
                <Label htmlFor={`with_${o.key}`}>{mode === "merge" ? "Merge with" : "Also from"}</Label>
                <Select id={`with_${o.key}`} value={o.id} onChange={(e) => setOthers(others.map((x) => (x.key === o.key ? { ...x, id: e.target.value } : x)))} required={mode === "merge"}>
                  <option value="">Choose a batch…</option>
                  {candidates.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.batch_code} · {KIND_LABELS[b.kind ?? ""]} · {b.name ?? ""} ({formatQty(b.available ?? b.quantity)})
                    </option>
                  ))}
                </Select>
                <FieldError>{error?.fieldError(`with.${idx}.batch_id`)}</FieldError>
              </div>
              <div>
                <Label htmlFor={`with_qty_${o.key}`}>Quantity</Label>
                <Input id={`with_qty_${o.key}`} name={`with_qty_${o.key}`} type="number" step="any" min="0" placeholder="All" />
              </div>
              <Button type="button" variant="ghost" size="icon" aria-label="Remove batch" disabled={mode === "merge" && others.length === 1} onClick={() => setOthers(others.filter((x) => x.key !== o.key))}>
                <Trash2 />
              </Button>
            </div>
          ))}
          <Button type="button" variant="secondary" size="sm" onClick={() => setOthers([...others, { key: Math.max(0, ...others.map((x) => x.key)) + 1, id: "" }])}>
            <Plus /> {mode === "merge" ? "Another batch" : "Add another input batch"}
          </Button>
          <div>
            <Label htmlFor="output_name">{mode === "merge" ? "Name of the merged batch" : "Product name"}</Label>
            <Input id="output_name" name="output_name" required={mode !== "merge"} placeholder={t.placeholder} aria-invalid={!!error?.fieldError("output.name")} />
            <FieldError>{error?.fieldError("output.name")}</FieldError>
          </div>
          {mode !== "merge" ? (
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label htmlFor="output_quantity">Quantity out</Label>
                <Input id="output_quantity" name="output_quantity" type="number" step="any" min="0" placeholder="Same as in" />
              </div>
              <div>
                <Label htmlFor="output_unit">Unit</Label>
                <Input id="output_unit" name="output_unit" defaultValue={unit} />
                <FieldError>{error?.fieldError("output.unit")}</FieldError>
              </div>
              {mode === "process" ? (
                <div className="col-span-2">
                  <Label htmlFor="method">Method</Label>
                  <Input id="method" name="method" placeholder="Sun-dried to 13% moisture, graded" />
                </div>
              ) : (
                <>
                  <div>
                    <Label htmlFor="package_count">Packages</Label>
                    <Input id="package_count" name="package_count" type="number" min="1" step="1" placeholder="10" />
                  </div>
                  <div>
                    <Label htmlFor="package_size">Package size</Label>
                    <Input id="package_size" name="package_size" placeholder="50 kg" />
                  </div>
                </>
              )}
            </div>
          ) : null}
          <WhenField />
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" rows={2} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function RecallDialog({ farmId, batch, onClose, onDone }: Base & { batch: Batch }) {
  return (
    <FormDialog
      title={`Recall ${batch.batch_code}`}
      description="Everything made from this batch is recalled too, including shipments already with customers. This cannot be undone."
      submitLabel="Recall"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/traceability/batches/{batch}/recall", { params: { path: { farm: farmId, batch: batch.id! } }, body: { reason: String(f.get("reason")) } });
        onDone();
      }}
    >
      {(error) => (
        <div>
          <Label htmlFor="reason">Reason</Label>
          <Textarea id="reason" name="reason" required minLength={3} rows={3} placeholder="Aflatoxin test above the limit" aria-invalid={!!error?.fieldError("reason")} />
          <FieldError>{error?.fieldError("reason")}</FieldError>
        </div>
      )}
    </FormDialog>
  );
}

/** Correct an event: a new event with the right values; the original stays. */
export function CorrectDialog({ farmId, event, onClose, onDone }: Base & { event: TimelineEvent }) {
  const fields = Object.entries(event.payload ?? {}).filter(([, v]) => v === null || ["string", "number", "boolean"].includes(typeof v));
  return (
    <FormDialog
      title="Correct this event"
      description="The original stays in the history; the correction is added next to it and shown instead."
      submitLabel="Add correction"
      onClose={onClose}
      onSubmit={async (f) => {
        const payload: Record<string, string> = {};
        for (const [k, v] of fields) {
          const next = String(f.get(`field_${k}`) ?? "");
          if (next !== String(v ?? "")) payload[k] = next;
        }
        await api.POST("/farms/{farm}/traceability/events/{event}/corrections", {
          params: { path: { farm: farmId, event: event.id! } },
          body: { payload, reason: String(f.get("reason")) },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          {fields.map(([k, v]) => (
            <div key={k}>
              <Label htmlFor={`field_${k}`}>{k.replaceAll("_", " ")}</Label>
              <Input id={`field_${k}`} name={`field_${k}`} defaultValue={String(v ?? "")} />
            </div>
          ))}
          <div>
            <Label htmlFor="reason">Why</Label>
            <Input id="reason" name="reason" required placeholder="Scale was off by 10 kg" aria-invalid={!!error?.fieldError("reason")} />
            <FieldError>{error?.fieldError("reason") ?? error?.fieldError("payload")}</FieldError>
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function DispatchDialog({ farmId, batch, customers, onClose, onDone }: Base & { batch?: Batch; customers: { id?: string; name?: string; code?: string; address?: string | null }[] }) {
  const usable = useUsableBatches(farmId);
  const [lines, setLines] = useState([{ key: 0, id: batch?.id ?? "" }]);
  return (
    <FormDialog
      title="Dispatch to a customer"
      description="The goods leave the farm: each batch gives the quantity sent, and the shipment joins their journey."
      submitLabel="Dispatch"
      onClose={onClose}
      onSubmit={async (f) => {
        const res = await api.POST("/farms/{farm}/shipments", {
          params: { path: { farm: farmId } },
          body: {
            customer_id: String(f.get("customer_id")),
            destination: text(f, "destination"),
            vehicle: text(f, "vehicle"),
            driver: text(f, "driver"),
            notes: text(f, "notes"),
            dispatched_at: when(f, "dispatched_at"),
            lines: lines.filter((l) => l.id).map((l) => ({ batch_id: l.id, quantity: num(f, `qty_${l.key}`) })),
          },
        });
        onDone(res.data?.data?.id);
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="customer_id">Customer</Label>
            <Select id="customer_id" name="customer_id" required aria-invalid={!!error?.fieldError("customer_id")}>
              <option value="">Choose…</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name} ({c.code})
                </option>
              ))}
            </Select>
            <FieldError>{error?.fieldError("customer_id")}</FieldError>
          </div>
          {lines.map((l, idx) => (
            <div key={l.key} className="grid grid-cols-[1fr_7rem_auto] items-end gap-2">
              <div>
                <Label htmlFor={`batch_${l.key}`}>Batch</Label>
                <Select id={`batch_${l.key}`} value={l.id} required onChange={(e) => setLines(lines.map((x) => (x.key === l.key ? { ...x, id: e.target.value } : x)))}>
                  <option value="">Choose a batch…</option>
                  {(usable.data ?? []).map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.batch_code} · {b.name ?? KIND_LABELS[b.kind ?? ""]} ({formatQty(b.available ?? b.quantity)})
                    </option>
                  ))}
                </Select>
                <FieldError>{error?.fieldError(`lines.${idx}.batch_id`)}</FieldError>
              </div>
              <div>
                <Label htmlFor={`qty_${l.key}`}>Quantity</Label>
                <Input id={`qty_${l.key}`} name={`qty_${l.key}`} type="number" step="any" min="0" placeholder="All" />
              </div>
              <Button type="button" variant="ghost" size="icon" aria-label="Remove line" disabled={lines.length === 1} onClick={() => setLines(lines.filter((x) => x.key !== l.key))}>
                <Trash2 />
              </Button>
            </div>
          ))}
          <Button type="button" variant="secondary" size="sm" onClick={() => setLines([...lines, { key: Math.max(...lines.map((x) => x.key)) + 1, id: "" }])}>
            <Plus /> Another batch
          </Button>
          <div className="grid grid-cols-2 gap-3">
            <div className="col-span-2">
              <Label htmlFor="destination">Destination</Label>
              <Input id="destination" name="destination" placeholder="The customer's address" />
            </div>
            <div>
              <Label htmlFor="vehicle">Vehicle</Label>
              <Input id="vehicle" name="vehicle" placeholder="UBA 123X" />
            </div>
            <div>
              <Label htmlFor="driver">Driver</Label>
              <Input id="driver" name="driver" />
            </div>
            <div className="col-span-2">
              <Label htmlFor="dispatched_at">Dispatched</Label>
              <Input id="dispatched_at" name="dispatched_at" type="datetime-local" defaultValue={nowLocal()} max={nowLocal()} />
            </div>
          </div>
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" rows={2} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function DeliverDialog({ farmId, shipment, onClose, onDone }: Base & { shipment: Shipment }) {
  return (
    <FormDialog
      title={`Delivered: ${shipment.code}`}
      description={`Confirm that ${shipment.customer?.name} received the goods.`}
      submitLabel="Confirm delivery"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/shipments/{shipment}/deliver", {
          params: { path: { farm: farmId, shipment: shipment.id! } },
          body: { received_by: text(f, "received_by"), delivered_at: when(f, "delivered_at"), notes: text(f, "notes") },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="received_by">Received by</Label>
            <Input id="received_by" name="received_by" placeholder="Name of the person who signed" />
          </div>
          <div>
            <Label htmlFor="delivered_at">Delivered</Label>
            <Input id="delivered_at" name="delivered_at" type="datetime-local" defaultValue={nowLocal()} max={nowLocal()} aria-invalid={!!error?.fieldError("delivered_at")} />
            <FieldError>{error?.fieldError("delivered_at")}</FieldError>
          </div>
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" rows={2} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function FailDialog({ farmId, shipment, onClose, onDone }: Base & { shipment: Shipment }) {
  return (
    <FormDialog
      title={`Not delivered: ${shipment.code}`}
      submitLabel="Record"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/shipments/{shipment}/fail", { params: { path: { farm: farmId, shipment: shipment.id! } }, body: { reason: String(f.get("reason")) } });
        onDone();
      }}
    >
      {(error) => (
        <div>
          <Label htmlFor="reason">What happened</Label>
          <Textarea id="reason" name="reason" required minLength={3} rows={3} placeholder="Customer's store was closed; goods returned" aria-invalid={!!error?.fieldError("reason")} />
          <FieldError>{error?.fieldError("reason")}</FieldError>
        </div>
      )}
    </FormDialog>
  );
}
