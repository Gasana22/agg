"use client";

import { useQuery } from "@tanstack/react-query";

import { api } from "@/lib/api/client";

/** Shared lookups for the crop screens. */
export function useFarmCrops(farmId: string, includeInactive = false) {
  return useQuery({
    queryKey: ["farm-crops", farmId, includeInactive],
    queryFn: async () => (await api.GET("/farms/{farm}/crops", { params: { path: { farm: farmId }, query: { include_inactive: includeInactive } } })).data!.data!,
  });
}

export function useSeasons(farmId: string) {
  return useQuery({
    queryKey: ["seasons", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/seasons", { params: { path: { farm: farmId } } })).data!.data!,
  });
}

export function useUnits() {
  return useQuery({
    queryKey: ["catalog", "units"],
    queryFn: async () => (await api.GET("/catalog/{catalog}", { params: { path: { catalog: "units" } } })).data!.data! as { code?: string; name?: string; dimension?: string }[],
    staleTime: 3_600_000,
  });
}

export function usePlots(farmId: string) {
  return useQuery({
    queryKey: ["structure", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/structure", { params: { path: { farm: farmId } } })).data!.data!,
  });
}
