"use client";

import { useQuery } from "@tanstack/react-query";
import L from "leaflet";
import { type RefObject, useEffect, useRef } from "react";

import { api } from "@/lib/api/client";

export type MapConfig = { provider: string; tile_url: string; attribution: string; max_zoom: number };

/** Until the API answers (or when signed out), the build's tiles or OpenStreetMap. */
export const FALLBACK_MAP: MapConfig = {
  provider: "osm",
  tile_url: process.env.NEXT_PUBLIC_MAP_TILE_URL || "https://tile.openstreetmap.org/{z}/{x}/{y}.png",
  attribution: process.env.NEXT_PUBLIC_MAP_ATTRIBUTION || "&copy; OpenStreetMap contributors",
  max_zoom: 19,
};

/** The base map chosen in the admin portal (ADR-0018). */
export function useMapConfig(): MapConfig {
  const q = useQuery({
    queryKey: ["map-config"],
    queryFn: async () => (await api.GET("/map-config")).data!.data as MapConfig,
    staleTime: 10 * 60_000,
    retry: false,
  });

  return q.data?.tile_url ? q.data : FALLBACK_MAP;
}

/** Keeps a Leaflet map's base layer on the configured tiles, swapping it when the provider changes. */
export function useBaseLayer(map: RefObject<L.Map | null>, ready: unknown): void {
  const config = useMapConfig();
  const layer = useRef<L.TileLayer | null>(null);

  useEffect(() => {
    const m = map.current;
    if (!m) return;
    layer.current?.remove();
    layer.current = L.tileLayer(config.tile_url, { maxZoom: config.max_zoom, attribution: config.attribution }).addTo(m);
    layer.current.bringToBack();
  }, [map, ready, config.tile_url, config.max_zoom, config.attribution]);
}
