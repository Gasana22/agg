"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Checkbox, FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { ApiError } from "@/lib/api/errors";
import { humanize } from "@/lib/format";
import {
  type AnyNode,
  type Block,
  IRRIGATION,
  LAND_USES,
  LOCATION_KINDS,
  NODE_LABEL,
  type NodeType,
  type Plot,
  type Section,
  type Warning,
} from "@/lib/structure";
import { createNode, updateNode } from "@/lib/structure-api";

export type NodeDialogState = { mode: "create"; type: NodeType; parentId?: string } | { mode: "edit"; type: NodeType; node: AnyNode } | null;

type Props = {
  farmId: string;
  state: NodeDialogState;
  blocks: Block[];
  sections: Section[];
  plots: Plot[];
  onClose: () => void;
  /** Called after a save; `drawNext` asks the page to start drawing the new node's shape. */
  onSaved: (node: AnyNode, warnings: Warning[], drawNext: boolean) => void;
};

/** Create or edit a block, section, plot or location. Shapes are drawn on the map. */
export function NodeDialog({ farmId, state, blocks, sections, plots, onClose, onSaved }: Props) {
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);
  if (!state) return null;

  const type = state.type;
  const node = state.mode === "edit" ? (state.node as Record<string, unknown>) : undefined;
  const value = (key: string) => (node?.[key] as string | number | null | undefined) ?? "";
  const parentDefault = state.mode === "create" ? state.parentId ?? "" : "";

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    const f = new FormData(e.currentTarget);
    const text = (k: string) => {
      const v = String(f.get(k) ?? "").trim();
      return v === "" ? null : v;
    };
    const body: Record<string, unknown> = {
      name: text("name"),
      description: text("description"),
      declared_area_ha: text("declared_area_ha") === null ? null : Number(f.get("declared_area_ha")),
    };
    if (state!.mode === "create" ? text("code") !== null : true) body.code = text("code");
    if (type === "section") body.block_id = text("block_id");
    if (type === "plot") Object.assign(body, { section_id: text("section_id"), land_use: text("land_use"), irrigation: text("irrigation") });
    if (type === "location") Object.assign(body, { kind: text("kind"), plot_id: text("plot_id") });
    if (state!.mode === "edit") body.version = state!.node.version;

    try {
      const result =
        state!.mode === "create" ? await createNode(farmId, type, body) : await updateNode(farmId, type, state!.node.id!, body);
      onSaved(result.data, result.meta?.warnings ?? [], state!.mode === "create" && f.get("draw_next") === "on");
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    } finally {
      setBusy(false);
    }
  }

  const title = `${state.mode === "create" ? "Add" : "Edit"} ${NODE_LABEL[type].toLowerCase()}`;

  return (
    <Dialog open onClose={onClose} title={title}>
      <form onSubmit={submit} className="space-y-3">
        <div className="grid grid-cols-3 gap-3">
          <div className="col-span-2">
            <Label htmlFor="name">Name</Label>
            <Input id="name" name="name" required maxLength={120} defaultValue={value("name")} aria-invalid={!!error?.fieldError("name")} />
            <FieldError>{error?.fieldError("name")}</FieldError>
          </div>
          <div>
            <Label htmlFor="code">Code</Label>
            <Input id="code" name="code" maxLength={30} placeholder="Auto" defaultValue={value("code")} required={state.mode === "edit"} aria-invalid={!!error?.fieldError("code")} />
            <FieldError>{error?.fieldError("code")}</FieldError>
          </div>
        </div>

        {type === "section" ? (
          <div>
            <Label htmlFor="block_id">Block</Label>
            <Select id="block_id" name="block_id" required defaultValue={(value("block_id") as string) || parentDefault}>
              <option value="">Choose a block…</option>
              {blocks.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.code} · {b.name}
                </option>
              ))}
            </Select>
            <FieldError>{error?.fieldError("block_id")}</FieldError>
          </div>
        ) : null}

        {type === "plot" ? (
          <>
            <div>
              <Label htmlFor="section_id">Section</Label>
              <Select id="section_id" name="section_id" defaultValue={(value("section_id") as string) || parentDefault}>
                <option value="">None (directly under the farm)</option>
                {sections.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.code} · {s.name}
                  </option>
                ))}
              </Select>
              <FieldError>{error?.fieldError("section_id")}</FieldError>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label htmlFor="land_use">Land use</Label>
                <Select id="land_use" name="land_use" defaultValue={(value("land_use") as string) || "crop"}>
                  {LAND_USES.map((v) => (
                    <option key={v} value={v}>
                      {humanize(v)}
                    </option>
                  ))}
                </Select>
              </div>
              <div>
                <Label htmlFor="irrigation">Water</Label>
                <Select id="irrigation" name="irrigation" defaultValue={(value("irrigation") as string) || "rainfed"}>
                  {IRRIGATION.map((v) => (
                    <option key={v} value={v}>
                      {humanize(v)}
                    </option>
                  ))}
                </Select>
              </div>
            </div>
          </>
        ) : null}

        {type === "location" ? (
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="kind">Kind</Label>
              <Select id="kind" name="kind" required defaultValue={(value("kind") as string) || "store"}>
                {LOCATION_KINDS.map((v) => (
                  <option key={v} value={v}>
                    {humanize(v)}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="plot_id">On plot</Label>
              <Select id="plot_id" name="plot_id" defaultValue={(value("plot_id") as string) || parentDefault}>
                <option value="">—</option>
                {plots.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.code} · {p.name}
                  </option>
                ))}
              </Select>
            </div>
          </div>
        ) : null}

        <div>
          <Label htmlFor="declared_area_ha">Declared area (ha)</Label>
          <Input id="declared_area_ha" name="declared_area_ha" type="number" min="0" step="0.0001" defaultValue={value("declared_area_ha")} aria-describedby="declared-help" />
          <p id="declared-help" className="mt-1 text-xs text-muted">
            Optional. A drawn boundary gives the measured area.
          </p>
        </div>

        <div>
          <Label htmlFor="description">Notes</Label>
          <Textarea id="description" name="description" rows={2} maxLength={2000} defaultValue={value("description")} />
        </div>

        {state.mode === "create" ? (
          <Checkbox name="draw_next" defaultChecked label={type === "location" ? "Place it on the map next" : "Draw its boundary on the map next"} />
        ) : null}

        {error && !error.problem.errors ? <p className="text-sm text-danger" role="alert">{error.problem.title}</p> : null}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" disabled={busy}>
            {busy ? "Saving…" : "Save"}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
