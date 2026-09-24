"use client";

import { useQuery } from "@tanstack/react-query";

import { api } from "./client";

/** Global catalogue lookups, cached for an hour. */
export function useUnits() {
  return useQuery({
    queryKey: ["catalog", "units"],
    queryFn: async () => (await api.GET("/catalog/{catalog}", { params: { path: { catalog: "units" } } })).data!.data! as { code?: string; name?: string; dimension?: string }[],
    staleTime: 3_600_000,
  });
}

export function useCatalog(catalog: "crops" | "crop-varieties" | "animal-species" | "animal-breeds" | "activity-types" | "inventory-categories", parentId?: string) {
  return useQuery({
    queryKey: ["catalog", catalog, parentId ?? null],
    queryFn: async () =>
      (await api.GET("/catalog/{catalog}", { params: { path: { catalog }, query: parentId ? { "filter[parent_id]": parentId } : {} } })).data!.data! as {
        id?: string;
        code?: string;
        name?: string;
        purpose?: string | null;
        module?: string;
        is_active?: boolean;
      }[],
    staleTime: 3_600_000,
    enabled: catalog !== "crop-varieties" && catalog !== "animal-breeds" ? true : Boolean(parentId),
  });
}
