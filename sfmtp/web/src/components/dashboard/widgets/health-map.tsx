"use client";

import "leaflet/dist/leaflet.css";

import L from "leaflet";
import { useRouter } from "next/navigation";
import { useEffect, useRef } from "react";

import { BAND } from "@/lib/analytics";
import { useBaseLayer } from "@/lib/map-config";

import type { HealthPoint } from "./health";

/** A small map of crop cycles at their plots, coloured by health band. */
export default function HealthMap({ points }: { points: HealthPoint[] }) {
  const container = useRef<HTMLDivElement>(null);
  const map = useRef<L.Map | null>(null);
  const router = useRouter();

  useEffect(() => {
    if (!container.current) return;
    const m = L.map(container.current, { zoomControl: false, attributionControl: true, scrollWheelZoom: false });
    const bounds = L.latLngBounds([]);
    for (const p of points) {
      const marker = L.circleMarker([p.lat!, p.lng!], { radius: 9, color: "#fff", weight: 2, fillColor: BAND[p.band]?.color ?? "#64748b", fillOpacity: 0.95 });
      marker.bindTooltip(`${p.title}: ${p.score}/100`);
      if (p.href) marker.on("click", () => router.push(p.href!));
      marker.addTo(m);
      bounds.extend(marker.getLatLng());
    }
    if (bounds.isValid()) m.fitBounds(bounds.pad(0.4), { maxZoom: 17 });
    map.current = m;
    return () => {
      m.remove();
      map.current = null;
    };
  }, [points, router]);
  useBaseLayer(map, points);

  return <div ref={container} className="h-48 w-full rounded-lg border border-border" role="img" aria-label="Crop health map" />;
}
