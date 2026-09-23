"use client";

import { useInfiniteQuery, useQueryClient } from "@tanstack/react-query";
import { UserPlus } from "lucide-react";
import { useDeferredValue, useState } from "react";

import { ListToolbar } from "@/components/admin/list-toolbar";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { Checkbox, FieldError, Input, Label, Select } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status";
import { api, type components } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { usePlatformWorkspace } from "@/lib/api/hooks";
import { formatDateTime } from "@/lib/format";
import { can } from "@/lib/permissions";

type Role = components["schemas"]["PlatformRole"];
const ROLES: Role[] = ["super_admin", "support", "billing"];

export default function AdminUsersPage() {
  const queryClient = useQueryClient();
  const { workspace } = usePlatformWorkspace();
  const manage = can(workspace?.permissions, "users.manage");
  const [type, setType] = useState("");
  const [q, setQ] = useState("");
  const [inviting, setInviting] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const search = useDeferredValue(q.trim());

  const query = useInfiniteQuery({
    queryKey: ["admin-users", type, search],
    initialPageParam: undefined as string | undefined,
    queryFn: async ({ pageParam }) =>
      (await api.GET("/admin/users", { params: { query: { cursor: pageParam, "filter[user_type]": (type || undefined) as "member" | undefined, q: search || undefined } } })).data!,
    getNextPageParam: (last) => last.meta?.next_cursor ?? undefined,
  });
  const rows = query.data?.pages.flatMap((p) => p.data ?? []) ?? [];
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["admin-users"] });

  async function setStatus(id: string, status: "active" | "disabled") {
    setError(null);
    try {
      await api.PATCH("/admin/users/{user}", { params: { path: { user: id } }, body: { status } });
      refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  return (
    <>
      <PageHeader
        title="Users & staff"
        description="Block or unblock any account (disabling signs it out everywhere). Manage SFMTP staff and their platform roles."
        actions={manage ? <Button onClick={() => setInviting(true)}><UserPlus /> Invite staff</Button> : null}
      />
      <div className="flex flex-wrap gap-2">
        <ListToolbar q={q} onQ={setQ} placeholder="Name or email" />
        <Select aria-label="Account type" className="mb-4 w-48" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">All accounts</option>
          <option value="platform_admin">SFMTP staff</option>
          <option value="member">Farm members</option>
          <option value="party">Suppliers & customers</option>
        </Select>
      </div>
      {error ? <div className="mb-4"><ErrorNotice error={error} /></div> : null}
      {query.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : query.error ? (
        <ErrorNotice error={query.error} />
      ) : rows.length === 0 ? (
        <EmptyState title="No accounts match" />
      ) : (
        <Table>
          <thead><tr><Th>Account</Th><Th>Type</Th><Th>Roles</Th><Th>Status</Th><Th>Last sign-in</Th><Th /></tr></thead>
          <tbody>
            {rows.map((u) => (
              <tr key={u.id}>
                <Td><p className="font-medium">{u.name}</p><p className="text-xs text-muted">{u.email}</p></Td>
                <Td className="text-sm">{u.user_type === "platform_admin" ? "SFMTP staff" : u.user_type === "member" ? `Farm member (${u.farm_count} farms)` : "Supplier / customer"}</Td>
                <Td className="space-x-1">{(u.platform_roles ?? []).map((r) => <Badge key={r} tone="primary">{r.replace("_", " ")}</Badge>)}</Td>
                <Td><StatusBadge status={u.status} />{!u.mfa_enabled ? <span className="ml-2 text-xs text-muted">no MFA</span> : null}</Td>
                <Td className="text-muted">{formatDateTime(u.last_login_at)}</Td>
                <Td className="text-right">
                  {manage ? (
                    u.status === "active" ? (
                      <Button size="sm" variant="secondary" onClick={() => setStatus(u.id!, "disabled")}>Disable</Button>
                    ) : (
                      <Button size="sm" variant="secondary" onClick={() => setStatus(u.id!, "active")}>Enable</Button>
                    )
                  ) : null}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      <InviteDialog open={inviting} onClose={() => setInviting(false)} onDone={() => { setInviting(false); refresh(); }} />
    </>
  );
}

function InviteDialog({ open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => void }) {
  const [error, setError] = useState<ApiError | null>(null);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    setError(null);
    try {
      await api.POST("/admin/users", {
        body: { name: String(f.get("name")), email: String(f.get("email")), platform_roles: ROLES.filter((r) => f.get(r) === "on") },
      });
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  return (
    <Dialog open={open} onClose={onClose} title="Invite SFMTP staff" description="They receive an email to set a password, then must set up MFA.">
      <form onSubmit={onSubmit} className="space-y-3">
        <div><Label htmlFor="name">Name</Label><Input id="name" name="name" required /></div>
        <div><Label htmlFor="email">Email</Label><Input id="email" name="email" type="email" required aria-invalid={!!error?.fieldError("email")} /></div>
        <fieldset>
          <legend className="mb-1.5 text-sm font-medium">Platform roles</legend>
          <div className="flex flex-wrap gap-4">{ROLES.map((r) => <Checkbox key={r} name={r} label={r.replace("_", " ")} defaultChecked={r === "support"} />)}</div>
        </fieldset>
        <FieldError>{error?.fieldError("email") ?? error?.fieldError("platform_roles") ?? (error ? error.problem.title : null)}</FieldError>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit">Send invite</Button>
        </div>
      </form>
    </Dialog>
  );
}
