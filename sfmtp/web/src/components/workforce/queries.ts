"use client";

import { useQuery } from "@tanstack/react-query";

import { api } from "@/lib/api/client";

export function useWorkers(farmId: string, status: "active" | "inactive" = "active", enabled = true) {
  return useQuery({
    queryKey: ["workers", farmId, status],
    queryFn: async () => (await api.GET("/farms/{farm}/workers", { params: { path: { farm: farmId }, query: { "filter[status]": status } } })).data!.data!,
    enabled,
  });
}

export function useMyDay(farmId: string) {
  return useQuery({
    queryKey: ["my-day", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/me/today", { params: { path: { farm: farmId } } })).data!.data!,
  });
}

export function useTask(farmId: string, taskId: string) {
  return useQuery({
    queryKey: ["task", farmId, taskId],
    queryFn: async () => (await api.GET("/farms/{farm}/tasks/{task}", { params: { path: { farm: farmId, task: taskId } } })).data!.data!,
  });
}

/** Open crop cycles, for planning crop work (members who can see crops only). */
export function useOpenCycles(farmId: string, enabled: boolean) {
  return useQuery({
    queryKey: ["crop-cycles", farmId, "open-for-work"],
    queryFn: async () => (await api.GET("/farms/{farm}/crop-cycles", { params: { path: { farm: farmId }, query: { per_page: 100 } } })).data!.data!,
    enabled,
  });
}
