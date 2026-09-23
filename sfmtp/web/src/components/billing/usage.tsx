import type { components } from "@/lib/api/schema";

type Usage = components["schemas"]["Subscription"]["usage"];

function Meter({ label, used, limit }: { label: string; used?: number | null; limit?: number | null }) {
  const pct = used != null && limit ? Math.min(100, (used / limit) * 100) : 0;
  const tone = pct >= 100 ? "bg-danger" : pct >= 80 ? "bg-accent" : "bg-primary";
  return (
    <div>
      <div className="mb-1 flex justify-between text-sm">
        <span>{label}</span>
        <span className="tabular-nums text-muted">
          {used ?? "—"} / {limit ?? "unlimited"}
        </span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-surface-muted" role="meter" aria-label={label} aria-valuenow={used ?? 0} aria-valuemin={0} aria-valuemax={limit ?? undefined}>
        {limit ? <div className={`h-full rounded-full ${tone}`} style={{ width: `${pct}%` }} /> : null}
      </div>
    </div>
  );
}

export function UsageMeters({ usage }: { usage?: Usage }) {
  return (
    <div className="space-y-3">
      <Meter label="Farms" used={usage?.farms?.used} limit={usage?.farms?.limit} />
      <Meter label="Users" used={usage?.users?.used} limit={usage?.users?.limit} />
      <Meter label="Storage (MB)" used={usage?.storage_mb?.used} limit={usage?.storage_mb?.limit} />
    </div>
  );
}

export function money(m?: { amount?: string; currency?: string } | null): string {
  if (!m?.amount) return "—";
  return new Intl.NumberFormat("en-UG", { style: "currency", currency: m.currency ?? "UGX", maximumFractionDigits: 0 }).format(Number(m.amount));
}
