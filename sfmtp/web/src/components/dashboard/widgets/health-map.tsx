"use client";

import "leaflet/dist/leaflet.css";

import L from "leaflet";
import { useRouter } from "next/navigation";
import { useEffect, useRef } from "react";

import { BAND } from "@/lib/analytics";

import type { HealthPoint } from "./health";

const TILE_URL =
  process.env.NEXT_PUBLIC_MAP_TILE_URL ||
  "https://tile.openstreetmap.org/{z}/{x}/{y}.png";
const TILE_ATTRIBUTION =
  process.env.NEXT_PUBLIC_MAP_ATTRIBUTION ||
  "&copy; OpenStreetMap contributors";

/** A small map of crop cycles at their plots, coloured by health band. */
export default function HealthMap({ points }: { points: HealthPoint[] }) {
  const container = useRef<HTMLDivElement>(null);
  const router = useRouter();

  useEffect(() => {
    if (!container.current) return;
    const m = L.map(container.current, {
      zoomControl: false,
      attributionControl: true,
      scrollWheelZoom: false,
    });
    L.tileLayer(TILE_URL, { maxZoom: 19, attribution: TILE_ATTRIBUTION }).addTo(
      m,
    );
    const bounds = L.latLngBounds([]);
    for (const p of points) {
      const color = BAND[p.band]?.color ?? "#64748b";
      const marker = L.circleMarker([p.lat!, p.lng!], {
        radius: 9,
        color: "#fff",
        weight: 2,
        fillColor: color,
        fillOpacity: 0.95,
      });
      marker.bindTooltip(`${p.title}: ${p.score}/100`);
      if (p.href) marker.on("click", () => router.push(p.href!));
      marker.addTo(m);
      bounds.extend(marker.getLatLng());
    }
    if (bounds.isValid()) m.fitBounds(bounds.pad(0.4), { maxZoom: 17 });
    return () => {
      m.remove();
    };
  }, [points, router]);

  return (
    <div
      ref={container}
      className="h-48 w-full rounded-lg border border-border"
      role="img"
      aria-label="Crop health map"
    />
  );
}
