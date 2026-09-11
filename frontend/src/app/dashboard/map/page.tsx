"use client";

import dynamic from "next/dynamic";

import { useFarm } from "@/lib/farm-context";

// Leaflet touches `window` at import time, so the map view must never be
// server-rendered — dynamic import with ssr:false keeps it client-only.
const FarmMapView = dynamic(() => import("./farm-map-view"), {
  ssr: false,
  loading: () => <p className="text-sm text-muted-foreground">Loading map…</p>,
});

export default function MapPage() {
  const { currentFarmId, currentFarm } = useFarm();

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Maps &amp; GIS</h1>
        <p className="text-sm text-muted-foreground">
          {currentFarm ? `${currentFarm.name}'s layout — boundaries and block locations.` : "Select a farm to see its map."}
        </p>
      </div>

      {currentFarmId ? (
        <FarmMapView farmId={currentFarmId} />
      ) : (
        <p className="text-sm text-muted-foreground">No farm selected.</p>
      )}
    </div>
  );
}
