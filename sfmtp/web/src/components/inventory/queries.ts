"use client";

import { useQuery } from "@tanstack/react-query";

import { api } from "@/lib/api/client";

export function useItems(farmId: string, enabled = true) {
  return useQuery({
    queryKey: ["inventory-items", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/inventory/items", { params: { path: { farm: farmId }, query: { "filter[active]": true } } })).data!.data!,
    enabled,
  });
}

/** Stock on hand by store and lot, for one item or the whole farm. */
export function useBalances(farmId: string, itemId?: string, enabled = true) {
  return useQuery({
    queryKey: ["inventory-stock", farmId, itemId ?? null],
    queryFn: async () => (await api.GET("/farms/{farm}/inventory/stock", { params: { path: { farm: farmId }, query: { "filter[item_id]": itemId } } })).data!.data!,
    enabled,
  });
}

export function useSuppliers(farmId: string, enabled = true) {
  return useQuery({
    queryKey: ["suppliers", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/suppliers", { params: { path: { farm: farmId } } })).data!.data!,
    enabled,
  });
}

/** Places that can hold stock: stores first, then any other location. */
export function useStores(farmId: string) {
  return useQuery({
    queryKey: ["structure", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/structure", { params: { path: { farm: farmId } } })).data!.data!,
    select: (d) => [...(d.locations ?? [])].sort((a, b) => Number(b.kind === "store") - Number(a.kind === "store") || (a.code ?? "").localeCompare(b.code ?? "")),
  });
}

export function useFarmCurrency(farmId: string) {
  const farm = useQuery({
    queryKey: ["farm", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}", { params: { path: { farm: farmId } } })).data!.data!,
    staleTime: 600_000,
  });
  return farm.data?.currency ?? "";
}
