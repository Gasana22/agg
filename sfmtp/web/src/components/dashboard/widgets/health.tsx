"use client";

import dynamic from "next/dynamic";
import Link from "next/link";

import { Badge } from "@/components/ui/badge";
import { EmptyState, Skeleton } from "@/components/ui/misc";
import { BAND } from "@/lib/analytics";

export type HealthPoint = {
  id: string;
  title: string;
  subtitle?: string;
  lat?: number | null;
  lng?: number | null;
  score: number;
  band: string;
  href?: string;
};
export type Bands = { good?: number; watch?: number; poor?: number };

// Leaflet only when a dashboard shows the crop health map.
const HealthMap = dynamic(() => import("./health-map"), {
  ssr: false,
  loading: () => <Skeleton className="h-48 w-full" />,
});

/** Good / watch / poor counts, as small badges. */
export function HealthBands({ bands }: { bands?: Bands }) {
  if (!bands) return null;
  return (
    <p className="mb-3 flex flex-wrap gap-2 text-xs" aria-label="Health bands">
      {(["good", "watch", "poor"] as const).map((b) => (
        <Badge key={b} tone={BAND[b].tone}>
          {BAND[b].label}: {bands[b] ?? 0}
        </Badge>
      ))}
    </p>
  );
}

/**
 * Crop health: each open cycle on a small map at its plot, coloured by its
 * score, with the worst cycles listed below and why they lost points.
 */
export function HealthMapWidget({
  data,
}: {
  data: { points?: HealthPoint[]; bands?: Bands };
}) {
  const points = data.points ?? [];
  if (points.length === 0) return <EmptyState title="No open crop cycles." />;
  const located = points.filter((p) => p.lat != null && p.lng != null);

  return (
    <div>
      <HealthBands bands={data.bands} />
      {located.length > 0 ? <HealthMap points={located} /> : null}
      <ul className="mt-3 divide-y divide-border">
        {points.slice(0, 5).map((p) => {
          const body = (
            <div className="flex items-start justify-between gap-3 py-2">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium">{p.title}</p>
                {p.subtitle ? (
                  <p className="truncate text-xs text-muted">{p.subtitle}</p>
                ) : null}
              </div>
              <span
                className="shrink-0 text-sm font-semibold tabular-nums"
                style={{ color: BAND[p.band]?.color }}
              >
                {p.score}/100
              </span>
            </div>
          );
          return (
            <li key={p.id}>
              {p.href ? (
                <Link href={p.href} className="block hover:bg-surface-muted/60">
                  {body}
                </Link>
              ) : (
                body
              )}
            </li>
          );
        })}
      </ul>
    </div>
  );
}
