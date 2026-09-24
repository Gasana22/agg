"use client";

import "leaflet/dist/leaflet.css";

import L from "leaflet";
import { useEffect, useRef } from "react";

import type { components } from "@/lib/api/client";
import { humanize } from "@/lib/format";

type Locations = components["schemas"]["JourneyLocations"];

const TILE_URL = process.env.NEXT_PUBLIC_MAP_TILE_URL || "https://tile.openstreetmap.org/{z}/{x}/{y}.png";
const TILE_ATTRIBUTION = process.env.NEXT_PUBLIC_MAP_ATTRIBUTION || "&copy; OpenStreetMap contributors";

/** The geographic trail of a batch: the plots of its lineage and every GPS-stamped event, in order. */
export default function TraceMap({ data }: { data: Locations }) {
  const container = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!container.current) return;
    const m = L.map(container.current, { zoomControl: true }).setView([1.37, 32.29], 7);
    L.tileLayer(TILE_URL, { maxZoom: 20, attribution: TILE_ATTRIBUTION }).addTo(m);
    const bounds = L.latLngBounds([]);

    for (const p of data.plots ?? []) {
      const boundary = p.boundary as { type?: string; coordinates?: number[][][] } | null;
      if (boundary?.type === "Polygon" && boundary.coordinates) {
        const layer = L.polygon(boundary.coordinates.map((ring) => ring.map(([lng, lat]) => [lat, lng] as L.LatLngTuple)), { color: "#15803d", weight: 2, fillOpacity: 0.2 });
        layer.bindTooltip(`Plot ${p.code} · ${(p.batches ?? []).join(", ")}`, { sticky: true });
        layer.addTo(m);
        bounds.extend(layer.getBounds());
      } else if (p.centroid?.lat !== undefined && p.centroid.lng !== undefined) {
        const marker = L.circleMarker([p.centroid.lat, p.centroid.lng], { radius: 8, color: "#15803d", fillOpacity: 0.5 });
        marker.bindTooltip(`Plot ${p.code}`).addTo(m);
        bounds.extend(marker.getLatLng());
      }
    }

    const points = (data.points ?? []).map((pt) => [pt.lat!, pt.lng!] as L.LatLngTuple);
    if (points.length > 1) L.polyline(points, { color: "#f97316", weight: 2, dashArray: "4 6" }).addTo(m);
    (data.points ?? []).forEach((pt, i) => {
      const marker = L.circleMarker([pt.lat!, pt.lng!], { radius: 6, color: "#c2410c", fillColor: "#f97316", fillOpacity: 0.9, weight: 2 });
      marker.bindTooltip(`${i + 1}. ${humanize(pt.event_type ?? "")} · ${pt.batch_code} · ${new Date(pt.occurred_at!).toLocaleDateString()}`);
      marker.addTo(m);
      bounds.extend(marker.getLatLng());
    });

    if (bounds.isValid()) m.fitBounds(bounds.pad(0.2), { maxZoom: 17 });
    return () => {
      m.remove();
    };
  }, [data]);

  return <div ref={container} className="h-[380px] w-full rounded-lg border border-border" role="region" aria-label="Map of the batch's plots and GPS points" />;
}
