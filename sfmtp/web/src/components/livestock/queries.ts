"use client";

import { useQuery } from "@tanstack/react-query";

import { api } from "@/lib/api/client";

export function useGroups(farmId: string) {
  return useQuery({
    queryKey: ["animal-groups", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/animal-groups", { params: { path: { farm: farmId } } })).data!.data!,
  });
}

export function useActiveAnimals(farmId: string) {
  return useQuery({
    queryKey: ["animals", farmId, "active-all"],
    queryFn: async () => (await api.GET("/farms/{farm}/animals", { params: { path: { farm: farmId }, query: { per_page: 200 } } })).data!.data!,
  });
}

export function useLocations(farmId: string) {
  return useQuery({
    queryKey: ["structure", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/structure", { params: { path: { farm: farmId } } })).data!.data!,
    select: (d) => d.locations ?? [],
  });
}
