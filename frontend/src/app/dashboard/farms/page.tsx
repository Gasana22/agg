"use client";

import * as React from "react";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { Plus, MapPin } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { listFarms, createFarm } from "@/lib/modules/farm-structure";
import { formatRole } from "@/lib/utils";

const schema = z.object({
  name: z.string().min(1, "Name is required"),
  district: z.string().optional(),
  village: z.string().optional(),
  gps_lat: z.string().optional(),
  gps_lng: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

export default function FarmsPage() {
  const queryClient = useQueryClient();
  const [open, setOpen] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const { data: farms, isLoading } = useQuery({ queryKey: ["farms"], queryFn: listFarms });

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const createMutation = useMutation({
    mutationFn: (values: FormValues) =>
      createFarm({
        name: values.name,
        district: values.district || undefined,
        village: values.village || undefined,
        gps_lat: values.gps_lat ? Number(values.gps_lat) : undefined,
        gps_lng: values.gps_lng ? Number(values.gps_lng) : undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["farms"] });
      setOpen(false);
      reset();
    },
    onError: (err) => {
      setError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not create farm." : "Something went wrong."
      );
    },
  });

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Farm Structure</h1>
          <p className="text-sm text-muted-foreground">
            Farms you own or are a member of, each broken down into blocks, sections, and plots.
          </p>
        </div>
        <Dialog open={open} onOpenChange={setOpen}>
          <DialogTrigger asChild>
            <Button className="gap-2">
              <Plus className="size-4" />
              New Farm
            </Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Create a farm</DialogTitle>
              <DialogDescription>You become this farm&apos;s owner automatically.</DialogDescription>
            </DialogHeader>
            <form
              onSubmit={handleSubmit((values) => {
                setError(null);
                createMutation.mutate(values);
              })}
              className="flex flex-col gap-4"
            >
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="name">Name</Label>
                <Input id="name" {...register("name")} />
                {errors.name && <p className="text-xs text-destructive">{errors.name.message}</p>}
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="district">District</Label>
                  <Input id="district" {...register("district")} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="village">Village</Label>
                  <Input id="village" {...register("village")} />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="gps_lat">GPS latitude</Label>
                  <Input id="gps_lat" type="number" step="any" {...register("gps_lat")} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="gps_lng">GPS longitude</Label>
                  <Input id="gps_lng" type="number" step="any" {...register("gps_lng")} />
                </div>
              </div>
              {error && <p className="text-sm text-destructive">{error}</p>}
              <DialogFooter>
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting ? "Creating…" : "Create farm"}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading farms…</p>
      ) : !farms || farms.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            No farms yet. Create your first farm to get started.
          </CardContent>
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {farms.map((farm) => (
            <Link key={farm.id} href={`/dashboard/farms/${farm.id}`}>
              <Card className="h-full transition-shadow hover:shadow-md">
                <CardHeader>
                  <div className="flex items-start justify-between gap-2">
                    <CardTitle className="text-base">{farm.name}</CardTitle>
                    {!farm.is_active && <Badge variant="secondary">Inactive</Badge>}
                  </div>
                  {(farm.district || farm.village) && (
                    <CardDescription className="flex items-center gap-1">
                      <MapPin className="size-3.5" />
                      {[farm.village, farm.district].filter(Boolean).join(", ")}
                    </CardDescription>
                  )}
                </CardHeader>
                <CardContent className="flex items-center justify-between pb-6 text-sm text-muted-foreground">
                  <span>{farm.blocks_count ?? 0} block{farm.blocks_count === 1 ? "" : "s"}</span>
                  {farm.my_role && <Badge variant="outline">{formatRole(farm.my_role)}</Badge>}
                </CardContent>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
