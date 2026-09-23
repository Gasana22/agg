"use client";

import { useQuery } from "@tanstack/react-query";
import { Lock } from "lucide-react";
import { useParams } from "next/navigation";
import { useMemo } from "react";

import { ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { humanize } from "@/lib/format";

const SCOPE_MARK: Record<string, string> = { all: "●", assigned: "◐", own: "○" };

/** Read-only permission matrix. Editing roles arrives with the role editor (Phase 3). */
export default function RolesPage() {
  const { farmId } = useParams<{ farmId: string }>();
  const roles = useQuery({
    queryKey: ["roles", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/roles", { params: { path: { farm: farmId } } })).data!.data!,
  });
  const permissions = useQuery({
    queryKey: ["permissions", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/permissions", { params: { path: { farm: farmId } } })).data!.data!,
  });

  const permissionList = permissions.data;
  const modules = useMemo(() => {
    const grouped = new Map<string, NonNullable<typeof permissionList>>();
    for (const p of permissionList ?? []) {
      const list = grouped.get(p.module ?? "") ?? [];
      list.push(p);
      grouped.set(p.module ?? "", list);
    }
    return [...grouped.entries()];
  }, [permissionList]);

  if (roles.isLoading || permissions.isLoading) return <Skeleton className="h-96 w-full" />;
  if (roles.error || permissions.error) return <ErrorNotice error={roles.error ?? permissions.error} />;

  return (
    <>
      <PageHeader title="Roles & permissions" description="● whole farm · ◐ assigned records · ○ own records only. The server enforces every permission." />
      <Table>
        <thead>
          <tr>
            <Th className="sticky left-0 z-10 min-w-64">Permission</Th>
            {roles.data!.map((r) => (
              <Th key={r.id} className="text-center">
                <span className="inline-flex items-center gap-1">
                  {r.name}
                  {r.is_locked ? <Lock className="size-3" aria-label="Locked" /> : null}
                </span>
              </Th>
            ))}
          </tr>
        </thead>
        <tbody>
          {modules.map(([module, list]) => (
            <ModuleRows key={module} module={module} list={list} roles={roles.data!} />
          ))}
        </tbody>
      </Table>
    </>
  );
}

function ModuleRows({ module, list, roles }: { module: string; list: { key?: string; description?: string; money?: boolean }[]; roles: { id?: string; grants?: Record<string, string> }[] }) {
  return (
    <>
      <tr>
        <Td colSpan={roles.length + 1} className="bg-surface-muted/60 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted">
          {humanize(module)}
        </Td>
      </tr>
      {list.map((p) => (
        <tr key={p.key}>
          <Td className="sticky left-0 bg-surface">
            <span className="block text-sm">{p.description}</span>
            <span className="font-mono text-[11px] text-muted">
              {p.key}
              {p.money ? " · $" : ""}
            </span>
          </Td>
          {roles.map((r) => {
            const scope = r.grants?.[p.key ?? ""];
            return (
              <Td key={r.id} className="text-center text-primary" title={scope ?? "no access"}>
                {scope ? SCOPE_MARK[scope] : <span className="text-border">—</span>}
              </Td>
            );
          })}
        </tr>
      ))}
    </>
  );
}
