"use client";

import * as React from "react";
import { MapContainer, TileLayer, Marker, Polygon, Polyline, Popup, useMapEvents } from "react-leaflet";
import L from "leaflet";
import "leaflet/dist/leaflet.css";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { isAxiosError } from "axios";
import { Pencil, Save, Undo2, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { getFarmMap, type GeoJsonFeature } from "@/lib/modules/maps";
import { listBlocks, updateFarm, updateBlock } from "@/lib/modules/farm-structure";
import { useFarm } from "@/lib/farm-context";

// Colored div icons avoid bundler asset-resolution issues with Leaflet's
// default marker PNGs (which assume a classic non-bundled asset path).
function dotIcon(color: string) {
  return L.divIcon({
    className: "",
    html: `<span style="display:block;width:14px;height:14px;border-radius:9999px;background:${color};border:2px solid white;box-shadow:0 0 0 1px rgba(0,0,0,0.25);"></span>`,
    iconSize: [14, 14],
    iconAnchor: [7, 7],
  });
}

const FARM_ICON = dotIcon("#16a34a");
const BLOCK_ICON = dotIcon("#2563eb");

type DrawTarget = { type: "farm" } | { type: "block"; id: number; name: string } | null;

function ClickCapture({ onClick }: { onClick: (lat: number, lng: number) => void }) {
  useMapEvents({
    click(e) {
      onClick(e.latlng.lat, e.latlng.lng);
    },
  });
  return null;
}

export default function FarmMapView({ farmId }: { farmId: number }) {
  const queryClient = useQueryClient();
  const { currentFarm } = useFarm();
  const [drawTarget, setDrawTarget] = React.useState<DrawTarget>(null);
  const [drawPoints, setDrawPoints] = React.useState<{ lat: number; lng: number }[]>([]);
  const [saveError, setSaveError] = React.useState<string | null>(null);
  const [blockChoice, setBlockChoice] = React.useState<string>("");

  const { data: map } = useQuery({
    queryKey: ["farm-map", farmId],
    queryFn: () => getFarmMap(farmId),
  });

  const { data: blocks } = useQuery({
    queryKey: ["blocks", farmId],
    queryFn: () => listBlocks(farmId),
  });

  const saveFarmBoundaryMutation = useMutation({
    mutationFn: (boundary: { lat: number; lng: number }[]) => updateFarm(farmId, { boundary }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["farm-map", farmId] });
      setDrawTarget(null);
      setDrawPoints([]);
    },
    onError: (err) =>
      setSaveError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not save boundary." : "Something went wrong."
      ),
  });

  const saveBlockBoundaryMutation = useMutation({
    mutationFn: ({ blockId, boundary }: { blockId: number; boundary: { lat: number; lng: number }[] }) =>
      updateBlock(blockId, { boundary }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["farm-map", farmId] });
      setDrawTarget(null);
      setDrawPoints([]);
    },
    onError: (err) =>
      setSaveError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not save boundary." : "Something went wrong."
      ),
  });

  const isSaving = saveFarmBoundaryMutation.isPending || saveBlockBoundaryMutation.isPending;

  const center: [number, number] = currentFarm?.gps_lat && currentFarm?.gps_lng
    ? [Number(currentFarm.gps_lat), Number(currentFarm.gps_lng)]
    : [-1.94, 30.06];

  function startDrawingFarm() {
    setSaveError(null);
    setDrawPoints([]);
    setDrawTarget({ type: "farm" });
  }

  function startDrawingBlock() {
    if (!blockChoice) return;
    const block = blocks?.find((b) => b.id === Number(blockChoice));
    if (!block) return;
    setSaveError(null);
    setDrawPoints([]);
    setDrawTarget({ type: "block", id: block.id, name: block.name });
  }

  function cancelDrawing() {
    setDrawTarget(null);
    setDrawPoints([]);
    setSaveError(null);
  }

  function save() {
    if (!drawTarget || drawPoints.length < 3) {
      setSaveError("A boundary needs at least 3 points.");
      return;
    }
    setSaveError(null);
    if (drawTarget.type === "farm") {
      saveFarmBoundaryMutation.mutate(drawPoints);
    } else {
      saveBlockBoundaryMutation.mutate({ blockId: drawTarget.id, boundary: drawPoints });
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <Card>
        <CardHeader>
          <CardTitle>{drawTarget ? `Drawing: ${drawTarget.type === "farm" ? "farm boundary" : `${drawTarget.name} boundary`}` : "Boundaries"}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-wrap items-end gap-3 pb-6">
          {!drawTarget ? (
            <>
              <Button size="sm" variant="outline" className="gap-2" onClick={startDrawingFarm}>
                <Pencil className="size-4" />
                Draw farm boundary
              </Button>
              <div className="flex items-end gap-2">
                <div className="flex flex-col gap-1.5">
                  <Label className="text-xs">Block</Label>
                  <Select value={blockChoice} onValueChange={setBlockChoice}>
                    <SelectTrigger className="h-9 w-48">
                      <SelectValue placeholder="Select a block" />
                    </SelectTrigger>
                    <SelectContent>
                      {(blocks ?? []).map((block) => (
                        <SelectItem key={block.id} value={String(block.id)}>
                          {block.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <Button
                  size="sm"
                  variant="outline"
                  className="gap-2"
                  disabled={!blockChoice}
                  onClick={startDrawingBlock}
                >
                  <Pencil className="size-4" />
                  Draw block boundary
                </Button>
              </div>
            </>
          ) : (
            <>
              <p className="text-sm text-muted-foreground">
                Click the map to add points ({drawPoints.length} so far, need at least 3).
              </p>
              <Button
                size="sm"
                variant="outline"
                className="gap-2"
                disabled={drawPoints.length === 0}
                onClick={() => setDrawPoints((pts) => pts.slice(0, -1))}
              >
                <Undo2 className="size-4" />
                Undo point
              </Button>
              <Button size="sm" className="gap-2" disabled={isSaving} onClick={save}>
                <Save className="size-4" />
                {isSaving ? "Saving…" : "Save boundary"}
              </Button>
              <Button size="sm" variant="ghost" className="gap-2" onClick={cancelDrawing}>
                <X className="size-4" />
                Cancel
              </Button>
            </>
          )}
        </CardContent>
        {saveError && (
          <CardContent className="pt-0 pb-4">
            <p className="text-sm text-destructive">{saveError}</p>
          </CardContent>
        )}
      </Card>

      <div className="h-[560px] overflow-hidden rounded-lg border">
        <MapContainer center={center} zoom={14} style={{ height: "100%", width: "100%" }}>
          <TileLayer
            attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          />
          {drawTarget && <ClickCapture onClick={(lat, lng) => setDrawPoints((pts) => [...pts, { lat, lng }])} />}

          {map?.features.map((feature: GeoJsonFeature, i: number) => {
            if (feature.geometry.type === "Point") {
              const [lng, lat] = feature.geometry.coordinates;
              return (
                <Marker
                  key={i}
                  position={[lat, lng]}
                  icon={feature.properties.kind === "farm" ? FARM_ICON : BLOCK_ICON}
                >
                  <Popup>{feature.properties.name}</Popup>
                </Marker>
              );
            }
            const ring = feature.geometry.coordinates[0].map(([lng, lat]) => [lat, lng] as [number, number]);
            return (
              <Polygon
                key={i}
                positions={ring}
                pathOptions={{
                  color: feature.properties.kind === "farm_boundary" ? "#16a34a" : "#2563eb",
                  weight: 2,
                }}
              >
                <Popup>{feature.properties.name}</Popup>
              </Polygon>
            );
          })}

          {drawPoints.length > 0 && (
            <Polyline positions={drawPoints.map((p) => [p.lat, p.lng])} pathOptions={{ color: "#dc2626", dashArray: "6 6" }} />
          )}
          {drawPoints.map((p, i) => (
            <Marker key={`draw-${i}`} position={[p.lat, p.lng]} icon={dotIcon("#dc2626")} />
          ))}
        </MapContainer>
      </div>
    </div>
  );
}
