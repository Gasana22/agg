"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { MailPlus } from "lucide-react";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import { assignableRoles, RolePicker } from "@/components/members/role-picker";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { FieldError, Input, Label, Textarea } from "@/components/ui/input";
import { EmptyState, ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api, idempotencyKey } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { useFarmWorkspace, useMe } from "@/lib/api/hooks";
import type { components } from "@/lib/api/schema";
import { formatDateTime } from "@/lib/format";
import { can } from "@/lib/permissions";

type Member = components["schemas"]["Member"];

export default function MembersPage() {
  return (
    <Suspense>
      <Members />
    </Suspense>
  );
}

function Members() {
  const { farmId } = useParams<{ farmId: string }>();
  const router = useRouter();
  const search = useSearchParams();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const me = useMe();
  const writable = workspace?.type === "farm";
  const canManage = writable && can(workspace?.permissions, "members.manage");
  const canInvite = writable && (canManage || can(workspace?.permissions, "members.invite_workers"));

  const [inviting, setInviting] = useState(search.get("invite") === "1");
  const [editing, setEditing] = useState<Member | null>(null);
  const [actionError, setActionError] = useState<ApiError | null>(null);

  const path = { params: { path: { farm: farmId } } };
  const members = useQuery({
    queryKey: ["members", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/members", { params: { path: { farm: farmId }, query: { per_page: 100 } } })).data!.data!,
  });
  const invitations = useQuery({
    queryKey: ["invitations", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/invitations", { params: { path: { farm: farmId }, query: { per_page: 100 } } })).data!.data!,
  });
  const roles = useQuery({
    queryKey: ["roles", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/roles", path)).data!.data!,
    enabled: canInvite,
  });

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ["members", farmId] });
    await queryClient.invalidateQueries({ queryKey: ["invitations", farmId] });
  };

  async function act(fn: () => Promise<unknown>, confirmText?: string) {
    if (confirmText && !window.confirm(confirmText)) return;
    setActionError(null);
    try {
      await fn();
      await refresh();
    } catch (err) {
      setActionError(err instanceof ApiError ? err : null);
    }
  }

  const closeInvite = () => {
    setInviting(false);
    if (search.get("invite")) router.replace(`/farms/${farmId}/members`);
  };

  const myId = me.data?.data?.id;
  const offered = assignableRoles(roles.data ?? [], canManage);

  return (
    <>
      <PageHeader
        title="Members"
        description="People with access to this farm and the roles they hold."
        actions={
          canInvite ? (
            <Button onClick={() => setInviting(true)}>
              <MailPlus /> Invite
            </Button>
          ) : null
        }
      />
      {actionError ? (
        <div className="mb-4">
          <ErrorNotice error={actionError} />
        </div>
      ) : null}

      {members.isLoading ? (
        <Skeleton className="h-48 w-full" />
      ) : members.error ? (
        <ErrorNotice error={members.error} />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Name</Th>
              <Th>Roles</Th>
              <Th>Status</Th>
              <Th>Joined</Th>
              {canManage ? <Th className="text-right">Actions</Th> : null}
            </tr>
          </thead>
          <tbody>
            {members.data!.map((m) => {
              const locked = m.is_owner || m.user?.id === myId;
              return (
                <tr key={m.id}>
                  <Td>
                    <p className="font-medium">{m.user?.name}</p>
                    <p className="text-xs text-muted">{m.user?.email}</p>
                  </Td>
                  <Td>
                    <div className="flex flex-wrap gap-1">
                      {m.is_owner ? <Badge tone="primary">Farm Owner</Badge> : null}
                      {m.roles
                        ?.filter((r) => r.key !== "owner")
                        .map((r) => (
                          <Badge key={r.key} tone="neutral">
                            {r.name}
                          </Badge>
                        ))}
                    </div>
                  </Td>
                  <Td>
                    <Badge tone={m.status === "active" ? "primary" : "warning"}>{m.status}</Badge>
                  </Td>
                  <Td className="text-muted">{formatDateTime(m.joined_at)}</Td>
                  {canManage ? (
                    <Td className="text-right">
                      {locked ? (
                        <span className="text-xs text-muted">{m.is_owner ? "Owner" : "You"}</span>
                      ) : (
                        <div className="flex justify-end gap-1">
                          <Button size="sm" variant="ghost" onClick={() => setEditing(m)}>
                            Roles
                          </Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                              act(() =>
                                api.PATCH("/farms/{farm}/members/{member}", {
                                  params: { path: { farm: farmId, member: m.id! } },
                                  body: { status: m.status === "active" ? "suspended" : "active" },
                                }),
                              )
                            }
                          >
                            {m.status === "active" ? "Suspend" : "Reactivate"}
                          </Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            className="text-danger"
                            onClick={() =>
                              act(
                                () => api.DELETE("/farms/{farm}/members/{member}", { params: { path: { farm: farmId, member: m.id! } } }),
                                `Remove ${m.user?.name} from this farm? Their past records keep their name.`,
                              )
                            }
                          >
                            Remove
                          </Button>
                        </div>
                      )}
                    </Td>
                  ) : null}
                </tr>
              );
            })}
          </tbody>
        </Table>
      )}

      <h2 className="mb-3 mt-8 text-lg font-semibold">Pending invitations</h2>
      {invitations.isLoading ? (
        <Skeleton className="h-24 w-full" />
      ) : invitations.error ? (
        <ErrorNotice error={invitations.error} />
      ) : invitations.data!.length === 0 ? (
        <EmptyState title="No pending invitations">{canInvite ? "Invite your team with the Invite button." : null}</EmptyState>
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Email</Th>
              <Th>Roles</Th>
              <Th>Invited by</Th>
              <Th>Expires</Th>
              {canInvite ? <Th className="text-right">Actions</Th> : null}
            </tr>
          </thead>
          <tbody>
            {invitations.data!.map((i) => (
              <tr key={i.id}>
                <Td className="font-medium">{i.email}</Td>
                <Td>
                  <div className="flex flex-wrap gap-1">
                    {i.roles?.map((r) => (
                      <Badge key={r.id} tone="neutral">
                        {r.name}
                      </Badge>
                    ))}
                  </div>
                </Td>
                <Td className="text-muted">{i.invited_by?.name}</Td>
                <Td className="text-muted">
                  {formatDateTime(i.expires_at)}
                  {(i.send_count ?? 1) > 1 ? <span className="block text-xs">Sent {i.send_count}×</span> : null}
                </Td>
                {canInvite ? (
                  <Td className="text-right">
                    <div className="flex justify-end gap-1">
                      <Button size="sm" variant="ghost" onClick={() => act(() => api.POST("/farms/{farm}/invitations/{invitation}/resend", { params: { path: { farm: farmId, invitation: i.id! } } }))}>
                        Resend
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        className="text-danger"
                        onClick={() => act(() => api.DELETE("/farms/{farm}/invitations/{invitation}", { params: { path: { farm: farmId, invitation: i.id! } } }), `Withdraw the invitation to ${i.email}?`)}
                      >
                        Withdraw
                      </Button>
                    </div>
                  </Td>
                ) : null}
              </tr>
            ))}
          </tbody>
        </Table>
      )}

      {inviting && roles.isSuccess ? (
        <InviteDialog
          farmId={farmId}
          roles={offered}
          workersOnly={!canManage}
          onClose={closeInvite}
          onSent={async () => {
            closeInvite();
            await refresh();
          }}
        />
      ) : null}
      {editing && roles.isSuccess ? (
        <EditRolesDialog
          farmId={farmId}
          member={editing}
          roles={offered}
          onClose={() => setEditing(null)}
          onSaved={async () => {
            setEditing(null);
            await refresh();
          }}
        />
      ) : null}
    </>
  );
}

function InviteDialog({
  farmId,
  roles,
  workersOnly,
  onClose,
  onSent,
}: {
  farmId: string;
  roles: components["schemas"]["Role"][];
  workersOnly: boolean;
  onClose: () => void;
  onSent: () => void;
}) {
  const [roleIds, setRoleIds] = useState<string[]>(roles.length === 1 ? [roles[0].id!] : []);
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    const f = new FormData(e.currentTarget);
    try {
      await api.POST("/farms/{farm}/invitations", {
        params: { path: { farm: farmId }, header: { "Idempotency-Key": idempotencyKey() } },
        body: { email: String(f.get("email")), role_ids: roleIds, message: String(f.get("message") || "") || null },
      });
      onSent();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open onClose={onClose} title="Invite a member" description={workersOnly ? "You can invite field workers." : "They get an email with a link that works for 7 days."}>
      <form onSubmit={submit} className="space-y-4">
        <div>
          <Label htmlFor="email">Email</Label>
          <Input id="email" name="email" type="email" required autoComplete="off" aria-invalid={!!error?.fieldError("email")} />
          <FieldError>{error?.fieldError("email")}</FieldError>
        </div>
        <RolePicker roles={roles} selected={roleIds} onChange={setRoleIds} />
        <FieldError>{error?.fieldError("role_ids")}</FieldError>
        <div>
          <Label htmlFor="message">Personal note (optional)</Label>
          <Textarea id="message" name="message" rows={2} maxLength={500} />
        </div>
        {error && !error.problem.errors ? (
          <p className="text-sm text-danger" role="alert">
            {error.problem.title}
          </p>
        ) : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" disabled={busy || roleIds.length === 0}>
            {busy ? "Sending…" : "Send invitation"}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function EditRolesDialog({
  farmId,
  member,
  roles,
  onClose,
  onSaved,
}: {
  farmId: string;
  member: Member;
  roles: components["schemas"]["Role"][];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [roleIds, setRoleIds] = useState<string[]>((member.roles ?? []).map((r) => r.id!).filter(Boolean));
  const [error, setError] = useState<ApiError | null>(null);

  async function save() {
    setError(null);
    try {
      await api.PATCH("/farms/{farm}/members/{member}", { params: { path: { farm: farmId, member: member.id! } }, body: { role_ids: roleIds } });
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  return (
    <Dialog open onClose={onClose} title={`Roles — ${member.user?.name}`} description="Permissions are the union of all roles held.">
      <div className="space-y-4">
        <RolePicker roles={roles} selected={roleIds} onChange={setRoleIds} />
        {error ? <ErrorNotice error={error} /> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={save} disabled={roleIds.length === 0}>
            Save roles
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
