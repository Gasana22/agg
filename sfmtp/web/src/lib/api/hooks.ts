"use client";

import { useQuery } from "@tanstack/react-query";

import { api } from "./client";
import type { components } from "./schema";

export type Workspace = components["schemas"]["Workspace"];
export type Dashboard = components["schemas"]["Dashboard"];

export type Workspaces = {
  workspaces: Workspace[];
  mfaRequired: boolean;
  mfaEnabled: boolean;
};

export function useWorkspaces() {
  return useQuery({
    queryKey: ["workspaces"],
    queryFn: async (): Promise<Workspaces> => {
      const body = (await api.GET("/me/workspaces")).data!;
      return {
        workspaces: body.data ?? [],
        mfaRequired: Boolean(body.meta?.mfa_required),
        mfaEnabled: Boolean(body.meta?.mfa_enabled),
      };
    },
    staleTime: 60_000,
  });
}

export function useMe() {
  return useQuery({
    queryKey: ["me"],
    queryFn: async () => (await api.GET("/me")).data!,
    staleTime: 60_000,
  });
}

/**
 * The farm workspace for a route: a membership, or an owner-granted
 * read-only support session. Undefined while loading or without access.
 */
export function useFarmWorkspace(farmId: string) {
  const query = useWorkspaces();
  const workspace =
    query.data?.workspaces.find((w) => w.type === "farm" && w.id === farmId) ??
    query.data?.workspaces.find((w) => w.type === "support" && w.id === farmId);
  return { ...query, workspace };
}

/** The platform workspace (admins only), with the caller's capabilities. */
export function usePlatformWorkspace() {
  const query = useWorkspaces();
  return { ...query, workspace: query.data?.workspaces.find((w) => w.type === "platform") };
}
