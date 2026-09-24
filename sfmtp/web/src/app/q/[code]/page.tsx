import { AlertTriangle, BadgeCheck, CalendarDays, Factory, Leaf, MapPin, Route, ShieldCheck, Sprout } from "lucide-react";
import type { Metadata } from "next";
import { headers } from "next/headers";

import type { components } from "@/lib/api/client";
import { apiBase } from "@/lib/server/backend";

type PublicTrace = components["schemas"]["PublicTrace"];
type Fields = NonNullable<PublicTrace["fields"]>;

export const dynamic = "force-dynamic";
export const metadata: Metadata = { title: "Product traceability", robots: { index: false, follow: false } };

async function load(code: string): Promise<{ status: number; data?: PublicTrace }> {
  const h = await headers();
  const forward = new Headers({ accept: "application/json" });
  // The API rate-limits and counts per visitor, not per web server.
  const ip = h.get("x-forwarded-for")?.split(",")[0]?.trim() ?? h.get("x-real-ip");
  if (ip) forward.set("x-forwarded-for", ip);
  const country = h.get("cf-ipcountry") ?? h.get("x-country");
  if (country) forward.set("cf-ipcountry", country);
  try {
    const res = await fetch(`${apiBase()}/public/trace/${encodeURIComponent(code)}`, { headers: forward, cache: "no-store" });
    if (!res.ok) return { status: res.status };
    return { status: 200, data: (await res.json()).data };
  } catch {
    return { status: 503 };
  }
}

const formatDate = (d?: string | null) => (d ? new Date(`${d}T12:00:00Z`).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" }) : null);

export default async function PublicTracePage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  const { status, data } = await load(code);

  return (
    <main className="mx-auto min-h-dvh max-w-xl px-4 py-8">
      <p className="mb-6 flex items-center gap-2 text-sm font-semibold text-primary">
        <Sprout className="size-5" aria-hidden /> SFMTP traceability
      </p>
      {!data ? <Missing status={status} code={code} /> : <Trace data={data} />}
      <footer className="mt-10 border-t border-border pt-4 text-xs text-muted">
        Details approved by the producer and signed by SFMTP. No information about you is stored when you scan.
      </footer>
    </main>
  );
}

function Missing({ status, code }: { status: number; code: string }) {
  const [title, body] =
    status === 404
      ? ["We could not find this code", `Check the code on the label (${code.toUpperCase()}). If it is correct, the product may not be registered.`]
      : status === 429
        ? ["Too many scans", "Please wait a minute and try again."]
        : ["Traceability is unavailable", "Please try again shortly."];
  return (
    <section className="rounded-xl border border-border bg-surface p-6">
      <h1 className="text-xl font-semibold">{title}</h1>
      <p className="mt-2 text-sm text-muted">{body}</p>
    </section>
  );
}

function Trace({ data }: { data: PublicTrace }) {
  const f = (data.fields ?? {}) as Fields;
  return (
    <article className="space-y-4">
      {data.notice ? (
        <section role="alert" className={`rounded-xl border p-5 ${data.notice.type === "recalled" ? "border-danger bg-danger/10 text-danger" : "border-warning bg-warning/10"}`}>
          <h2 className="flex items-center gap-2 text-lg font-semibold">
            <AlertTriangle className="size-5" aria-hidden /> {data.notice.title}
          </h2>
          <p className="mt-1 text-sm">{data.notice.message}</p>
        </section>
      ) : null}

      <section className="rounded-xl border border-border bg-surface p-6">
        <p className="text-xs uppercase tracking-wide text-muted">{f.product?.kind ?? "Product"}</p>
        <h1 className="text-2xl font-semibold">{f.product?.name ?? "Traceable product"}</h1>
        {f.farm || f.region ? (
          <p className="mt-2 flex items-center gap-1.5 text-sm">
            <MapPin className="size-4 text-primary" aria-hidden />
            {[f.farm, f.region?.district, f.region?.country].filter(Boolean).join(", ")}
          </p>
        ) : null}
        <p className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
          <span className="font-mono">Code {data.code}</span>
          {f.batch_code ? <span className="font-mono">Batch {f.batch_code}</span> : null}
          {data.status === "active" ? (
            <span className="inline-flex items-center gap-1 text-primary">
              <ShieldCheck className="size-3.5" aria-hidden /> Verified by the producer
            </span>
          ) : null}
        </p>
      </section>

      {f.crop?.length || f.origin?.length || f.dates ? (
        <Card icon={<Leaf className="size-4" aria-hidden />} title="Grown">
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            {f.crop?.length ? <Row label="Crop" value={f.crop.join(", ")} /> : null}
            {f.origin?.length ? <Row label="Field" value={f.origin.map((p) => `Plot ${p}`).join(", ")} /> : null}
            {f.dates?.planted ? <Row label="Planted" value={formatDate(f.dates.planted)} /> : null}
            {f.dates?.harvested ? <Row label="Harvested" value={formatDate(f.dates.harvested)} /> : null}
            {f.dates?.processed ? <Row label="Processed" value={formatDate(f.dates.processed)} /> : null}
            {f.dates?.packed ? <Row label="Packed" value={formatDate(f.dates.packed)} /> : null}
          </dl>
        </Card>
      ) : null}

      {f.seed_source?.length ? (
        <Card icon={<Sprout className="size-4" aria-hidden />} title="Seed">
          <ul className="space-y-1 text-sm">
            {f.seed_source.map((s, i) => (
              <li key={i}>
                {s.name}
                {s.lot_number ? <span className="text-muted"> · lot {s.lot_number}</span> : null}
                {s.supplier ? <span className="text-muted"> · {s.supplier}</span> : null}
              </li>
            ))}
          </ul>
        </Card>
      ) : null}

      {f.inputs?.length ? (
        <Card icon={<CalendarDays className="size-4" aria-hidden />} title="Treatments and inputs">
          <ul className="space-y-1 text-sm">
            {f.inputs.map((x, i) => (
              <li key={i} className="flex justify-between gap-3">
                <span>
                  {x.product ?? x.type}
                  {x.withholding_days ? <span className="text-muted"> · {x.withholding_days}-day withholding respected</span> : null}
                </span>
                <span className="shrink-0 text-muted">{formatDate(x.date)}</span>
              </li>
            ))}
          </ul>
        </Card>
      ) : null}

      {f.processing?.length ? (
        <Card icon={<Factory className="size-4" aria-hidden />} title="Processing and packing">
          <ul className="space-y-1 text-sm">
            {f.processing.map((p, i) => (
              <li key={i} className="flex justify-between gap-3">
                <span>
                  {p.step}
                  {p.method ? <span className="text-muted"> · {p.method}</span> : null}
                  {p.packages ? <span className="text-muted"> · {p.packages} × {p.package_size ?? "pack"}</span> : null}
                </span>
                <span className="shrink-0 text-muted">{formatDate(p.date)}</span>
              </li>
            ))}
          </ul>
        </Card>
      ) : null}

      {f.certifications?.length ? (
        <Card icon={<BadgeCheck className="size-4" aria-hidden />} title="Certifications and inspections">
          <ul className="space-y-1 text-sm">
            {f.certifications.map((c, i) => (
              <li key={i} className="flex justify-between gap-3">
                <span>{c.note ?? c.type}</span>
                <span className="shrink-0 text-muted">{formatDate(c.date)}</span>
              </li>
            ))}
          </ul>
        </Card>
      ) : null}

      {f.journey?.length ? (
        <Card icon={<Route className="size-4" aria-hidden />} title="Its journey">
          <ol className="relative space-y-3 border-l border-border pl-4 text-sm">
            {f.journey.map((s, i) => (
              <li key={i} className="relative">
                <span className="absolute -left-[1.3rem] top-1.5 size-2 rounded-full bg-primary" aria-hidden />
                {s.kind} <span className="font-mono text-xs text-muted">{s.batch_code}</span>
                {s.date ? <span className="text-muted"> · {formatDate(s.date)}</span> : null}
              </li>
            ))}
          </ol>
        </Card>
      ) : null}
    </article>
  );
}

function Card({ icon, title, children }: { icon: React.ReactNode; title: string; children: React.ReactNode }) {
  return (
    <section className="rounded-xl border border-border bg-surface p-5">
      <h2 className="mb-2 flex items-center gap-2 text-sm font-semibold">
        <span className="text-primary">{icon}</span> {title}
      </h2>
      {children}
    </section>
  );
}

function Row({ label, value }: { label: string; value: string | null }) {
  return (
    <>
      <dt className="text-muted">{label}</dt>
      <dd>{value}</dd>
    </>
  );
}
