"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, ArrowRightLeft, Baby, Heart, Milk, Scale, ShoppingCart, Stethoscope, Wheat } from "lucide-react";
import Link from "next/link";
import { useParams, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";

import {
  ExitDialog,
  FeedingDialog,
  HealthDialog,
  MoveDialog,
  ProductionDialog,
  SaleRequestDialog,
  ServeDialog,
  WeightDialog,
} from "@/components/livestock/dialogs";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ErrorNotice, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { relativeDays } from "@/lib/crops";
import { describeRecord, formatAge, STATUS_TONE } from "@/lib/livestock";
import { can } from "@/lib/permissions";
import { cn } from "@/lib/utils";

type Dialog = "health" | "weight" | "feeding" | "production" | "move" | "serve" | "sale" | "exit" | null;

const ICONS: Record<string, React.ComponentType<{ className?: string }>> = {
  health: Stethoscope,
  feeding: Wheat,
  weight: Scale,
  production: Milk,
  movement: ArrowRightLeft,
  breeding: Heart,
  sale: ShoppingCart,
};

const VOIDABLE: Record<string, "/farms/{farm}/animal-health/{health_record}/void" | "/farms/{farm}/animal-weights/{weight}/void" | "/farms/{farm}/animal-feedings/{feeding}/void" | "/farms/{farm}/animal-production/{production}/void"> = {
  health: "/farms/{farm}/animal-health/{health_record}/void",
  weight: "/farms/{farm}/animal-weights/{weight}/void",
  feeding: "/farms/{farm}/animal-feedings/{feeding}/void",
  production: "/farms/{farm}/animal-production/{production}/void",
};
const PARAM: Record<string, string> = { health: "health_record", weight: "weight", feeding: "feeding", production: "production" };

export default function AnimalPage() {
  return (
    <Suspense>
      <AnimalProfile />
    </Suspense>
  );
}

function AnimalProfile() {
  const { farmId, animalId } = useParams<{ farmId: string; animalId: string }>();
  const search = useSearchParams();
  const queryClient = useQueryClient();
  const { workspace } = useFarmWorkspace(farmId);
  const writable = workspace?.type === "farm";
  const perms = workspace?.permissions;
  const canManage = writable && can(perms, "livestock.animals.manage");
  const canRecord = writable && perms?.["livestock.records.record"] === "all";
  const canRequestSale = writable && can(perms, "livestock.sales.request");
  const seesMoney = can(perms, "finance.values.view");

  const initial = search.get("action") as Dialog;
  const [dialog, setDialog] = useState<Dialog>(initial === "health" || initial === "weight" || initial === "sale" ? initial : null);
  const [error, setError] = useState<ApiError | null>(null);

  const path = { params: { path: { farm: farmId, animal: animalId } } };
  const animal = useQuery({ queryKey: ["animal", animalId], queryFn: async () => (await api.GET("/farms/{farm}/animals/{animal}", path)).data!.data! });
  const timeline = useQuery({ queryKey: ["animal-timeline", animalId], queryFn: async () => (await api.GET("/farms/{farm}/animals/{animal}/timeline", path)).data!.data! });

  const refresh = async () => {
    setDialog(null);
    await queryClient.invalidateQueries({ queryKey: ["animal", animalId] });
    await queryClient.invalidateQueries({ queryKey: ["animal-timeline", animalId] });
    await queryClient.invalidateQueries({ queryKey: ["animals", farmId] });
  };

  async function voidRecord(kind: string, id: string) {
    const reason = window.prompt("Why is this record wrong? It stays in the history, marked voided.");
    if (!reason) return;
    setError(null);
    try {
      await api.POST(VOIDABLE[kind], { params: { path: { farm: farmId, [PARAM[kind]]: id } as never }, body: { reason } });
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    }
  }

  if (animal.isLoading) return <Skeleton className="h-96 w-full" />;
  if (animal.error) return <ErrorNotice error={animal.error} />;
  const a = animal.data!;
  const active = a.status === "active";

  return (
    <>
      <nav className="mb-2 text-sm text-muted">
        <Link href={`/farms/${farmId}/livestock`} className="hover:underline">
          Livestock
        </Link>{" "}
        / {a.animal_code}
      </nav>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="flex flex-wrap items-center gap-3 text-2xl font-semibold tracking-tight">
            {a.animal_code}
            {a.name ? <span className="font-normal">“{a.name}”</span> : null}
            <Badge tone={STATUS_TONE[a.status ?? ""] ?? "neutral"}>{a.status}</Badge>
          </h1>
          <p className="mt-1 text-sm capitalize text-muted">
            {a.species?.name} · {a.breed?.name ?? a.breed_note ?? "breed not recorded"} · {a.sex} · {formatAge(a.birth_date)}
            {a.birth_date_estimated ? " (est.)" : ""}
            {a.tag_number ? ` · tag ${a.tag_number}` : ""}
          </p>
        </div>
        {active ? (
          <div className="flex flex-wrap gap-2">
            {canRecord ? (
              <>
                <Button size="sm" onClick={() => setDialog("health")}>
                  Treatment / vaccination
                </Button>
                <Button size="sm" variant="secondary" onClick={() => setDialog("weight")}>
                  Weight
                </Button>
                {a.sex === "female" ? (
                  <Button size="sm" variant="secondary" onClick={() => setDialog("production")}>
                    Milk / eggs
                  </Button>
                ) : null}
                <Button size="sm" variant="ghost" onClick={() => setDialog("feeding")}>
                  Feeding
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setDialog("move")}>
                  Move
                </Button>
                {a.sex === "female" && !a.pregnancy ? (
                  <Button size="sm" variant="ghost" onClick={() => setDialog("serve")}>
                    Service
                  </Button>
                ) : null}
              </>
            ) : null}
            {canRequestSale ? (
              <Button size="sm" variant="ghost" onClick={() => setDialog("sale")}>
                Request sale
              </Button>
            ) : null}
            {canManage ? (
              <Button size="sm" variant="ghost" className="text-danger" onClick={() => setDialog("exit")}>
                Died / culled
              </Button>
            ) : null}
          </div>
        ) : null}
      </div>

      {error ? (
        <div className="mb-4">
          <ErrorNotice error={error} />
        </div>
      ) : null}
      {a.milk_withdrawal_until || a.meat_withdrawal_until ? (
        <div className="mb-4 flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 px-4 py-3 text-sm" role="status">
          <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" />
          <div>
            {a.milk_withdrawal_until ? (
              <p>
                Milk and eggs must be discarded until <strong>{a.milk_withdrawal_until}</strong> ({relativeDays(a.milk_withdrawal_until)}).
              </p>
            ) : null}
            {a.meat_withdrawal_until ? (
              <p>
                Not for slaughter or sale for meat until <strong>{a.meat_withdrawal_until}</strong> ({relativeDays(a.meat_withdrawal_until)}).
              </p>
            ) : null}
          </div>
        </div>
      ) : null}
      {a.pregnancy ? (
        <div className="mb-4 flex items-center gap-2 rounded-lg border border-primary/30 bg-primary-soft/40 px-4 py-3 text-sm" role="status">
          <Baby className="size-4 text-primary" />
          Pregnant, due {a.pregnancy.expected_due_on} ({relativeDays(a.pregnancy.expected_due_on)}).
        </div>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
        <section aria-label="History">
          <h2 className="mb-3 text-lg font-semibold">History</h2>
          {timeline.isLoading ? <Skeleton className="h-40 w-full" /> : null}
          {timeline.data?.length === 0 ? <p className="text-sm text-muted">Nothing recorded yet.</p> : null}
          <ol className="space-y-2">
            {timeline.data?.map((e) => {
              const data = (e.data ?? {}) as Record<string, unknown>;
              const { title, detail } = describeRecord(e.kind ?? "", data);
              const Icon = ICONS[e.kind ?? ""] ?? Heart;
              const voided = data.voided as { reason?: string } | null;
              const byGroup = data.group && !data.animal;
              return (
                <li key={`${e.kind}:${String(data.id)}`}>
                  <Card className={cn(voided && "opacity-60")}>
                    <CardContent className="flex gap-3 py-3 text-sm">
                      <Icon className="mt-0.5 size-5 shrink-0 text-primary" />
                      <div className="min-w-0 flex-1">
                        <p className="flex flex-wrap items-center gap-2">
                          <span className={cn("font-medium", voided && "line-through")}>{title}</span>
                          {byGroup ? <Badge tone="neutral">whole group</Badge> : null}
                          {voided ? <Badge tone="danger">voided</Badge> : null}
                          <span className="text-xs text-muted">{e.at?.slice(0, 10)}</span>
                        </p>
                        {detail ? <p className="mt-0.5 text-muted">{detail}</p> : null}
                        {voided ? <p className="mt-0.5 text-xs text-danger">Voided: {voided.reason}</p> : null}
                        {data.notes ? <p className="mt-0.5 text-xs text-muted">{String(data.notes)}</p> : null}
                      </div>
                      {canRecord && !voided && VOIDABLE[e.kind ?? ""] && !byGroup ? (
                        <Button size="sm" variant="ghost" className="self-start text-xs" onClick={() => voidRecord(e.kind!, String(data.id))}>
                          Void
                        </Button>
                      ) : null}
                    </CardContent>
                  </Card>
                </li>
              );
            })}
          </ol>
        </section>

        <aside className="space-y-4">
          <Card>
            <CardContent className="space-y-2 text-sm">
              <h2 className="font-semibold">Details</h2>
              <dl className="grid grid-cols-2 gap-x-3 gap-y-2">
                <Fact k="Weight" v={a.last_weight_kg != null ? `${a.last_weight_kg} kg (${a.last_weighed_on})` : "—"} />
                <Fact k="Born" v={a.birth_date ?? "—"} />
                <Fact k="Group" v={a.group?.name ?? "—"} />
                <Fact k="Location" v={a.location?.name ?? "—"} />
                <Fact k="Came from" v={a.origin === "born" ? "Born here" : `${a.origin} ${a.acquired_on ?? ""}`} />
                {a.exited_on ? <Fact k="Left" v={`${a.exited_on}: ${a.exit_reason ?? ""}`} /> : null}
              </dl>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="space-y-2 text-sm">
              <h2 className="font-semibold">Parents</h2>
              <p>
                <span className="text-muted">Dam:</span>{" "}
                {a.dam ? (
                  <Link className="text-primary hover:underline" href={`/farms/${farmId}/livestock/animals/${a.dam.id}`}>
                    {a.dam.animal_code} {a.dam.name}
                  </Link>
                ) : (
                  "—"
                )}
              </p>
              <p>
                <span className="text-muted">Sire:</span>{" "}
                {a.sire ? (
                  <Link className="text-primary hover:underline" href={`/farms/${farmId}/livestock/animals/${a.sire.id}`}>
                    {a.sire.animal_code} {a.sire.name}
                  </Link>
                ) : (
                  "—"
                )}
              </p>
              {a.parentage_note ? <p className="text-xs text-muted">{a.parentage_note}</p> : null}
            </CardContent>
          </Card>
          {a.batch ? (
            <Card>
              <CardContent className="space-y-1 text-sm">
                <h2 className="font-semibold">Traceability</h2>
                <Link className="font-mono text-primary hover:underline" href={`/farms/${farmId}/traceability/batches/${a.batch.id}`}>
                  {a.batch.batch_code}
                </Link>
                <p className="text-xs text-muted">Its public-safe identifier. Treatments, moves, services, births and exits are events on it.</p>
              </CardContent>
            </Card>
          ) : null}
        </aside>
      </div>

      {dialog === "health" ? <HealthDialog farmId={farmId} animal={a} onClose={() => setDialog(null)} onDone={refresh} /> : null}
      {dialog === "weight" ? <WeightDialog farmId={farmId} animal={a} onClose={() => setDialog(null)} onDone={refresh} /> : null}
      {dialog === "feeding" ? <FeedingDialog farmId={farmId} animal={a} onClose={() => setDialog(null)} onDone={refresh} /> : null}
      {dialog === "production" ? <ProductionDialog farmId={farmId} animal={a} onClose={() => setDialog(null)} onDone={refresh} /> : null}
      {dialog === "move" ? <MoveDialog farmId={farmId} animal={a} onClose={() => setDialog(null)} onDone={refresh} /> : null}
      {dialog === "serve" ? <ServeDialog farmId={farmId} animal={a} onClose={() => setDialog(null)} onDone={refresh} /> : null}
      {dialog === "sale" ? <SaleRequestDialog farmId={farmId} animal={a} seesMoney={seesMoney} onClose={() => setDialog(null)} onDone={refresh} /> : null}
      {dialog === "exit" ? <ExitDialog farmId={farmId} animal={a} onClose={() => setDialog(null)} onDone={refresh} /> : null}
    </>
  );
}

function Fact({ k, v }: { k: string; v: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-muted">{k}</dt>
      <dd className="capitalize">{v}</dd>
    </div>
  );
}
