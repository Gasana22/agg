"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { EmptyState, ErrorNotice, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { formatQuantity } from "@/lib/crops";

import { FormDialog, num, text } from "@/components/forms/form-dialog";
import { useFarmCrops, useSeasons } from "./queries";

const PLAN_TONE: Record<string, "neutral" | "primary" | "warning"> = { draft: "warning", approved: "primary", active: "primary", closed: "neutral" };

export function PlansPanel({
  farmId,
  canManage,
  canApprove,
  seesMoney,
  currency,
  startOpen,
}: {
  farmId: string;
  canManage: boolean;
  canApprove: boolean;
  seesMoney: boolean;
  currency: string;
  startOpen: boolean;
}) {
  const queryClient = useQueryClient();
  const [creating, setCreating] = useState(startOpen);
  const [error, setError] = useState<ApiError | null>(null);
  const plans = useQuery({
    queryKey: ["crop-plans", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/crop-plans", { params: { path: { farm: farmId }, query: { per_page: 100 } } })).data!.data!,
  });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["crop-plans", farmId] });

  async function act(fn: () => Promise<unknown>) {
    setError(null);
    try {
      await fn();
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  return (
    <>
      <div className="mb-3 flex items-center justify-between">
        <p className="text-sm text-muted">Plans set what to grow in a season. Cycles can start against a plan once it is approved.</p>
        {canManage ? (
          <Button size="sm" onClick={() => setCreating(true)}>
            <Plus /> New plan
          </Button>
        ) : null}
      </div>
      {error ? (
        <div className="mb-3">
          <ErrorNotice error={error} />
        </div>
      ) : null}
      {plans.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : plans.error ? (
        <ErrorNotice error={plans.error} />
      ) : plans.data!.length === 0 ? (
        <EmptyState title="No crop plans yet" />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Plan</Th>
              <Th>Season</Th>
              <Th>Crop</Th>
              <Th className="text-right">Area (planned / planted)</Th>
              <Th className="text-right">Expected yield</Th>
              {seesMoney ? <Th className="text-right">Budget</Th> : null}
              <Th>Status</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {plans.data!.map((p) => (
              <tr key={p.id}>
                <Td>
                  <span className="font-mono text-xs text-muted">{p.code}</span> <span className="font-medium">{p.name}</span>
                  <span className="block text-xs text-muted">{p.cycles_count ?? 0} cycles</span>
                </Td>
                <Td className="text-muted">{p.season?.name}</Td>
                <Td>{p.crop?.label}</Td>
                <Td className="text-right tabular-nums">
                  {p.planned_area_ha} / {p.planted_area_ha ?? 0} ha
                </Td>
                <Td className="text-right tabular-nums">{formatQuantity(p.expected_yield, p.yield_unit)}</Td>
                {seesMoney ? <Td className="text-right tabular-nums">{p.budget_amount != null ? `${currency} ${p.budget_amount.toLocaleString()}` : "—"}</Td> : null}
                <Td>
                  <Badge tone={PLAN_TONE[p.status ?? "draft"]}>{p.status}</Badge>
                </Td>
                <Td className="text-right">
                  {p.status === "draft" && canApprove ? (
                    <Button size="sm" variant="secondary" onClick={() => act(() => api.POST("/farms/{farm}/crop-plans/{crop_plan}/approve", { params: { path: { farm: farmId, crop_plan: p.id! } } }))}>
                      Approve
                    </Button>
                  ) : null}
                  {p.status !== "closed" && p.status !== "draft" && canManage ? (
                    <Button size="sm" variant="ghost" onClick={() => act(() => api.POST("/farms/{farm}/crop-plans/{crop_plan}/close", { params: { path: { farm: farmId, crop_plan: p.id! } } }))}>
                      Close
                    </Button>
                  ) : null}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {creating ? (
        <PlanDialog
          farmId={farmId}
          seesMoney={seesMoney}
          currency={currency}
          onClose={() => setCreating(false)}
          onDone={async () => {
            setCreating(false);
            await refresh();
          }}
        />
      ) : null}
    </>
  );
}

function PlanDialog({ farmId, seesMoney, currency, onClose, onDone }: { farmId: string; seesMoney: boolean; currency: string; onClose: () => void; onDone: () => void }) {
  const crops = useFarmCrops(farmId);
  const seasons = useSeasons(farmId);

  return (
    <FormDialog
      title="New crop plan"
      description="Drafts need the owner's approval before cycles can start against them."
      submitLabel="Save draft"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/crop-plans", {
          params: { path: { farm: farmId } },
          body: {
            name: String(f.get("name")),
            season_id: String(f.get("season_id")),
            crop_id: String(f.get("crop_id")),
            planned_area_ha: Number(f.get("planned_area_ha")),
            expected_yield: num(f, "expected_yield"),
            budget_amount: seesMoney ? num(f, "budget_amount") : undefined,
            notes: text(f, "notes"),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div>
            <Label htmlFor="name">Name</Label>
            <Input id="name" name="name" required maxLength={120} placeholder="Maize, Block A" />
            <FieldError>{error?.fieldError("name")}</FieldError>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="season_id">Season</Label>
              <Select id="season_id" name="season_id" required defaultValue="">
                <option value="">Choose…</option>
                {seasons.data?.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </Select>
              <FieldError>{error?.fieldError("season_id")}</FieldError>
            </div>
            <div>
              <Label htmlFor="crop_id">Crop</Label>
              <Select id="crop_id" name="crop_id" required defaultValue="">
                <option value="">Choose…</option>
                {crops.data?.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.label}
                  </option>
                ))}
              </Select>
              <FieldError>{error?.fieldError("crop_id")}</FieldError>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="planned_area_ha">Planned area (ha)</Label>
              <Input id="planned_area_ha" name="planned_area_ha" type="number" min="0" step="0.01" required />
              <FieldError>{error?.fieldError("planned_area_ha")}</FieldError>
            </div>
            <div>
              <Label htmlFor="expected_yield">Expected yield (crop unit)</Label>
              <Input id="expected_yield" name="expected_yield" type="number" min="0" step="0.001" />
            </div>
          </div>
          {seesMoney ? (
            <div>
              <Label htmlFor="budget_amount">Budget ({currency})</Label>
              <Input id="budget_amount" name="budget_amount" type="number" min="0" step="1" />
            </div>
          ) : null}
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" rows={2} />
          </div>
        </>
      )}
    </FormDialog>
  );
}
