"use client";

import * as React from "react";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { Plus } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
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
import { listAssets, createAsset } from "@/lib/modules/assets";
import { listMembers } from "@/lib/modules/farm-structure";
import { useFarm } from "@/lib/farm-context";
import { usePermissions } from "@/lib/permissions";
import { formatRole } from "@/lib/utils";

const assetSchema = z.object({
  name: z.string().min(1, "Name is required"),
  category: z.string().optional(),
  serial_number: z.string().optional(),
  purchase_date: z.string().optional(),
  purchase_cost: z.string().optional(),
  assigned_to: z.string().optional(),
  notes: z.string().optional(),
});
type AssetFormValues = z.infer<typeof assetSchema>;

const STATUS_VARIANT: Record<string, "default" | "secondary" | "warning" | "success" | "destructive"> = {
  active: "success",
  under_maintenance: "warning",
  retired: "secondary",
};

export default function AssetsPage() {
  const { currentFarmId } = useFarm();
  const { canManageAssets } = usePermissions();
  const queryClient = useQueryClient();
  const [assetOpen, setAssetOpen] = React.useState(false);
  const [assetError, setAssetError] = React.useState<string | null>(null);

  const { data: assets, isLoading: assetsLoading } = useQuery({
    queryKey: ["assets", currentFarmId],
    queryFn: () => listAssets(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const { data: members } = useQuery({
    queryKey: ["farm-members", currentFarmId],
    queryFn: () => listMembers(currentFarmId!),
    enabled: !!currentFarmId,
  });

  const assetForm = useForm<AssetFormValues>({ resolver: zodResolver(assetSchema) });

  const createAssetMutation = useMutation({
    mutationFn: (values: AssetFormValues) =>
      createAsset(currentFarmId!, {
        name: values.name,
        category: values.category || undefined,
        serial_number: values.serial_number || undefined,
        purchase_date: values.purchase_date || undefined,
        purchase_cost: values.purchase_cost ? Number(values.purchase_cost) : undefined,
        assigned_to: values.assigned_to ? Number(values.assigned_to) : undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["assets", currentFarmId] });
      setAssetOpen(false);
      assetForm.reset();
    },
    onError: (err) =>
      setAssetError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add asset." : "Something went wrong."
      ),
  });

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Assets</h1>
        <p className="text-sm text-muted-foreground">Equipment, vehicles, and their maintenance history.</p>
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Assets</CardTitle>
          {canManageAssets && (
          <Dialog open={assetOpen} onOpenChange={setAssetOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2">
                <Plus className="size-4" />
                Add asset
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Add an asset</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={assetForm.handleSubmit((values) => {
                  setAssetError(null);
                  createAssetMutation.mutate(values);
                })}
                className="flex max-h-[70vh] flex-col gap-4 overflow-y-auto"
              >
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="asset-name">Name</Label>
                  <Input id="asset-name" {...assetForm.register("name")} />
                  {assetForm.formState.errors.name && (
                    <p className="text-xs text-destructive">{assetForm.formState.errors.name.message}</p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="asset-category">Category</Label>
                    <Input id="asset-category" placeholder="Tractor, Tool, Vehicle..." {...assetForm.register("category")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="asset-serial">Serial number</Label>
                    <Input id="asset-serial" {...assetForm.register("serial_number")} />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="purchase_date">Purchase date</Label>
                    <Input id="purchase_date" type="date" {...assetForm.register("purchase_date")} />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="purchase_cost">Purchase cost</Label>
                    <Input id="purchase_cost" type="number" step="any" {...assetForm.register("purchase_cost")} />
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label>Assigned to</Label>
                  <Select onValueChange={(v) => assetForm.setValue("assigned_to", v)}>
                    <SelectTrigger>
                      <SelectValue placeholder="Optional" />
                    </SelectTrigger>
                    <SelectContent>
                      {(members ?? []).map((member) => (
                        <SelectItem key={member.id} value={String(member.id)}>
                          {member.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="asset-notes">Notes</Label>
                  <Textarea id="asset-notes" rows={2} {...assetForm.register("notes")} />
                </div>
                {assetError && <p className="text-sm text-destructive">{assetError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createAssetMutation.isPending}>
                    {createAssetMutation.isPending ? "Adding…" : "Add asset"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
          )}
        </CardHeader>
        <CardContent className="pb-6">
          {assetsLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : !assets || assets.length === 0 ? (
            <p className="text-sm text-muted-foreground">No assets yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Category</TableHead>
                  <TableHead>Serial</TableHead>
                  <TableHead>Assigned to</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {assets.map((asset) => (
                  <TableRow key={asset.id}>
                    <TableCell className="font-medium">
                      <Link href={`/dashboard/assets/${asset.id}`} className="hover:underline">
                        {asset.name}
                      </Link>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{asset.category ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{asset.serial_number ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{asset.assignee?.name ?? "—"}</TableCell>
                    <TableCell>
                      <Badge variant={STATUS_VARIANT[asset.status] ?? "secondary"}>{formatRole(asset.status)}</Badge>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
