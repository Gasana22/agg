"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { FieldError, Input, Label, Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

import { useFarmCrops, useSeasons } from "./queries";
import { UnitSelect } from "./unit-select";

/** The farm's crop list and seasons. */
export function SetupPanel({ farmId, canManage }: { farmId: string; canManage: boolean }) {
  const queryClient = useQueryClient();
  const crops = useFarmCrops(farmId, true);
  const seasons = useSeasons(farmId);
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ["farm-crops", farmId] });
    void queryClient.invalidateQueries({ queryKey: ["seasons", farmId] });
  };

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <section>
        <h2 className="mb-3 text-lg font-semibold">Crops grown on this farm</h2>
        {canManage ? <AddCropForm farmId={farmId} onAdded={refresh} /> : null}
        {crops.error ? <ErrorNotice error={crops.error} /> : null}
        {crops.data?.length === 0 ? (
          <EmptyState title="No crops yet">Add the crops and varieties you grow.</EmptyState>
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Crop</Th>
                <Th>Maturity</Th>
                <Th>Yield unit</Th>
                {canManage ? <Th /> : null}
              </tr>
            </thead>
            <tbody>
              {crops.data?.map((c) => (
                <tr key={c.id} className={c.is_active ? undefined : "opacity-60"}>
                  <Td className="font-medium">
                    {c.label}
                    {!c.is_active ? (
                      <Badge tone="neutral" className="ml-2">
                        inactive
                      </Badge>
                    ) : null}
                  </Td>
                  <Td className="text-muted">{c.maturity_days ? `${c.maturity_days} days` : "—"}</Td>
                  <Td className="text-muted">{c.yield_unit}</Td>
                  {canManage ? (
                    <Td className="text-right">
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={async () => {
                          await api.PATCH("/farms/{farm}/crops/{crop}", { params: { path: { farm: farmId, crop: c.id! } }, body: { is_active: !c.is_active } });
                          refresh();
                        }}
                      >
                        {c.is_active ? "Deactivate" : "Reactivate"}
                      </Button>
                    </Td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </section>

      <section>
        <h2 className="mb-3 text-lg font-semibold">Seasons</h2>
        {canManage ? <AddSeasonForm farmId={farmId} onAdded={refresh} /> : null}
        {seasons.data?.length === 0 ? (
          <EmptyState title="No seasons yet">For example “2026 Season A”, March to July.</EmptyState>
        ) : (
          <Table>
            <thead>
              <tr>
                <Th>Season</Th>
                <Th>From</Th>
                <Th>To</Th>
              </tr>
            </thead>
            <tbody>
              {seasons.data?.map((s) => (
                <tr key={s.id}>
                  <Td className="font-medium">{s.name}</Td>
                  <Td className="text-muted">{s.starts_on}</Td>
                  <Td className="text-muted">{s.ends_on}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </section>
    </div>
  );
}

function AddCropForm({ farmId, onAdded }: { farmId: string; onAdded: () => void }) {
  const [cropId, setCropId] = useState("");
  const [error, setError] = useState<ApiError | null>(null);
  const catalog = useQuery({
    queryKey: ["catalog", "crops"],
    queryFn: async () => (await api.GET("/catalog/{catalog}", { params: { path: { catalog: "crops" } } })).data!.data!,
    staleTime: 3_600_000,
  });
  const varieties = useQuery({
    queryKey: ["catalog", "crop-varieties", cropId],
    queryFn: async () => (await api.GET("/catalog/{catalog}", { params: { path: { catalog: "crop-varieties" }, query: { "filter[parent_id]": cropId } } })).data!.data!,
    enabled: cropId !== "" && cropId !== "custom",
  });

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    const f = new FormData(e.currentTarget);
    const variety = String(f.get("variety_pick") ?? "");
    try {
      await api.POST("/farms/{farm}/crops", {
        params: { path: { farm: farmId } },
        body:
          cropId === "custom"
            ? { name: String(f.get("name")), variety: String(f.get("variety") || "") || null, yield_unit: String(f.get("yield_unit")) }
            : {
                global_crop_id: cropId,
                global_variety_id: variety && variety !== "other" ? variety : null,
                variety: variety === "other" ? String(f.get("variety") || "") || null : null,
                yield_unit: String(f.get("yield_unit")),
              },
      });
      e.currentTarget?.reset();
      setCropId("");
      onAdded();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  return (
    <Card className="mb-3">
      <CardContent>
        <form onSubmit={submit} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="catalog_crop">Crop</Label>
              <Select id="catalog_crop" value={cropId} onChange={(e) => setCropId(e.target.value)} required>
                <option value="">Choose from the catalogue…</option>
                {catalog.data?.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
                <option value="custom">Other (not in the catalogue)</option>
              </Select>
            </div>
            {cropId === "custom" ? (
              <div>
                <Label htmlFor="name">Name</Label>
                <Input id="name" name="name" required maxLength={120} />
              </div>
            ) : (
              <div>
                <Label htmlFor="variety_pick">Variety</Label>
                <Select id="variety_pick" name="variety_pick" defaultValue="" disabled={!cropId}>
                  <option value="">Any / not specified</option>
                  {varieties.data?.map((v) => (
                    <option key={v.id} value={v.id}>
                      {v.name}
                    </option>
                  ))}
                  <option value="other">Other…</option>
                </Select>
              </div>
            )}
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="variety">Variety (if not listed)</Label>
              <Input id="variety" name="variety" maxLength={120} />
            </div>
            <div>
              <Label htmlFor="crop_yield_unit">Yield unit</Label>
              <UnitSelect id="crop_yield_unit" name="yield_unit" defaultValue="kg" dimensions={["mass", "count", "volume"]} />
            </div>
          </div>
          {error ? <FieldError>{error.fieldError("name") ?? error.fieldError("global_variety_id") ?? error.problem.title}</FieldError> : null}
          <Button type="submit" size="sm">
            Add crop
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

function AddSeasonForm({ farmId, onAdded }: { farmId: string; onAdded: () => void }) {
  const [error, setError] = useState<ApiError | null>(null);

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    const form = e.currentTarget;
    const f = new FormData(form);
    try {
      await api.POST("/farms/{farm}/seasons", {
        params: { path: { farm: farmId } },
        body: { name: String(f.get("season_name")), starts_on: String(f.get("starts_on")), ends_on: String(f.get("ends_on")) },
      });
      form.reset();
      onAdded();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  return (
    <Card className="mb-3">
      <CardContent>
        <form onSubmit={submit} className="grid grid-cols-[1fr_9rem_9rem_auto] items-end gap-3">
          <div>
            <Label htmlFor="season_name">Name</Label>
            <Input id="season_name" name="season_name" required maxLength={80} placeholder="2026 Season B" />
          </div>
          <div>
            <Label htmlFor="starts_on">From</Label>
            <Input id="starts_on" name="starts_on" type="date" required />
          </div>
          <div>
            <Label htmlFor="ends_on">To</Label>
            <Input id="ends_on" name="ends_on" type="date" required />
          </div>
          <Button type="submit" size="sm">
            Add
          </Button>
          {error ? (
            <p className="col-span-4 text-sm text-danger" role="alert">
              {error.fieldError("name") ?? error.fieldError("ends_on") ?? error.problem.title}
            </p>
          ) : null}
        </form>
      </CardContent>
    </Card>
  );
}
