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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import {
  getBlock,
  updateBlock,
  listSections,
  createSection,
  updateSection,
  deleteSection,
  type Section,
} from "@/lib/modules/farm-structure";
import { usePermissions } from "@/lib/permissions";
import { useConfirm } from "@/components/confirm-provider";

const sectionSchema = z.object({
  name: z.string().min(1, "Name is required"),
  gps_lat: z.string().optional(),
  gps_lng: z.string().optional(),
});
type SectionFormValues = z.infer<typeof sectionSchema>;

const blockEditSchema = z.object({
  name: z.string().min(1, "Name is required"),
  gps_lat: z.string().optional(),
  gps_lng: z.string().optional(),
});
type BlockEditFormValues = z.infer<typeof blockEditSchema>;

export default function BlockDetailPage() {
  const params = useParams<{ farmId: string; blockId: string }>();
  const farmId = Number(params.farmId);
  const blockId = Number(params.blockId);
  const queryClient = useQueryClient();
  const { canManageFarm } = usePermissions();
  const confirm = useConfirm();

  const [sectionOpen, setSectionOpen] = React.useState(false);
  const [sectionError, setSectionError] = React.useState<string | null>(null);
  const [editingSection, setEditingSection] = React.useState<Section | null>(null);
  const [sectionEditError, setSectionEditError] = React.useState<string | null>(null);
  const [blockEditOpen, setBlockEditOpen] = React.useState(false);
  const [blockEditError, setBlockEditError] = React.useState<string | null>(null);

  const { data: block } = useQuery({
    queryKey: ["block", blockId],
    queryFn: () => getBlock(blockId),
  });

  const { data: sections, isLoading: sectionsLoading } = useQuery({
    queryKey: ["sections", blockId],
    queryFn: () => listSections(blockId),
  });

  const sectionForm = useForm<SectionFormValues>({ resolver: zodResolver(sectionSchema) });
  const sectionEditForm = useForm<SectionFormValues>({ resolver: zodResolver(sectionSchema) });
  const blockEditForm = useForm<BlockEditFormValues>({ resolver: zodResolver(blockEditSchema) });

  const createSectionMutation = useMutation({
    mutationFn: (values: SectionFormValues) =>
      createSection(blockId, {
        name: values.name,
        gps_lat: values.gps_lat ? Number(values.gps_lat) : undefined,
        gps_lng: values.gps_lng ? Number(values.gps_lng) : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["sections", blockId] });
      setSectionOpen(false);
      sectionForm.reset();
    },
    onError: (err) =>
      setSectionError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add section." : "Something went wrong."
      ),
  });

  const editSectionMutation = useMutation({
    mutationFn: (values: SectionFormValues) =>
      updateSection(editingSection!.id, {
        name: values.name,
        gps_lat: values.gps_lat ? Number(values.gps_lat) : undefined,
        gps_lng: values.gps_lng ? Number(values.gps_lng) : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["sections", blockId] });
      setEditingSection(null);
    },
    onError: (err) =>
      setSectionEditError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not update section." : "Something went wrong."
      ),
  });

  const deleteSectionMutation = useMutation({
    mutationFn: (sectionId: number) => deleteSection(sectionId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["sections", blockId] }),
  });

  const editBlockMutation = useMutation({
    mutationFn: (values: BlockEditFormValues) =>
      updateBlock(blockId, {
        name: values.name,
        gps_lat: values.gps_lat ? Number(values.gps_lat) : undefined,
        gps_lng: values.gps_lng ? Number(values.gps_lng) : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["block", blockId] });
      queryClient.invalidateQueries({ queryKey: ["blocks", farmId] });
      setBlockEditOpen(false);
    },
    onError: (err) =>
      setBlockEditError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not update block." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link href={`/dashboard/farms/${farmId}`} className="text-sm text-muted-foreground hover:underline">
          ← Back to farm
        </Link>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{block?.name ?? "Block"}</h1>
          <p className="text-sm text-muted-foreground">
            {block?.gps_lat && block?.gps_lng ? `${block.gps_lat}, ${block.gps_lng}` : "No GPS set"}
          </p>
        </div>
        {canManageFarm && (
        <Dialog
          open={blockEditOpen}
          onOpenChange={(open) => {
            setBlockEditOpen(open);
            if (open && block) {
              blockEditForm.reset({
                name: block.name,
                gps_lat: block.gps_lat ?? "",
                gps_lng: block.gps_lng ?? "",
              });
              setBlockEditError(null);
            }
          }}
        >
          <DialogTrigger asChild>
            <Button size="sm" variant="outline" className="gap-2" disabled={!block}>
              <Pencil className="size-4" />
              Edit block
            </Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Edit block</DialogTitle>
            </DialogHeader>
            <form
              onSubmit={blockEditForm.handleSubmit((values) => {
                setBlockEditError(null);
                editBlockMutation.mutate(values);
              })}
              className="flex flex-col gap-4"
            >
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-block-detail-name">Name</Label>
                <Input id="edit-block-detail-name" {...blockEditForm.register("name")} />
                {blockEditForm.formState.errors.name && (
                  <p className="text-xs text-destructive">{blockEditForm.formState.errors.name.message}</p>
                )}
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="edit-block-detail-lat">GPS latitude</Label>
                  <Input id="edit-block-detail-lat" type="number" step="any" {...blockEditForm.register("gps_lat")} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="edit-block-detail-lng">GPS longitude</Label>
                  <Input id="edit-block-detail-lng" type="number" step="any" {...blockEditForm.register("gps_lng")} />
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
        )}
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Sections</CardTitle>
          {canManageFarm && (
          <Dialog open={sectionOpen} onOpenChange={setSectionOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add section
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Add a section</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={sectionForm.handleSubmit((values) => {
                  setSectionError(null);
                  createSectionMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="section-name">Name</Label>
                  <Input id="section-name" {...sectionForm.register("name")} />
                  {sectionForm.formState.errors.name && (
                    <p className="text-xs text-destructive">{sectionForm.formState.errors.name.message}</p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="section-lat">GPS latitude</Label>
                    <Input id="section-lat" type="number" step="any" {...sectionForm.register("gps_lat")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="section-lng">GPS longitude</Label>
                    <Input id="section-lng" type="number" step="any" {...sectionForm.register("gps_lng")} />
                  </div>
                </div>
                {sectionError && <p className="text-sm text-destructive">{sectionError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createSectionMutation.isPending}>
                    {createSectionMutation.isPending ? "Adding…" : "Add section"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
          )}
        </CardHeader>
        <CardContent className="pb-6">
          {sectionsLoading ? (
            <p className="text-sm text-muted-foreground">Loading sections…</p>
          ) : !sections || sections.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No sections yet. Sections divide a block into plots.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Plots</TableHead>
                  <TableHead>GPS</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-20" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {sections.map((section) => (
                  <TableRow key={section.id}>
                    <TableCell className="font-medium">
                      <Link
                        href={`/dashboard/farms/${farmId}/blocks/${blockId}/sections/${section.id}`}
                        className="hover:underline"
                      >
                        {section.name}
                      </Link>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{section.plots_count ?? 0}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {section.gps_lat && section.gps_lng ? `${section.gps_lat}, ${section.gps_lng}` : "—"}
                    </TableCell>
                    <TableCell>
                      <Badge variant={section.is_active ? "success" : "secondary"}>
                        {section.is_active ? "Active" : "Inactive"}
                      </Badge>
                    </TableCell>
                    <TableCell className="flex justify-end gap-1">
                      {canManageFarm && (
                        <>
                          <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => {
                              setEditingSection(section);
                              sectionEditForm.reset({
                                name: section.name,
                                gps_lat: section.gps_lat ?? "",
                                gps_lng: section.gps_lng ?? "",
                              });
                              setSectionEditError(null);
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
                                  title: `Delete section "${section.name}"?`,
                                  description: "This also removes its plots.",
                                  confirmLabel: "Delete",
                                })
                              ) {
                                deleteSectionMutation.mutate(section.id);
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

      <Dialog
        open={!!editingSection}
        onOpenChange={(open) => {
          if (!open) {
            setEditingSection(null);
            setSectionEditError(null);
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit section</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={sectionEditForm.handleSubmit((values) => {
              setSectionEditError(null);
              editSectionMutation.mutate(values);
            })}
            className="flex flex-col gap-4"
          >
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="edit-section-name">Name</Label>
              <Input id="edit-section-name" {...sectionEditForm.register("name")} />
              {sectionEditForm.formState.errors.name && (
                <p className="text-xs text-destructive">{sectionEditForm.formState.errors.name.message}</p>
              )}
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-section-lat">GPS latitude</Label>
                <Input id="edit-section-lat" type="number" step="any" {...sectionEditForm.register("gps_lat")} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="edit-section-lng">GPS longitude</Label>
                <Input id="edit-section-lng" type="number" step="any" {...sectionEditForm.register("gps_lng")} />
              </div>
            </div>
            {sectionEditError && <p className="text-sm text-destructive">{sectionEditError}</p>}
            <DialogFooter>
              <Button type="submit" disabled={editSectionMutation.isPending}>
                {editSectionMutation.isPending ? "Saving…" : "Save changes"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
