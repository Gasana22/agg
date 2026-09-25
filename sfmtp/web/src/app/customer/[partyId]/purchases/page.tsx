"use client";

import { useQuery } from "@tanstack/react-query";
import { QrCode } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";

import { Badge } from "@/components/ui/badge";
import { EmptyState, ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatDateTime, humanize } from "@/lib/format";
import { formatQty } from "@/lib/inventory";

function show(value: unknown): string {
  if (value === null || value === undefined) return "—";
  if (Array.isArray(value)) return value.map(show).join("; ");
  if (typeof value === "object") return Object.values(value as Record<string, unknown>).filter((v) => v !== null && v !== "").map(show).join(", ");
  return String(value);
}

/** Batches bought, with what the farm approved for the public (the same as a QR scan shows). */
export default function PurchasesPage() {
  const { partyId } = useParams<{ partyId: string }>();
  const rows = useQuery({
    queryKey: ["customer-purchases", partyId],
    queryFn: async () => (await api.GET("/customer/{party}/purchases", { params: { path: { party: partyId } } })).data!.data ?? [],
  });

  return (
    <>
      <PageHeader title="What I bought" description="Each batch you received, and where it came from as far as the farm has made public." />
      {rows.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : rows.error ? (
        <ErrorNotice error={rows.error} />
      ) : (rows.data ?? []).length === 0 ? (
        <EmptyState title="Nothing received yet" />
      ) : (
        <ul className="grid gap-4 lg:grid-cols-2">
          {rows.data!.map((p, i) => (
            <li key={i} className="rounded-2xl border border-border bg-surface p-4 text-sm">
              <div className="flex items-start justify-between gap-2">
                <div>
                  <p className="font-medium">
                    {formatQty(p.quantity, p.unit)} {p.description}
                  </p>
                  <p className="text-xs text-muted">
                    {p.farm?.name} · {p.shipment?.code} · {formatDateTime(p.shipment?.dispatched_at)}
                  </p>
                  <p className="font-mono text-xs text-muted">{p.batch_code}</p>
                </div>
                {p.recalled ? <Badge tone="danger">Recalled: do not use</Badge> : null}
              </div>
              {p.public ? (
                <dl className="mt-3 grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1 border-t border-border pt-3">
                  {Object.entries(p.public).map(([k, v]) => (
                    <div key={k} className="contents">
                      <dt className="text-muted">{humanize(k)}</dt>
                      <dd>{show(v)}</dd>
                    </div>
                  ))}
                </dl>
              ) : (
                <p className="mt-3 border-t border-border pt-3 text-xs text-muted">The farm has not published the details of this batch.</p>
              )}
              {p.qr_code ? (
                <Link href={`/q/${p.qr_code}`} className="mt-3 inline-flex items-center gap-1 text-primary hover:underline">
                  <QrCode className="size-4" /> Public page
                </Link>
              ) : null}
            </li>
          ))}
        </ul>
      )}
    </>
  );
}
