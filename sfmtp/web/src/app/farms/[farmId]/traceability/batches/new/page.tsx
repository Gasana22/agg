"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";

import { KIND_LABELS } from "@/components/trace/labels";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { FieldError, Input, Label, Select } from "@/components/ui/input";
import { PageHeader } from "@/components/ui/misc";
import { api, idempotencyKey } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

const MANUAL_KINDS = ["seed_lot", "input_lot", "processed", "packaged"] as const;

export default function NewBatchPage() {
  const { farmId } = useParams<{ farmId: string }>();
  const router = useRouter();
  const queryClient = useQueryClient();
  const [error, setError] = useState<ApiError | null>(null);
  const [pending, setPending] = useState(false);
  const [key] = useState(idempotencyKey);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setPending(true);
    setError(null);
    const f = new FormData(e.currentTarget);
    const quantity = f.get("quantity") ? Number(f.get("quantity")) : undefined;
    try {
      const { data } = await api.POST("/farms/{farm}/traceability/batches", {
        params: { path: { farm: farmId }, header: { "Idempotency-Key": key } },
        body: {
          kind: f.get("kind") as (typeof MANUAL_KINDS)[number],
          name: String(f.get("name") || "") || undefined,
          quantity,
          unit: quantity !== undefined ? String(f.get("unit") || "kg") : undefined,
          notes: String(f.get("notes") || "") || undefined,
        },
      });
      await queryClient.invalidateQueries({ queryKey: ["batches", farmId] });
      router.replace(`/farms/${farmId}/traceability/batches/${data!.data!.id}`);
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
      setPending(false);
    }
  }

  return (
    <>
      <PageHeader title="New batch" description="Harvests, crop lots and animals are created automatically as work is recorded." />
      <Card className="max-w-xl">
        <CardContent>
          <form onSubmit={onSubmit} className="space-y-4">
            <div>
              <Label htmlFor="kind">Kind</Label>
              <Select id="kind" name="kind" defaultValue="processed">
                {MANUAL_KINDS.map((k) => (
                  <option key={k} value={k}>
                    {KIND_LABELS[k]}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="name">Name</Label>
              <Input id="name" name="name" placeholder="e.g. Dried maize, grade A" />
            </div>
            <div className="grid grid-cols-[1fr_8rem] gap-3">
              <div>
                <Label htmlFor="quantity">Quantity</Label>
                <Input id="quantity" name="quantity" type="number" min="0" step="0.001" aria-invalid={!!error?.fieldError("quantity")} />
              </div>
              <div>
                <Label htmlFor="unit">Unit</Label>
                <Select id="unit" name="unit" defaultValue="kg">
                  {["kg", "t", "l", "bags", "crates", "heads", "pcs"].map((u) => (
                    <option key={u}>{u}</option>
                  ))}
                </Select>
              </div>
            </div>
            <FieldError>{error?.fieldError("quantity")}</FieldError>
            <div>
              <Label htmlFor="notes">Notes</Label>
              <Input id="notes" name="notes" />
            </div>
            {error && !error.problem.errors ? <FieldError>{error.problem.title}</FieldError> : null}
            <div className="flex gap-2">
              <Button type="submit" disabled={pending}>
                {pending ? "Creating…" : "Create batch"}
              </Button>
              <Button type="button" variant="ghost" onClick={() => router.back()}>
                Cancel
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </>
  );
}
