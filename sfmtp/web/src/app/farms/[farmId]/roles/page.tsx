"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Lock, Pencil, Plus } from "lucide-react";
import { useParams } from "next/navigation";
import { useMemo, useState } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FieldError, Input, Label, Select } from "@/components/ui/input";
import { ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { useFarmWorkspace } from "@/lib/api/hooks";
import type { components } from "@/lib/api/schema";
import { humanize } from "@/lib/format";
import { can } from "@/lib/permissions";

type Role = components["schemas"]["Role"];
type Permission = components["schemas"]["PermissionDefinition"];

const SCOPE_MARK: Record<string, string> = { all: "●", assigned: "◐", own: "○" };

/**
 * The permission matrix. The owner edits a role's column in place; the
 * server applies the guard-rails (docs/04 §4) and reports what it refused.
 */
export default function RolesPage() {
  const { farmId } = useParams<{ farmId: string }>();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const canManage = workspace?.type === "farm" && can(workspace.permissions, "roles.manage");

  const roles = useQuery({
    queryKey: ["roles", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/roles", { params: { path: { farm: farmId } } })).data!.data!,
  });
  const permissions = useQuery({
    queryKey: ["permissions", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/permissions", { params: { path: { farm: farmId } } })).data!.data!,
  });

  const [editing, setEditing] = useState<{ role: Role; grants: Record<string, string> } | null>(null);
  const [dialog, setDialog] = useState<{ mode: "create" } | { mode: "rename"; role: Role } | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [saving, setSaving] = useState(false);

  const permissionList = permissions.data;
  const modules = useMemo(() => {
    const grouped = new Map<string, Permission[]>();
    for (const p of permissionList ?? []) {
      const list = grouped.get(p.module ?? "") ?? [];
      list.push(p);
      grouped.set(p.module ?? "", list);
    }
    return [...grouped.entries()];
  }, [permissionList]);

  const refresh = () => queryClient.invalidateQueries({ queryKey: ["roles", farmId] });

  async function saveGrants() {
    if (!editing) return;
    setSaving(true);
    setError(null);
    try {
      await api.PUT("/farms/{farm}/roles/{role}/permissions", {
        params: { path: { farm: farmId, role: editing.role.id! } },
        body: { grants: editing.grants as Record<string, "all" | "assigned" | "own"> },
      });
      setEditing(null);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    } finally {
      setSaving(false);
    }
  }

  async function deleteRole(role: Role) {
    if (!window.confirm(`Delete the role "${role.name}"?`)) return;
    setError(null);
    try {
      await api.DELETE("/farms/{farm}/roles/{role}", { params: { path: { farm: farmId, role: role.id! } } });
      setEditing(null);
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  if (roles.isLoading || permissions.isLoading) return <Skeleton className="h-96 w-full" />;
  if (roles.error || permissions.error) return <ErrorNotice error={roles.error ?? permissions.error} />;

  const grantErrors = error?.problem.errors ?? {};

  return (
    <>
      <PageHeader
        title="Roles & permissions"
        description="● whole farm · ◐ assigned records · ○ own records only. The server enforces every permission."
        actions={
          canManage ? (
            <Button onClick={() => setDialog({ mode: "create" })}>
              <Plus /> New role
            </Button>
          ) : null
        }
      />

      {editing ? (
        <div className="sticky top-0 z-20 mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-accent/40 bg-surface px-4 py-3 text-sm shadow-sm">
          <span className="flex-1">
            Editing <strong>{editing.role.name}</strong> · {Object.keys(editing.grants).length} permissions
          </span>
          <Button size="sm" variant="ghost" onClick={() => setDialog({ mode: "rename", role: editing.role })}>
            Rename
          </Button>
          {!editing.role.is_system ? (
            <Button size="sm" variant="ghost" className="text-danger" onClick={() => deleteRole(editing.role)}>
              Delete role
            </Button>
          ) : null}
          <Button
            size="sm"
            variant="ghost"
            onClick={() => {
              setEditing(null);
              setError(null);
            }}
          >
            Cancel
          </Button>
          <Button size="sm" onClick={saveGrants} disabled={saving}>
            {saving ? "Saving…" : "Save permissions"}
          </Button>
        </div>
      ) : null}
      {error ? (
        <div className="mb-4 space-y-1">
          <ErrorNotice error={error} />
          {Object.entries(grantErrors).length > 0 ? (
            <ul className="list-disc pl-5 text-sm text-danger">
              {Object.entries(grantErrors).map(([field, messages]) => (
                <li key={field}>
                  <span className="font-mono">{field.replace(/^grants\./, "")}</span>: {messages[0]}
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      ) : null}

      <Table>
        <thead>
          <tr>
            <Th className="sticky left-0 z-10 min-w-64">Permission</Th>
            {roles.data!.map((r) => (
              <Th key={r.id} className="text-center">
                <span className="inline-flex items-center gap-1">
                  {r.name}
                  {r.is_locked ? <Lock className="size-3" aria-label="Locked" /> : null}
                  {canManage && !r.is_locked && !editing ? (
                    <button
                      type="button"
                      className="rounded p-0.5 hover:bg-surface"
                      aria-label={`Edit ${r.name}`}
                      onClick={() => {
                        setError(null);
                        setEditing({ role: r, grants: { ...(r.grants ?? {}) } });
                      }}
                    >
                      <Pencil className="size-3" />
                    </button>
                  ) : null}
                </span>
                {r.member_count !== undefined ? (
                  <span className="block text-[11px] font-normal normal-case">
                    {r.member_count} {r.member_count === 1 ? "member" : "members"}
                  </span>
                ) : null}
              </Th>
            ))}
          </tr>
        </thead>
        <tbody>
          {modules.map(([module, list]) => (
            <ModuleRows
              key={module}
              module={module}
              list={list}
              roles={roles.data!}
              editing={editing}
              errors={grantErrors}
              onChange={(key, scope) =>
                setEditing((current) => {
                  if (!current) return current;
                  const grants = { ...current.grants };
                  if (scope) grants[key] = scope;
                  else delete grants[key];
                  return { ...current, grants };
                })
              }
            />
          ))}
        </tbody>
      </Table>

      {dialog ? (
        <RoleDialog
          farmId={farmId}
          state={dialog}
          roles={roles.data!}
          onClose={() => setDialog(null)}
          onSaved={async (role) => {
            setDialog(null);
            await refresh();
            setEditing({ role, grants: { ...(role.grants ?? {}) } });
          }}
        />
      ) : null}
    </>
  );
}

function ModuleRows({
  module,
  list,
  roles,
  editing,
  errors,
  onChange,
}: {
  module: string;
  list: Permission[];
  roles: Role[];
  editing: { role: Role; grants: Record<string, string> } | null;
  errors: Record<string, string[]>;
  onChange: (key: string, scope: string | null) => void;
}) {
  return (
    <>
      <tr>
        <Td colSpan={roles.length + 1} className="bg-surface-muted/60 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted">
          {humanize(module)}
        </Td>
      </tr>
      {list.map((p) => {
        const key = p.key ?? "";
        const problem = errors[`grants.${key}`]?.[0];
        return (
          <tr key={key}>
            <Td className="sticky left-0 bg-surface">
              <span className="block text-sm">{p.description}</span>
              <span className="font-mono text-[11px] text-muted">
                {key}
                {p.money ? " · $" : ""}
                {p.owner_only ? " · owner only" : ""}
              </span>
              {problem ? <span className="block text-xs text-danger">{problem}</span> : null}
            </Td>
            {roles.map((r) => {
              if (editing && editing.role.id === r.id) {
                return (
                  <Td key={r.id} className="bg-primary-soft/40 text-center">
                    <Select
                      aria-label={`${key} for ${r.name}`}
                      className="h-8 min-w-24 text-xs"
                      value={editing.grants[key] ?? ""}
                      disabled={p.owner_only}
                      onChange={(e) => onChange(key, e.target.value || null)}
                    >
                      <option value="">—</option>
                      {(p.scopes ?? []).map((s) => (
                        <option key={s} value={s}>
                          {s}
                        </option>
                      ))}
                    </Select>
                  </Td>
                );
              }
              const scope = r.grants?.[key];
              return (
                <Td key={r.id} className="text-center text-primary" title={scope ?? "no access"}>
                  {scope ? SCOPE_MARK[scope] : <span className="text-border">—</span>}
                </Td>
              );
            })}
          </tr>
        );
      })}
    </>
  );
}

function RoleDialog({
  farmId,
  state,
  roles,
  onClose,
  onSaved,
}: {
  farmId: string;
  state: { mode: "create" } | { mode: "rename"; role: Role };
  roles: Role[];
  onClose: () => void;
  onSaved: (role: Role) => void;
}) {
  const [error, setError] = useState<ApiError | null>(null);
  const current = state.mode === "rename" ? state.role : null;

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    const f = new FormData(e.currentTarget);
    const name = String(f.get("name"));
    const description = String(f.get("description") || "") || null;
    try {
      const res = current
        ? await api.PATCH("/farms/{farm}/roles/{role}", { params: { path: { farm: farmId, role: current.id! } }, body: { name, description } })
        : await api.POST("/farms/{farm}/roles", {
            params: { path: { farm: farmId } },
            body: { name, description, copy_from_role_id: String(f.get("copy_from") || "") || null },
          });
      onSaved(res.data!.data!);
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  return (
    <Dialog open onClose={onClose} title={current ? "Rename role" : "New role"} description={current ? undefined : "A custom role, e.g. “Dairy Supervisor”. Owner-only permissions are never copied."}>
      <form onSubmit={submit} className="space-y-3">
        <div>
          <Label htmlFor="role-name">Name</Label>
          <Input id="role-name" name="name" required minLength={2} maxLength={100} defaultValue={current?.name ?? ""} aria-invalid={!!error?.fieldError("name")} />
          <FieldError>{error?.fieldError("name")}</FieldError>
        </div>
        <div>
          <Label htmlFor="role-description">Description</Label>
          <Input id="role-description" name="description" maxLength={255} defaultValue={current?.description ?? ""} />
        </div>
        {!current ? (
          <div>
            <Label htmlFor="copy_from">Start from</Label>
            <Select id="copy_from" name="copy_from" defaultValue="">
              <option value="">No permissions</option>
              {roles.map((r) => (
                <option key={r.id} value={r.id}>
                  Copy of {r.name}
                </option>
              ))}
            </Select>
          </div>
        ) : null}
        {error && !error.problem.errors ? (
          <p className="text-sm text-danger" role="alert">
            {error.problem.title}
          </p>
        ) : null}
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit">{current ? "Save" : "Create role"}</Button>
        </div>
      </form>
    </Dialog>
  );
}
