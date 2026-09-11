"use client";

import { useParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { isAxiosError } from "axios";
import { AlertTriangle, MapPin, Sprout, Beef } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { getPublicTraceBatch } from "@/lib/modules/public-trace";
import { formatRole } from "@/lib/utils";

const STATUS_VARIANT: Record<string, "success" | "secondary" | "destructive"> = {
  active: "success",
  sold: "secondary",
  recalled: "destructive",
};

export default function PublicTracePage() {
  const params = useParams<{ code: string }>();
  const code = params.code;

  const { data: batch, isLoading, isError, error } = useQuery({
    queryKey: ["public-trace", code],
    queryFn: () => getPublicTraceBatch(code),
    retry: false,
  });

  const notFound = isError && isAxiosError(error) && error.response?.status === 404;

  return (
    <div className="flex min-h-full flex-1 items-start justify-center bg-muted/30 px-4 py-10">
      <div className="flex w-full max-w-xl flex-col gap-4">
        <div className="text-center">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">SFMTP Traceability</p>
        </div>

        {isLoading ? (
          <Card>
            <CardContent className="py-16 text-center text-sm text-muted-foreground">Loading…</CardContent>
          </Card>
        ) : notFound ? (
          <Card>
            <CardContent className="py-16 text-center">
              <p className="text-base font-medium">We couldn&apos;t find that batch</p>
              <p className="mt-1 text-sm text-muted-foreground">
                The code <span className="font-mono">{code}</span> doesn&apos;t match any product on record.
              </p>
            </CardContent>
          </Card>
        ) : isError || !batch ? (
          <Card>
            <CardContent className="py-16 text-center text-sm text-muted-foreground">
              Something went wrong loading this product&apos;s history. Please try again.
            </CardContent>
          </Card>
        ) : (
          <>
            {batch.status === "recalled" && (
              <Card className="border-destructive/50 bg-destructive/5">
                <CardContent className="flex items-center gap-3 py-4 text-sm text-destructive">
                  <AlertTriangle className="size-5 shrink-0" />
                  <p>
                    <span className="font-semibold">This batch has been recalled.</span> If you have this product,
                    please follow guidance from the point of purchase.
                  </p>
                </CardContent>
              </Card>
            )}

            <Card>
              <CardHeader className="flex flex-row items-start justify-between gap-3">
                <div>
                  <CardTitle className="text-xl">{batch.product_name}</CardTitle>
                  <p className="mt-1 font-mono text-xs text-muted-foreground">{batch.code}</p>
                </div>
                <Badge variant={STATUS_VARIANT[batch.status] ?? "secondary"}>{formatRole(batch.status)}</Badge>
              </CardHeader>
              <CardContent className="flex flex-col gap-4 pb-6">
                <div className="flex items-center justify-between text-sm">
                  <span className="text-muted-foreground">Quantity</span>
                  <span className="font-medium">
                    {batch.quantity} {batch.unit}
                  </span>
                </div>

                {batch.farm && (
                  <div className="flex items-start gap-2 rounded-md border p-3 text-sm">
                    <MapPin className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <div>
                      <p className="font-medium">{batch.farm.name}</p>
                      {(batch.farm.district || batch.farm.village) && (
                        <p className="text-muted-foreground">
                          {[batch.farm.village, batch.farm.district].filter(Boolean).join(", ")}
                        </p>
                      )}
                    </div>
                  </div>
                )}

                {batch.origin?.type === "crop_harvest" && (
                  <div className="flex items-start gap-2 rounded-md border p-3 text-sm">
                    <Sprout className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <div>
                      <p className="font-medium">Harvested {new Date(batch.origin.harvest_date).toLocaleDateString()}</p>
                      {batch.origin.quality_grade && (
                        <p className="text-muted-foreground">Grade: {batch.origin.quality_grade}</p>
                      )}
                    </div>
                  </div>
                )}

                {batch.origin?.type === "animal_production_record" && (
                  <div className="flex items-start gap-2 rounded-md border p-3 text-sm">
                    <Beef className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <div>
                      <p className="font-medium">{formatRole(batch.origin.product_type)}</p>
                      <p className="text-muted-foreground">
                        Collected {new Date(batch.origin.collection_date).toLocaleDateString()}
                      </p>
                    </div>
                  </div>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">Journey</CardTitle>
              </CardHeader>
              <CardContent className="pb-6">
                {batch.timeline.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No history recorded yet.</p>
                ) : (
                  <ol className="flex flex-col gap-4">
                    {batch.timeline.map((event, index) => (
                      <li key={event.id} className="flex gap-3">
                        <div className="flex flex-col items-center">
                          <span
                            className={`flex size-2.5 shrink-0 rounded-full ${
                              event.type === "recalled" ? "bg-destructive" : "bg-primary"
                            }`}
                          />
                          {index < batch.timeline.length - 1 && <span className="mt-1 w-px flex-1 bg-border" />}
                        </div>
                        <div className="pb-1">
                          <p className="text-sm font-medium">{formatRole(event.type)}</p>
                          <p className="text-xs text-muted-foreground">
                            {new Date(event.date).toLocaleDateString()}
                            {event.location ? ` · ${event.location}` : ""}
                          </p>
                          {event.notes && <p className="mt-1 text-xs text-muted-foreground">{event.notes}</p>}
                        </div>
                      </li>
                    ))}
                  </ol>
                )}
              </CardContent>
            </Card>
          </>
        )}
      </div>
    </div>
  );
}
