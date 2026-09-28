"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, RefreshCw } from "lucide-react";
import { useState } from "react";

import { FormDialog } from "@/components/forms/form-dialog";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { FieldError, Input, Label, Select } from "@/components/ui/input";
import { EmptyState, Skeleton } from "@/components/ui/misc";
import { api, type components } from "@/lib/api/client";
import { formatRelative, humanize } from "@/lib/format";
import type { Location, Plot } from "@/lib/structure";

type Device = components["schemas"]["IotDevice"];

const KINDS = ["weather_station", "soil_probe", "water_meter", "tank_level", "cold_room", "other"] as const;

/**
 * IoT extension point (ADR-0018): sensors placed on plots or at locations,
 * with their latest readings. A new sensor's token is shown once.
 */
export function Sensors({ farmId, plots, locations, canManage }: { farmId: string; plots: Plot[]; locations: Location[]; canManage: boolean }) {
  const queryClient = useQueryClient();
  const [adding, setAdding] = useState(false);
  const [token, setToken] = useState<{ name: string; token: string } | null>(null);
  const devices = useQuery({
    queryKey: ["iot-devices", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/iot-devices", { params: { path: { farm: farmId } } })).data!.data ?? [],
  });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["iot-devices", farmId] });

  return (
    <Card>
      <CardHeader>
        <CardTitle>Sensors</CardTitle>
        {canManage ? (
          <Button size="sm" variant="secondary" onClick={() => setAdding(true)}>
            <Plus /> Add
          </Button>
        ) : null}
      </CardHeader>
      <CardContent className="space-y-3">
        {token ? (
          <div className="rounded-lg border border-warning bg-warning/10 p-3 text-xs" role="status">
            <p className="font-medium">Token for {token.name}: copy it into the device now. It is not shown again.</p>
            <code className="mt-1 block break-all font-mono">{token.token}</code>
            <p className="mt-1 text-muted">The device posts to /api/v1/iot/readings with “Authorization: Bearer” and this token.</p>
            <Button size="sm" variant="ghost" className="mt-1 px-0" onClick={() => setToken(null)}>
              Done
            </Button>
          </div>
        ) : null}
        {devices.isLoading ? (
          <Skeleton className="h-16 w-full" />
        ) : (devices.data ?? []).length === 0 ? (
          <EmptyState title="No sensors yet">Soil probes, tank gauges or weather stations can post their readings here.</EmptyState>
        ) : (
          <ul className="divide-y divide-border">
            {devices.data!.map((d) => (
              <DeviceRow key={d.id} farmId={farmId} device={d} canManage={canManage} onToken={(t) => setToken({ name: d.name ?? "", token: t })} />
            ))}
          </ul>
        )}
      </CardContent>
      {adding ? (
        <FormDialog
          title="Add a sensor"
          description="It gets its own token to post readings with. Place it on a plot or at a location to see it on the map."
          submitLabel="Add sensor"
          onClose={() => setAdding(false)}
          onSubmit={async (f) => {
            const res = await api.POST("/farms/{farm}/iot-devices", {
              params: { path: { farm: farmId } },
              body: {
                name: String(f.get("name")),
                kind: String(f.get("kind")) as (typeof KINDS)[number],
                plot_id: String(f.get("plot_id") || "") || null,
                location_id: String(f.get("location_id") || "") || null,
              },
            });
            setAdding(false);
            setToken({ name: res.data?.data?.name ?? "", token: res.data?.meta?.token ?? "" });
            await refresh();
          }}
        >
          {(error) => (
            <div className="space-y-3">
              <div>
                <Label htmlFor="sensor-name">Name</Label>
                <Input id="sensor-name" name="name" required placeholder="Tank level, Plot A-1 soil probe…" />
                <FieldError>{error?.fieldError("name")}</FieldError>
              </div>
              <div>
                <Label htmlFor="sensor-kind">Kind</Label>
                <Select id="sensor-kind" name="kind" defaultValue="soil_probe">
                  {KINDS.map((k) => (
                    <option key={k} value={k}>
                      {humanize(k)}
                    </option>
                  ))}
                </Select>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <Label htmlFor="sensor-plot">Plot</Label>
                  <Select id="sensor-plot" name="plot_id" defaultValue="">
                    <option value="">—</option>
                    {plots.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.code} · {p.name}
                      </option>
                    ))}
                  </Select>
                </div>
                <div>
                  <Label htmlFor="sensor-location">Location</Label>
                  <Select id="sensor-location" name="location_id" defaultValue="">
                    <option value="">—</option>
                    {locations.map((l) => (
                      <option key={l.id} value={l.id}>
                        {l.code} · {l.name}
                      </option>
                    ))}
                  </Select>
                </div>
              </div>
            </div>
          )}
        </FormDialog>
      ) : null}
    </Card>
  );
}

function DeviceRow({ farmId, device: d, canManage, onToken }: { farmId: string; device: Device; canManage: boolean; onToken: (t: string) => void }) {
  const readings = useQuery({
    queryKey: ["iot-readings", farmId, d.id],
    queryFn: async () => (await api.GET("/farms/{farm}/iot-devices/{iotDevice}/readings", { params: { path: { farm: farmId, iotDevice: d.id! } } })).data!.data!,
    refetchInterval: 60_000,
  });

  return (
    <li className="py-2 text-sm">
      <div className="flex items-center justify-between gap-2">
        <p className="font-medium">
          {d.name} <span className="font-mono text-xs text-muted">{d.code}</span>
        </p>
        {!d.is_active ? <Badge tone="neutral">off</Badge> : d.last_seen_at ? <span className="text-xs text-muted">{formatRelative(d.last_seen_at)}</span> : <Badge tone="warning">no data yet</Badge>}
      </div>
      <p className="text-xs text-muted">
        {humanize(d.kind ?? "")}
        {(readings.data?.latest ?? []).map((r) => ` · ${humanize(r.metric ?? "")}: ${r.value}`).join("")}
      </p>
      {canManage ? (
        <Button
          size="sm"
          variant="ghost"
          className="h-7 px-0 text-xs text-muted"
          onClick={async () => {
            if (!window.confirm(`Give ${d.name} a new token? The current one stops working.`)) return;
            const res = await api.POST("/farms/{farm}/iot-devices/{iotDevice}/rotate-token", { params: { path: { farm: farmId, iotDevice: d.id! } } });
            onToken(res.data?.meta?.token ?? "");
          }}
        >
          <RefreshCw /> New token
        </Button>
      ) : null}
    </li>
  );
}
