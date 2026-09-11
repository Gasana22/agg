"use client";

import * as React from "react";
import { useParams } from "next/navigation";
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
import { getTraceBatch, createTraceEvent, TRACE_EVENT_TYPES } from "@/lib/modules/traceability";
import { formatRole } from "@/lib/utils";

const eventSchema = z.object({
  type: z.string().min(1, "Pick a type"),
  date: z.string().min(1, "Date is required"),
  location: z.string().optional(),
  notes: z.string().optional(),
});
type EventFormValues = z.infer<typeof eventSchema>;

const STATUS_VARIANT: Record<string, "default" | "secondary" | "warning" | "success" | "destructive"> = {
  active: "default",
  sold: "success",
  recalled: "destructive",
};

const SOURCE_TYPE_LABEL: Record<string, string> = {
  "App\\Models\\CropHarvest": "Crop harvest",
  "App\\Models\\AnimalProductionRecord": "Animal production record",
};

export default function TraceBatchDetailPage() {
  const params = useParams<{ batchId: string }>();
  const batchId = Number(params.batchId);
  const queryClient = useQueryClient();

  const [eventOpen, setEventOpen] = React.useState(false);
  const [eventError, setEventError] = React.useState<string | null>(null);
  const [selectedType, setSelectedType] = React.useState("");

  const { data: batch } = useQuery({
    queryKey: ["trace-batch", batchId],
    queryFn: () => getTraceBatch(batchId),
  });

  const eventForm = useForm<EventFormValues>({ resolver: zodResolver(eventSchema) });

  const createEventMutation = useMutation({
    mutationFn: (values: EventFormValues) =>
      createTraceEvent(batchId, {
        type: values.type,
        date: values.date,
        location: values.location || undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["trace-batch", batchId] });
      queryClient.invalidateQueries({ queryKey: ["trace-batches"] });
      setEventOpen(false);
      setSelectedType("");
      eventForm.reset();
    },
    onError: (err) =>
      setEventError(
        isAxiosError(err) ? err.response?.data?.message ?? "Could not add event." : "Something went wrong."
      ),
  });

  const isClosed = batch?.status === "sold" || batch?.status === "recalled";

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{batch?.code ?? "Trace batch"}</h1>
          <p className="text-sm text-muted-foreground">
            {batch?.product_name}
            {batch ? ` · ${batch.quantity} ${batch.unit}` : ""}
            {batch ? ` · from ${SOURCE_TYPE_LABEL[batch.source_type] ?? batch.source_type} #${batch.source_id}` : ""}
          </p>
        </div>
        {batch && <Badge variant={STATUS_VARIANT[batch.status] ?? "secondary"}>{formatRole(batch.status)}</Badge>}
      </div>

      {batch && (
        <p className="text-xs text-muted-foreground">
          Public trace URL: <span className="font-mono">{batch.trace_url}</span>
        </p>
      )}

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Chain of custody</CardTitle>
          <Dialog open={eventOpen} onOpenChange={setEventOpen}>
            <DialogTrigger asChild>
              <Button size="sm" className="gap-2" disabled={isClosed}>
                <Plus className="size-4" />
                Add event
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Add a chain-of-custody event</DialogTitle>
              </DialogHeader>
              <form
                onSubmit={eventForm.handleSubmit((values) => {
                  setEventError(null);
                  createEventMutation.mutate(values);
                })}
                className="flex flex-col gap-4"
              >
                <div className="flex flex-col gap-1.5">
                  <Label>Type</Label>
                  <Select
                    onValueChange={(v) => {
                      eventForm.setValue("type", v);
                      setSelectedType(v);
                    }}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select a type" />
                    </SelectTrigger>
                    <SelectContent>
                      {TRACE_EVENT_TYPES.map((type) => (
                        <SelectItem key={type} value={type}>
                          {formatRole(type)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {eventForm.formState.errors.type && (
                    <p className="text-xs text-destructive">{eventForm.formState.errors.type.message}</p>
                  )}
                  {selectedType === "recalled" && (
                    <p className="text-xs text-amber-600">
                      Recalling this batch notifies farm management and closes its chain of custody.
                    </p>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="event-date">Date</Label>
                    <Input id="event-date" type="date" {...eventForm.register("date")} />
                    {eventForm.formState.errors.date && (
                      <p className="text-xs text-destructive">{eventForm.formState.errors.date.message}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <Label htmlFor="event-location">Location</Label>
                    <Input id="event-location" {...eventForm.register("location")} />
                  </div>
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="event-notes">Notes</Label>
                  <Textarea id="event-notes" rows={2} {...eventForm.register("notes")} />
                </div>
                {eventError && <p className="text-sm text-destructive">{eventError}</p>}
                <DialogFooter>
                  <Button type="submit" disabled={createEventMutation.isPending}>
                    {createEventMutation.isPending ? "Adding…" : "Add event"}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </CardHeader>
        <CardContent className="pb-6">
          {!batch || batch.events.length === 0 ? (
            <p className="text-sm text-muted-foreground">No events yet.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Type</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Location</TableHead>
                  <TableHead>Notes</TableHead>
                  <TableHead>Recorded by</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {batch.events.map((event) => (
                  <TableRow key={event.id}>
                    <TableCell className="font-medium">
                      <Badge variant={event.type === "recalled" ? "destructive" : "outline"}>
                        {formatRole(event.type)}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {new Date(event.date).toLocaleDateString()}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{event.location ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{event.notes ?? "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{event.recorder.name}</TableCell>
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
