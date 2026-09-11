"use client";

import * as React from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { Plus, Trash2, Pencil } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import {
  listMembers,
  addMember,
  updateMember,
  removeMember,
  listBlocks,
  createBlock,
  updateBlock,
  deleteBlock,
  updateFarm,
  FARM_ROLES,
  type Block,
} from "@/lib/modules/farm-structure";
import { useAuth } from "@/lib/auth-context";
import { useFarm } from "@/lib/farm-context";
import { usePermissions } from "@/lib/permissions";
import { useConfirm } from "@/components/confirm-provider";
import { formatRole } from "@/lib/utils";

const memberSchema = z.object({
  email: z.string().email("Enter a valid email"),
  role_on_farm: z.string().min(1, "Pick a role"),
});
type MemberFormValues = z.infer<typeof memberSchema>;

const blockSchema = z.object({
  name: z.string().min(1, "Name is required"),
  gps_lat: z.string().optional(),
  gps_lng: z.string().optional(),
});
type BlockFormValues = z.infer<typeof blockSchema>;

const farmEditSchema = z.object({
  name: z.string().min(1, "Name is required"),
  district: z.string().optional(),
  village: z.string().optional(),
  gps_lat: z.string().optional(),
  gps_lng: z.string().optional(),
});
type FarmEditFormValues = z.infer<typeof farmEditSchema>;

export default function FarmDetailPage() {
  const params = useParams<{ farmId: string }>();
  const farmId = Number(params.farmId);
  const { farms } = useFarm();
  const farm = farms.find((f) => f.id === farmId);
  const queryClient = useQueryClient();
  const { platformRoles } = useAuth();
  const isSystemAdministrator = platformRoles.includes("system_administrator");
  const { canManageFarm } = usePermissions();
  const confirm = useConfirm();

  const [memberDialogOpen, setMemberDialogOpen] = React.useState(false);
  const [blockDialogOpen, setBlockDialogOpen] = React.useState(false);
  const [memberError, setMemberError] = React.useState<string | null>(null);
  const [blockError, setBlockError] = React.useState<string | null>(null);
  const [farmEditOpen, setFarmEditOpen] = React.useState(false);
  const [farmEditError, setFarmEditError] = React.useState<string | null>(null);
  const [editingBlock, setEditingBlock] = React.useState<Block | null>(null);
  const [blockEditError, setBlockEditError] = React.useState<string | null>(null);

  // Members and blocks are farm-operations data (WorkerProfile-adjacent
  // staffing and physical structure) — not something system_administrator
  // has authority over, only farm_owner/farm_manager do. See
  // User::canViewFarm()/canManageFarm(). Skip the requests entirely for
  // admin rather than firing doomed ones.
  const { data: members, isLoading: membersLoading } = useQuery({
    queryKey: ["farm-members", farmId],
    queryFn: () => listMembers(farmId),
    enabled: !isSystemAdministrator,
  });

  const { data: blocks, isLoading: blocksLoading } = useQuery({
    queryKey: ["blocks", farmId],
    queryFn: () => listBlocks(farmId),
    enabled: !isSystemAdministrator,
  });

  const memberForm = useForm<MemberFormValues>({ resolver: zodResolver(memberSchema) });
  const blockForm = useForm<BlockFormValues>({ resolver: zodResolver(blockSchema) });
  const farmEditForm = useForm<FarmEditFormValues>({ resolver: zodResolver(farmEditSchema) });
  const blockEditForm = useForm<BlockFormValues>({ resolver: zodResolver(blockSchema) });

  const addMemberMutation = useMutation({
    mutationFn: (values: MemberFormValues) => addMember(farmId, values),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["farm-members", farmId] });
      setMemberDialogOpen(false);
      memberForm.reset();
    },
    onError: (err) =>
      setMemberError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add member." : "Something went wrong."
      ),
  });

  const updateRoleMutation = useMutation({
    mutationFn: ({ userId, role }: { userId: number; role: string }) => updateMember(farmId, userId, role),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["farm-members", farmId] }),
  });

  const removeMemberMutation = useMutation({
    mutationFn: (userId: number) => removeMember(farmId, userId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["farm-members", farmId] }),
  });

  const createBlockMutation = useMutation({
    mutationFn: (values: BlockFormValues) =>
      createBlock(farmId, {
        name: values.name,
        gps_lat: values.gps_lat ? Number(values.gps_lat) : undefined,
        gps_lng: values.gps_lng ? Number(values.gps_lng) : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["blocks", farmId] });
      queryClient.invalidateQueries({ queryKey: ["farms"] });
      setBlockDialogOpen(false);
      blockForm.reset();
    },
    onError: (err) =>
      setBlockError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not create block." : "Something went wrong."
      ),
  });

  const deleteBlockMutation = useMutation({
    mutationFn: deleteBlock,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["blocks", farmId] });
      queryClient.invalidateQueries({ queryKey: ["farms"] });
    },
  });

  const editFarmMutation = useMutation({
    mutationFn: (values: FarmEditFormValues) =>
      updateFarm(farmId, {
        name: values.name,
        district: values.district || undefined,
        village: values.village || undefined,
        gps_lat: values.gps_lat ? Number(values.gps_lat) : undefined,
        gps_lng: values.gps_lng ? Number(values.gps_lng) : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["farms"] });
      setFarmEditOpen(false);
    },
    onError: (err) =>
      setFarmEditError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not update farm." : "Something went wrong."
      ),
  });

  const editBlockMutation = useMutation({
    mutationFn: (values: BlockFormValues) =>
      updateBlock(editingBlock!.id, {
        name: values.name,
        gps_lat: values.gps_lat ? Number(values.gps_lat) : undefined,
        gps_lng: values.gps_lng ? Number(values.gps_lng) : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["blocks", farmId] });
      setEditingBlock(null);
    },
    onError: (err) =>
      setBlockEditError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not update block." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{farm?.name ?? "Farm"}</h1>
          <p className="text-sm text-muted-foreground">
            {[farm?.village, farm?.district].filter(Boolean).join(", ") || "No location set"}
          </p>
        </div>
        {(isSystemAdministrator || canManageFarm) && (
        <Dialog
          open={farmEditOpen}
          onOpenChange={(open) => {
            setFarmEditOpen(open);
            if (open && farm) {
              farmEditForm.reset({
                name: farm.name,
                district: farm.district ?? "",
                village: farm.village ?? "",
                gps_lat: farm.gps_lat ?? "",
                gps_lng: farm.gps_lng ?? "",
              });
              setFarmEditError(null);
            }
          }}
        >
          <DialogTrigger asChild>
            <Button size="sm" variant="outline" className="gap-2" disabled={!farm}>
              <Pencil className="size-4" />
              Edit farm
            </Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Edit farm</DialogTitle>
            </DialogHeader>
            <form
              onSubmit={farmEditForm.handleSubmit((values) => {
                setFarmEditError(null);
                editFarmMutation.mutate(values);
              })}
              className="flex flex-col gap-4"
            >
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-farm-name">Name</Label>
                <Input id="edit-farm-name" {...farmEditForm.register("name")} />
                {farmEditForm.formState.errors.name && (
                  <p className="text-xs text-destructive">{farmEditForm.formState.errors.name.message}</p>
                )}
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="edit-farm-district">District</Label>
                  <Input id="edit-farm-district" {...farmEditForm.register("district")} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="edit-farm-village">Village</Label>
                  <Input id="edit-farm-village" {...farmEditForm.register("village")} />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="edit-farm-lat">GPS latitude</Label>
                  <Input id="edit-farm-lat" type="number" step="any" {...farmEditForm.register("gps_lat")} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="edit-farm-lng">GPS longitude</Label>
                  <Input id="edit-farm-lng" type="number" step="any" {...farmEditForm.register("gps_lng")} />
                </div>
              </div>
              {farmEditError && <p className="text-sm text-destructive">{farmEditError}</p>}
              <DialogFooter>
                <Button type="submit" disabled={editFarmMutation.isPending}>
                  {editFarmMutation.isPending ? "Saving…" : "Save changes"}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
        )}
      </div>

      {isSystemAdministrator ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Members, blocks, sections, and plots are this farm&apos;s own operations — managed by its farm
            owner and manager, not platform administration.
          </CardContent>
        </Card>
      ) : (
        <>
      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Members</CardTitle>
          {canManageFarm && (
          <Dialog open={memberDialogOpen} onOpenChange={setMemberDialogOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add member
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Add a farm member</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={memberForm.handleSubmit((values) => {
                  setMemberError(null);
                  addMemberMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="email">User email</Label>
                  <Input id="email" type="email" {...memberForm.register("email")} />
                  {memberForm.formState.errors.email && (
                    <p className="text-xs text-destructive">
                      {memberForm.formState.errors.email.message}
                    </p>
                  )}
                  <p className="text-xs text-muted-foreground">
                    The user must already have an SFMTP account.
                  </p>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Role on this farm</Label>
                  <Select onValueChange={(v) => memberForm.setValue("role_on_farm", v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select a role" />
                    </SelectTrigger>
                    <SelectContent>
                      {FARM_ROLES.map((role) => (
                        <SelectItem key={role} value={role}>
                          {formatRole(role)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {memberForm.formState.errors.role_on_farm && (
                    <p className="text-xs text-destructive">
                      {memberForm.formState.errors.role_on_farm.message}
                    </p>
                  )}
                </div>
                {memberError && <p className="text-sm text-destructive">{memberError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={addMemberMutation.isPending}>
                    {addMemberMutation.isPending ? "Adding…" : "Add member"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
          )}
        </CardHeader>
        <CardContent className="pb-6">
          {membersLoading ? (
            <p className="text-sm text-muted-foreground">Loading members…</p>
          ) : !members || members.length === 0 ? (
            <p className="text-sm text-muted-foreground">No members yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Email</TableHead>
                  <TableHead>Role</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {members.map((member) => (
                  <TableRow key={member.id}>
                    <TableCell className="font-medium">{member.name}</TableCell>
                    <TableCell className="text-muted-foreground">{member.email}</TableCell>
                    <TableCell>
                      {member.role_on_farm === "farm_owner" || !canManageFarm ? (
                        <Badge variant="outline">{formatRole(member.role_on_farm)}</Badge>
                      ) : (
                        <Select
                          defaultValue={member.role_on_farm}
                          onValueChange={(role) =>
                            updateRoleMutation.mutate({ userId: member.id, role })
                          }
                        >
                          <SelectTrigger className="h-8 w-44">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {FARM_ROLES.filter((r) => r !== "farm_owner").map((role) => (
                              <SelectItem key={role} value={role}>
                                {formatRole(role)}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      )}
                    </TableCell>
                    <TableCell>
                      {canManageFarm && member.role_on_farm !== "farm_owner" && (
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={async () => {
                            if (
                              await confirm({
                                title: `Remove ${member.name} from this farm?`,
                                description: "They will lose access to this farm immediately.",
                                confirmLabel: "Remove",
                              })
                            ) {
                              removeMemberMutation.mutate(member.id);
                            }
                          }}
                        >
                          <Trash2 className="size-4 text-destructive" />
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Blocks</CardTitle>
          {canManageFarm && (
          <Dialog open={blockDialogOpen} onOpenChange={setBlockDialogOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add block
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Add a block</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={blockForm.handleSubmit((values) => {
                  setBlockError(null);
                  createBlockMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="block-name">Name</Label>
                  <Input id="block-name" {...blockForm.register("name")} />
                  {blockForm.formState.errors.name && (
                    <p className="text-xs text-destructive">{blockForm.formState.errors.name.message}</p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="block-lat">GPS latitude</Label>
                    <Input id="block-lat" type="number" step="any" {...blockForm.register("gps_lat")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="block-lng">GPS longitude</Label>
                    <Input id="block-lng" type="number" step="any" {...blockForm.register("gps_lng")} />
                  </div>
                </div>
                {blockError && <p className="text-sm text-destructive">{blockError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createBlockMutation.isPending}>
                    {createBlockMutation.isPending ? "Adding…" : "Add block"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
          )}
        </CardHeader>
        <CardContent className="pb-6">
          {blocksLoading ? (
            <p className="text-sm text-muted-foreground">Loading blocks…</p>
          ) : !blocks || blocks.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No blocks yet. Blocks divide a farm into sections and plots for crop seasons.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Sections</TableHead>
                  <TableHead>GPS</TableHead>
                  <TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {blocks.map((block) => (
                  <TableRow key={block.id}>
                    <TableCell className="font-medium">
                      <Link href={`/dashboard/farms/${farmId}/blocks/${block.id}`} className="hover:underline">
                        {block.name}
                      </Link>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{block.sections_count ?? 0}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {block.gps_lat && block.gps_lng ? `${block.gps_lat}, ${block.gps_lng}` : "—"}
                    </TableCell>
                    <TableCell className="flex justify-end gap-1">
                      {canManageFarm && (
                        <>
                          <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => {
                              setEditingBlock(block);
                              blockEditForm.reset({
                                name: block.name,
                                gps_lat: block.gps_lat ?? "",
                                gps_lng: block.gps_lng ?? "",
                              });
                              setBlockEditError(null);
                            }}
                          >
                            <Pencil className="size-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            onClick={async () => {
                              if (
                                await confirm({
                                  title: `Delete block "${block.name}"?`,
                                  description: "This also removes its sections and plots.",
                                  confirmLabel: "Delete",
                                })
                              ) {
                                deleteBlockMutation.mutate(block.id);
                              }
                            }}
                          >
                            <Trash2 className="size-4 text-destructive" />
                          </Button>
                        </>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
        </>
      )}

      <Dialog
        open={!!editingBlock}
        onOpenChange={(open) => {
          if (!open) {
            setEditingBlock(null);
            setBlockEditError(null);
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit block{editingBlock ? ` — ${editingBlock.name}` : ""}</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={blockEditForm.handleSubmit((values) => {
              setBlockEditError(null);
              editBlockMutation.mutate(values);
            })}
            className="flex flex-col gap-4"
          >
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="edit-block-name">Name</Label>
              <Input id="edit-block-name" {...blockEditForm.register("name")} />
              {blockEditForm.formState.errors.name && (
                <p className="text-xs text-destructive">{blockEditForm.formState.errors.name.message}</p>
              )}
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-block-lat">GPS latitude</Label>
                <Input id="edit-block-lat" type="number" step="any" {...blockEditForm.register("gps_lat")} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-block-lng">GPS longitude</Label>
                <Input id="edit-block-lng" type="number" step="any" {...blockEditForm.register("gps_lng")} />
              </div>
            </div>
            {blockEditError && <p className="text-sm text-destructive">{blockEditError}</p>}
            <DialogFooter>
              <Button type="submit" disabled={editBlockMutation.isPending}>
                {editBlockMutation.isPending ? "Saving…" : "Save changes"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
