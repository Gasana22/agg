"use client";

import { useQuery } from "@tanstack/react-query";

import { api } from "@/lib/api/client";

/** The chart of accounts (with balances); cached briefly for pickers. */
export function useAccounts(farmId: string, enabled = true) {
  return useQuery({
    queryKey: ["ledger-accounts", farmId, ""],
    queryFn: async () => (await api.GET("/farms/{farm}/ledger/accounts", { params: { path: { farm: farmId } } })).data!,
    select: (d) => d.data ?? [],
    staleTime: 30_000,
    enabled,
  });
}

export function useCustomers(farmId: string, enabled = true) {
  return useQuery({
    queryKey: ["customers", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/customers", { params: { path: { farm: farmId } } })).data!.data!,
    enabled,
  });
}
