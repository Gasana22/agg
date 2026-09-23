"use client";

import "leaflet/dist/leaflet.css";

import L from "leaflet";
import { useEffect, useRef } from "react";

import { type Block, LAND_USE_COLOR, type Location, type NodeType, type Plot, type Section } from "@/lib/structure";

export type MapSelection = { type: NodeType; id: string } | null;

export type DrawMode = { kind: "polygon"; points: [number, number][] } | { kind: "point"; point: [number, number] | null } | null;

type Props = {
  blocks: Block[];
  sections: Section[];
  plots: Plot[];
  locations: Location[];
  selected: MapSelection;
  onSelect: (selection: MapSelection) => void;
  draw: DrawMode;
  onDrawChange: (draw: DrawMode) => void;
};

const TILE_URL = process.env.NEXT_PUBLIC_MAP_TILE_URL || "https://tile.openstreetmap.org/{z}/{x}/{y}.png";
const TILE_ATTRIBUTION = process.env.NEXT_PUBLIC_MAP_ATTRIBUTION || "&copy; OpenStreetMap contributors";
const UGANDA: L.LatLngExpression = [1.37, 32.29];

/**
 * The farm map: boundaries of blocks, sections and plots, location pins, and
 * a click-to-draw mode for new boundaries and points. Plain Leaflet, loaded
 * only in the browser.
 */
export default function FarmMap({ blocks, sections, plots, locations, selected, onSelect, draw, onDrawChange }: Props) {
  const container = useRef<HTMLDivElement>(null);
  const map = useRef<L.Map | null>(null);
  const shapes = useRef<L.LayerGroup | null>(null);
  const sketch = useRef<L.LayerGroup | null>(null);
  const fitted = useRef(false);
  const drawRef = useRef(draw);
  const onDrawChangeRef = useRef(onDrawChange);

  useEffect(() => {
    drawRef.current = draw;
    onDrawChangeRef.current = onDrawChange;
  }, [draw, onDrawChange]);

  // Create the map once.
  useEffect(() => {
    if (!container.current || map.current) return;
    const m = L.map(container.current, { zoomControl: true, doubleClickZoom: false }).setView(UGANDA, 7);
    L.tileLayer(TILE_URL, { maxZoom: 20, attribution: TILE_ATTRIBUTION }).addTo(m);
    shapes.current = L.layerGroup().addTo(m);
    sketch.current = L.layerGroup().addTo(m);

    m.on("click", (e: L.LeafletMouseEvent) => {
      const current = drawRef.current;
      if (!current) return;
      const point: [number, number] = [e.latlng.lat, e.latlng.lng];
      onDrawChangeRef.current(current.kind === "polygon" ? { kind: "polygon", points: [...current.points, point] } : { kind: "point", point });
    });

    map.current = m;
    return () => {
      m.remove();
      map.current = null;
    };
  }, []);

  // Draw the structure.
  useEffect(() => {
    const m = map.current;
    const group = shapes.current;
    if (!m || !group) return;
    group.clearLayers();
    const bounds = L.latLngBounds([]);
    const isSelected = (type: NodeType, id?: string) => selected?.type === type && selected.id === id;

    const addPolygon = (type: NodeType, node: Block | Section | Plot, style: L.PathOptions) => {
      if (!node.boundary) return;
      const rings = node.boundary.coordinates.map((ring) => ring.map(([lng, lat]) => [lat, lng] as L.LatLngTuple));
      const layer = L.polygon(rings, { ...style, weight: isSelected(type, node.id) ? 4 : style.weight });
      layer.bindTooltip(`${node.code} · ${node.name}`, { sticky: true });
      layer.on("click", (e) => {
        if (drawRef.current) return;   // clicks go to the drawing
        L.DomEvent.stopPropagation(e);
        onSelect({ type, id: node.id! });
      });
      layer.addTo(group);
      bounds.extend(layer.getBounds());
    };

    blocks.forEach((b) => addPolygon("block", b, { color: "#0f172a", weight: 2, dashArray: "6 4", fillOpacity: 0.02 }));
    sections.forEach((s) => addPolygon("section", s, { color: "#475569", weight: 1.5, dashArray: "2 4", fillOpacity: 0.03 }));
    plots.forEach((p) => {
      const color = LAND_USE_COLOR[p.land_use ?? "other"] ?? LAND_USE_COLOR.other;
      addPolygon("plot", p, { color, weight: 2, fillColor: color, fillOpacity: 0.25 });
    });
    locations.forEach((l) => {
      if (l.latitude === null || l.latitude === undefined || l.longitude === null || l.longitude === undefined) return;
      const marker = L.circleMarker([l.latitude, l.longitude], {
        radius: isSelected("location", l.id) ? 9 : 6,
        color: "#1d4ed8",
        fillColor: "#3b82f6",
        fillOpacity: 0.9,
        weight: 2,
      });
      marker.bindTooltip(`${l.code} · ${l.name}`);
      marker.on("click", (e) => {
        if (drawRef.current) return;
        L.DomEvent.stopPropagation(e);
        onSelect({ type: "location", id: l.id! });
      });
      marker.addTo(group);
      bounds.extend(marker.getLatLng());
    });

    if (!fitted.current && bounds.isValid()) {
      m.fitBounds(bounds.pad(0.1));
      fitted.current = true;
    }
  }, [blocks, sections, plots, locations, selected, onSelect]);

  // Draw the sketch in progress.
  useEffect(() => {
    const group = sketch.current;
    if (!group) return;
    group.clearLayers();
    container.current?.classList.toggle("cursor-crosshair", Boolean(draw));
    if (!draw) return;

    if (draw.kind === "polygon" && draw.points.length > 0) {
      const style = { color: "#f97316", weight: 2, dashArray: "4 4" };
      (draw.points.length >= 3 ? L.polygon(draw.points, { ...style, fillOpacity: 0.15 }) : L.polyline(draw.points, style)).addTo(group);
      draw.points.forEach((p) => L.circleMarker(p, { radius: 4, color: "#f97316", fillOpacity: 1 }).addTo(group));
    }
    if (draw.kind === "point" && draw.point) {
      L.circleMarker(draw.point, { radius: 8, color: "#f97316", fillOpacity: 0.8 }).addTo(group);
    }
  }, [draw]);

  // Pan to a selection made in the list.
  useEffect(() => {
    const m = map.current;
    if (!m || !selected) return;
    const all = [...blocks, ...sections, ...plots] as (Block | Section | Plot)[];
    const node = selected.type === "location" ? locations.find((l) => l.id === selected.id) : all.find((n) => n.id === selected.id);
    if (!node) return;
    if (node.centroid) m.panTo([node.centroid.lat!, node.centroid.lng!]);
    else if ("latitude" in node && node.latitude != null && node.longitude != null) m.panTo([node.latitude, node.longitude]);
    // Only when the selection changes, not when data refreshes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selected?.type, selected?.id]);

  return <div ref={container} className="h-full min-h-[420px] w-full rounded-xl border border-border" role="application" aria-label="Farm map" />;
}
