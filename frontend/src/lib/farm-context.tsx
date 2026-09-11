"use client";

import * as React from "react";
import { useQuery } from "@tanstack/react-query";

import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";

export type Farm = {
  id: number;
  name: string;
  district: string | null;
  village: string | null;
  gps_lat: string | null;
  gps_lng: string | null;
  boundary: { lat: number; lng: number }[] | null;
  is_active: boolean;
  owner?: { id: number; name: string };
  blocks_count?: number;
  my_role?: string | null;
  created_at: string;
  updated_at: string;
};

type FarmContextValue = {
  farms: Farm[];
  isLoading: boolean;
  currentFarmId: number | null;
  currentFarm: Farm | null;
  setCurrentFarmId: (id: number) => void;
  refetch: () => void;
};

const FarmContext = React.createContext<FarmContextValue | null>(null);

const STORAGE_KEY = "farmsap_current_farm_id";

function readStoredFarmId(): number | null {
  if (typeof window === "undefined") return null;
  const stored = window.localStorage.getItem(STORAGE_KEY);
  return stored ? Number(stored) : null;
}

export function FarmProvider({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  // The explicit choice a user made via the switcher (or restored from a
  // prior session). If it's unset, or no longer valid for the loaded farm
  // list, currentFarmId below falls back to the first farm — computed
  // during render rather than synced via an effect, since it's pure
  // derivation from data already in hand once farms have loaded.
  const [explicitFarmId, setExplicitFarmId] = React.useState<number | null>(readStoredFarmId);

  const { data, isLoading, refetch } = useQuery({
    queryKey: ["farms"],
    queryFn: async () => {
      const { data } = await api.get<{ data: Farm[] }>("/farms");
      return data.data;
    },
    enabled: !!user,
  });

  const farms = React.useMemo(() => data ?? [], [data]);

  const currentFarmId = React.useMemo(() => {
    if (explicitFarmId !== null && farms.some((f) => f.id === explicitFarmId)) {
      return explicitFarmId;
    }
    return farms[0]?.id ?? null;
  }, [explicitFarmId, farms]);

  const setCurrentFarmId = React.useCallback((id: number) => {
    setExplicitFarmId(id);
    window.localStorage.setItem(STORAGE_KEY, String(id));
  }, []);

  const currentFarm = React.useMemo(
    () => farms.find((f) => f.id === currentFarmId) ?? null,
    [farms, currentFarmId]
  );

  const value = React.useMemo(
    () => ({ farms, isLoading, currentFarmId, currentFarm, setCurrentFarmId, refetch }),
    [farms, isLoading, currentFarmId, currentFarm, setCurrentFarmId, refetch]
  );

  return <FarmContext.Provider value={value}>{children}</FarmContext.Provider>;
}

export function useFarm() {
  const ctx = React.useContext(FarmContext);
  if (!ctx) {
    throw new Error("useFarm must be used within FarmProvider");
  }
  return ctx;
}
