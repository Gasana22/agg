"use client";

import { useState } from "react";

import { FormDialog, num, text } from "@/components/forms/form-dialog";
import { UnitSelect } from "@/components/forms/unit-select";
import { Checkbox, FieldError, Input, Label, Select, Textarea } from "@/components/ui/input";
import { api } from "@/lib/api/client";
import { useCatalog } from "@/lib/api/catalog";
import { ApiError } from "@/lib/api/errors";
import { humanize } from "@/lib/format";
import { type Animal, type AnimalGroup, type Breeding, GROUP_PURPOSES, HEALTH_KINDS, PRODUCTS, type SaleRequest } from "@/lib/livestock";

import { useActiveAnimals, useGroups, useLocations } from "./queries";
import { subjectOf, SubjectPicker } from "./subject-picker";

type Base = { farmId: string; onClose: () => void; onDone: () => void };
const today = () => new Date().toISOString().slice(0, 10);

export function RegisterAnimalDialog({ farmId, onClose, onDone }: Omit<Base, "onDone"> & { onDone: (animal?: Animal) => void }) {
  const species = useCatalog("animal-species");
  const [speciesId, setSpeciesId] = useState("");
  const breeds = useCatalog("animal-breeds", speciesId || undefined);
  const groups = useGroups(farmId);
  const animals = useActiveAnimals(farmId);
  const locations = useLocations(farmId);
  const [origin, setOrigin] = useState("purchased");
  const sameSpecies = (animals.data ?? []).filter((a) => a.species?.id === speciesId);

  return (
    <FormDialog
      title="Register an animal"
      description="It gets a farm code and a traceability record from today."
      submitLabel="Register"
      onClose={onClose}
      onSubmit={async (f) => {
        const res = await api.POST("/farms/{farm}/animals", {
          params: { path: { farm: farmId } },
          body: {
            species_id: speciesId,
            breed_id: text(f, "breed_id"),
            breed_note: text(f, "breed_note"),
            sex: String(f.get("sex")) as "female" | "male",
            name: text(f, "name"),
            tag_number: text(f, "tag_number"),
            birth_date: text(f, "birth_date"),
            birth_date_estimated: f.get("birth_date_estimated") === "on",
            origin: origin as "born" | "purchased" | "gifted" | "other",
            acquired_on: origin === "born" ? null : text(f, "acquired_on"),
            dam_id: text(f, "dam_id"),
            sire_id: text(f, "sire_id"),
            parentage_note: text(f, "parentage_note"),
            group_id: text(f, "group_id"),
            location_id: text(f, "location_id"),
          },
        });
        onDone(res.data!.data!);
      }}
    >
      {(error) => (
        <>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label htmlFor="species">Species</Label>
              <Select id="species" required value={speciesId} onChange={(e) => setSpeciesId(e.target.value)}>
                <option value="">Choose…</option>
                {species.data?.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </Select>
              <FieldError>{error?.fieldError("species_id")}</FieldError>
            </div>
            <div>
              <Label htmlFor="breed_id">Breed</Label>
              <Select id="breed_id" name="breed_id" defaultValue="" disabled={!speciesId}>
                <option value="">Cross / not listed</option>
                {breeds.data?.map((b) => (
                  <option key={b.id} value={b.id}>
                    {b.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="sex">Sex</Label>
              <Select id="sex" name="sex" defaultValue="female">
                <option value="female">Female</option>
                <option value="male">Male</option>
              </Select>
            </div>
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label htmlFor="name">Name</Label>
              <Input id="name" name="name" maxLength={80} />
            </div>
            <div>
              <Label htmlFor="tag_number">Ear tag</Label>
              <Input id="tag_number" name="tag_number" maxLength={40} aria-invalid={!!error?.fieldError("tag_number")} />
              <FieldError>{error?.fieldError("tag_number")}</FieldError>
            </div>
            <div>
              <Label htmlFor="breed_note">Breed note</Label>
              <Input id="breed_note" name="breed_note" maxLength={120} placeholder="e.g. Friesian × Ankole" />
            </div>
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label htmlFor="origin">Came from</Label>
              <Select id="origin" value={origin} onChange={(e) => setOrigin(e.target.value)}>
                <option value="purchased">Bought</option>
                <option value="born">Born here</option>
                <option value="gifted">Gift</option>
                <option value="other">Other</option>
              </Select>
            </div>
            <div>
              <Label htmlFor="birth_date">Birth date</Label>
              <Input id="birth_date" name="birth_date" type="date" max={today()} required={origin === "born"} />
            </div>
            {origin !== "born" ? (
              <div>
                <Label htmlFor="acquired_on">Arrived on</Label>
                <Input id="acquired_on" name="acquired_on" type="date" max={today()} defaultValue={today()} />
              </div>
            ) : null}
          </div>
          <Checkbox name="birth_date_estimated" label="Birth date is an estimate" />
          {speciesId ? (
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label htmlFor="dam_id">Dam (mother)</Label>
                <Select id="dam_id" name="dam_id" defaultValue="">
                  <option value="">Not on this farm</option>
                  {sameSpecies.filter((a) => a.sex === "female").map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.label}
                    </option>
                  ))}
                </Select>
                <FieldError>{error?.fieldError("dam_id")}</FieldError>
              </div>
              <div>
                <Label htmlFor="sire_id">Sire (father)</Label>
                <Select id="sire_id" name="sire_id" defaultValue="">
                  <option value="">Not on this farm</option>
                  {sameSpecies.filter((a) => a.sex === "male").map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.label}
                    </option>
                  ))}
                </Select>
              </div>
            </div>
          ) : null}
          <div>
            <Label htmlFor="parentage_note">Parentage (off-farm)</Label>
            <Input id="parentage_note" name="parentage_note" maxLength={200} placeholder="e.g. from Mbarara breeder, sire unknown" />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="group_id">Group</Label>
              <Select id="group_id" name="group_id" defaultValue="">
                <option value="">None</option>
                {groups.data?.filter((g) => !speciesId || g.species?.id === speciesId).map((g) => (
                  <option key={g.id} value={g.id}>
                    {g.name}
                  </option>
                ))}
              </Select>
              <FieldError>{error?.fieldError("group_id")}</FieldError>
            </div>
            <div>
              <Label htmlFor="location_id">Location</Label>
              <Select id="location_id" name="location_id" defaultValue="">
                <option value="">The group&apos;s location</option>
                {locations.data?.map((l) => (
                  <option key={l.id} value={l.id}>
                    {l.code} · {l.name}
                  </option>
                ))}
              </Select>
            </div>
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function GroupDialog({ farmId, group, onClose, onDone }: Base & { group?: AnimalGroup }) {
  const species = useCatalog("animal-species");
  const locations = useLocations(farmId);
  return (
    <FormDialog
      title={group ? `Edit ${group.name}` : "New group"}
      description="A herd, flock or pen. Flocks can be kept by head count alone."
      submitLabel="Save"
      onClose={onClose}
      onSubmit={async (f) => {
        const body = {
          name: String(f.get("name")),
          purpose: String(f.get("purpose")) as (typeof GROUP_PURPOSES)[number],
          location_id: text(f, "location_id"),
          flock_size: num(f, "flock_size"),
          notes: text(f, "notes"),
        };
        if (group) await api.PATCH("/farms/{farm}/animal-groups/{group}", { params: { path: { farm: farmId, group: group.id! } }, body: { ...body, version: group.version } });
        else await api.POST("/farms/{farm}/animal-groups", { params: { path: { farm: farmId } }, body: { ...body, species_id: String(f.get("species_id")) } });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="name">Name</Label>
              <Input id="name" name="name" required maxLength={120} defaultValue={group?.name} placeholder="Dairy herd" />
              <FieldError>{error?.fieldError("name")}</FieldError>
            </div>
            {group ? null : (
              <div>
                <Label htmlFor="species_id">Species</Label>
                <Select id="species_id" name="species_id" required defaultValue="">
                  <option value="">Choose…</option>
                  {species.data?.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
                </Select>
              </div>
            )}
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label htmlFor="purpose">Purpose</Label>
              <Select id="purpose" name="purpose" defaultValue={group?.purpose ?? "mixed"}>
                {GROUP_PURPOSES.map((p) => (
                  <option key={p} value={p}>
                    {humanize(p)}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="location_id">Location</Label>
              <Select id="location_id" name="location_id" defaultValue={group?.location?.id ?? ""}>
                <option value="">—</option>
                {locations.data?.map((l) => (
                  <option key={l.id} value={l.id}>
                    {l.code} · {l.name}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="flock_size">Flock size</Label>
              <Input id="flock_size" name="flock_size" type="number" min="0" step="1" defaultValue={group?.flock_size ?? ""} placeholder="Head count" />
            </div>
          </div>
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" rows={2} defaultValue={group?.notes ?? ""} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function HealthDialog({ farmId, animal, onClose, onDone }: Base & { animal?: Animal }) {
  const [kind, setKind] = useState("treatment");
  return (
    <FormDialog
      title="Treatment, vaccination or check"
      description="Withdrawal days come from the product label; they block milk and sales until they pass."
      submitLabel="Record"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/animal-health", {
          params: { path: { farm: farmId } },
          body: {
            ...subjectOf(f),
            kind: kind as (typeof HEALTH_KINDS)[number],
            given_on: String(f.get("given_on")),
            diagnosis: text(f, "diagnosis"),
            product_name: text(f, "product_name"),
            dose: num(f, "dose"),
            dose_unit: num(f, "dose") !== null ? text(f, "dose_unit") : null,
            meat_withdrawal_days: num(f, "meat_withdrawal_days"),
            milk_withdrawal_days: num(f, "milk_withdrawal_days"),
            next_due_on: text(f, "next_due_on"),
            given_by: text(f, "given_by"),
            notes: text(f, "notes"),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <SubjectPicker farmId={farmId} animal={animal} error={error?.fieldError("animal_id")} />
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="kind">What</Label>
              <Select id="kind" value={kind} onChange={(e) => setKind(e.target.value)}>
                {HEALTH_KINDS.map((k) => (
                  <option key={k} value={k}>
                    {humanize(k)}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="given_on">Date</Label>
              <Input id="given_on" name="given_on" type="date" max={today()} defaultValue={today()} required />
            </div>
          </div>
          {kind === "treatment" || kind === "injury" || kind === "checkup" ? (
            <div>
              <Label htmlFor="diagnosis">Diagnosis / finding</Label>
              <Input id="diagnosis" name="diagnosis" maxLength={200} placeholder="e.g. Mastitis, rear left quarter" />
            </div>
          ) : null}
          <div className="grid grid-cols-[1fr_6rem_7rem] gap-3">
            <div>
              <Label htmlFor="product_name">Product</Label>
              <Input id="product_name" name="product_name" maxLength={150} required={kind === "vaccination" || kind === "deworming"} aria-invalid={!!error?.fieldError("product_name")} />
              <FieldError>{error?.fieldError("product_name")}</FieldError>
            </div>
            <div>
              <Label htmlFor="dose">Dose</Label>
              <Input id="dose" name="dose" type="number" min="0" step="0.001" />
            </div>
            <div>
              <Label htmlFor="dose_unit">Unit</Label>
              <UnitSelect id="dose_unit" name="dose_unit" defaultValue="ml" dimensions={["volume", "mass", "count"]} />
            </div>
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label htmlFor="meat_withdrawal_days">Meat withdrawal (days)</Label>
              <Input id="meat_withdrawal_days" name="meat_withdrawal_days" type="number" min="0" step="1" />
            </div>
            <div>
              <Label htmlFor="milk_withdrawal_days">Milk / egg withdrawal (days)</Label>
              <Input id="milk_withdrawal_days" name="milk_withdrawal_days" type="number" min="0" step="1" />
            </div>
            <div>
              <Label htmlFor="next_due_on">Next dose due</Label>
              <Input id="next_due_on" name="next_due_on" type="date" min={today()} />
              <FieldError>{error?.fieldError("next_due_on")}</FieldError>
            </div>
          </div>
          <div>
            <Label htmlFor="given_by">Given by</Label>
            <Input id="given_by" name="given_by" maxLength={120} placeholder="Vet or staff name" />
          </div>
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" rows={2} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function WeightDialog({ farmId, animal, onClose, onDone }: Base & { animal?: Animal }) {
  return (
    <FormDialog
      title="Record weight"
      submitLabel="Record"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/animal-weights", {
          params: { path: { farm: farmId } },
          body: { animal_id: String(f.get("animal_id")), weighed_on: String(f.get("weighed_on")), weight_kg: Number(f.get("weight_kg")), method: String(f.get("method")) as "scale", notes: text(f, "notes") },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <SubjectPicker farmId={farmId} animal={animal} allowGroup={false} error={error?.fieldError("animal_id")} />
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label htmlFor="weight_kg">Weight (kg)</Label>
              <Input id="weight_kg" name="weight_kg" type="number" min="0" step="0.1" required aria-invalid={!!error?.fieldError("weight_kg")} />
              <FieldError>{error?.fieldError("weight_kg")}</FieldError>
            </div>
            <div>
              <Label htmlFor="weighed_on">Date</Label>
              <Input id="weighed_on" name="weighed_on" type="date" max={today()} defaultValue={today()} required />
            </div>
            <div>
              <Label htmlFor="method">How</Label>
              <Select id="method" name="method" defaultValue="scale">
                <option value="scale">Scale</option>
                <option value="tape">Weigh band</option>
                <option value="estimate">Estimate</option>
              </Select>
            </div>
          </div>
          <div>
            <Label htmlFor="notes">Notes</Label>
            <Textarea id="notes" name="notes" rows={2} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function FeedingDialog({ farmId, animal, onClose, onDone }: Base & { animal?: Animal }) {
  return (
    <FormDialog
      title="Record feeding"
      submitLabel="Record"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/animal-feedings", {
          params: { path: { farm: farmId } },
          body: { ...subjectOf(f), fed_on: String(f.get("fed_on")), feed_name: String(f.get("feed_name")), quantity: Number(f.get("quantity")), unit: String(f.get("unit")), notes: text(f, "notes") },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <SubjectPicker farmId={farmId} animal={animal} error={error?.fieldError("animal_id")} />
          <div className="grid grid-cols-[1fr_6rem_7rem] gap-3">
            <div>
              <Label htmlFor="feed_name">Feed</Label>
              <Input id="feed_name" name="feed_name" required maxLength={150} placeholder="Dairy meal" />
            </div>
            <div>
              <Label htmlFor="quantity">Qty</Label>
              <Input id="quantity" name="quantity" type="number" min="0" step="0.1" required />
            </div>
            <div>
              <Label htmlFor="unit">Unit</Label>
              <UnitSelect id="unit" name="unit" defaultValue="kg" dimensions={["mass", "volume"]} />
            </div>
          </div>
          <div>
            <Label htmlFor="fed_on">Date</Label>
            <Input id="fed_on" name="fed_on" type="date" max={today()} defaultValue={today()} required />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function ProductionDialog({ farmId, animal, onClose, onDone }: Base & { animal?: Animal }) {
  const [blocked, setBlocked] = useState<string | null>(null);
  return (
    <FormDialog
      title="Record production"
      description="Kept milk and eggs go into today's lot in traceability."
      submitLabel="Record"
      onClose={onClose}
      onSubmit={async (f) => {
        try {
          await api.POST("/farms/{farm}/animal-production", {
            params: { path: { farm: farmId } },
            body: {
              ...subjectOf(f),
              product: String(f.get("product")) as (typeof PRODUCTS)[number],
              produced_on: String(f.get("produced_on")),
              session: (text(f, "session") as "am" | "pm" | "day" | null) ?? null,
              quantity: Number(f.get("quantity")),
              unit: String(f.get("unit")),
              discarded: f.get("discarded") === "on",
            },
          });
          onDone();
        } catch (err) {
          if (err instanceof ApiError && err.code === "withdrawal_period") setBlocked(err.problem.title);
          throw err;
        }
      }}
    >
      {(error) => (
        <>
          <SubjectPicker farmId={farmId} animal={animal} error={error?.fieldError("animal_id")} />
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="product">Product</Label>
              <Select id="product" name="product" defaultValue="milk">
                {PRODUCTS.map((p) => (
                  <option key={p} value={p}>
                    {humanize(p)}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="session">Session</Label>
              <Select id="session" name="session" defaultValue="am">
                <option value="am">Morning</option>
                <option value="pm">Evening</option>
                <option value="day">Whole day</option>
              </Select>
            </div>
          </div>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <Label htmlFor="quantity">Quantity</Label>
              <Input id="quantity" name="quantity" type="number" min="0" step="0.1" required />
            </div>
            <div>
              <Label htmlFor="unit">Unit</Label>
              <UnitSelect id="unit" name="unit" defaultValue="l" dimensions={["volume", "count", "mass"]} />
            </div>
            <div>
              <Label htmlFor="produced_on">Date</Label>
              <Input id="produced_on" name="produced_on" type="date" max={today()} defaultValue={today()} required />
            </div>
          </div>
          {blocked ? <p className="rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">{blocked}</p> : null}
          <Checkbox name="discarded" label="Discarded (not kept for sale or use)" />
        </>
      )}
    </FormDialog>
  );
}

export function MoveDialog({ farmId, animal, onClose, onDone }: Base & { animal?: Animal }) {
  const locations = useLocations(farmId);
  return (
    <FormDialog
      title="Move"
      submitLabel="Move"
      onClose={onClose}
      onSubmit={async (f) => {
        const subject = subjectOf(f);
        await api.POST("/farms/{farm}/animal-movements", {
          params: { path: { farm: farmId } },
          body: { ...(subject.animal_id ? { animal_ids: [subject.animal_id] } : { group_id: subject.group_id }), to_location_id: String(f.get("to_location_id")), reason: text(f, "reason") },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <SubjectPicker farmId={farmId} animal={animal} error={error?.fieldError("animal_ids")} />
          <div>
            <Label htmlFor="to_location_id">To</Label>
            <Select id="to_location_id" name="to_location_id" required defaultValue="">
              <option value="">Choose…</option>
              {locations.data?.map((l) => (
                <option key={l.id} value={l.id}>
                  {l.code} · {l.name} ({l.kind})
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label htmlFor="reason">Why</Label>
            <Input id="reason" name="reason" maxLength={200} placeholder="e.g. Grazing rotation" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function ServeDialog({ farmId, animal, onClose, onDone }: Base & { animal?: Animal }) {
  const animals = useActiveAnimals(farmId);
  const [method, setMethod] = useState("natural");
  const dam = animal ?? null;
  const sires = (animals.data ?? []).filter((a) => a.sex === "male" && (!dam || a.species?.id === dam.species?.id));
  return (
    <FormDialog
      title="Record a service"
      description="The expected due date follows the species' gestation length."
      submitLabel="Record"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/animal-breedings", {
          params: { path: { farm: farmId } },
          body: { dam_id: String(f.get("dam_id")), sire_id: text(f, "sire_id"), sire_note: text(f, "sire_note"), method: method as "natural" | "ai", served_on: String(f.get("served_on")) },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          {dam ? (
            <input type="hidden" name="dam_id" value={dam.id} />
          ) : (
            <div>
              <Label htmlFor="dam_id">Dam</Label>
              <Select id="dam_id" name="dam_id" required defaultValue="">
                <option value="">Choose…</option>
                {(animals.data ?? []).filter((a) => a.sex === "female").map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.label}
                  </option>
                ))}
              </Select>
              <FieldError>{error?.fieldError("dam_id")}</FieldError>
            </div>
          )}
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="method">Method</Label>
              <Select id="method" value={method} onChange={(e) => setMethod(e.target.value)}>
                <option value="natural">Natural</option>
                <option value="ai">Artificial insemination</option>
              </Select>
            </div>
            <div>
              <Label htmlFor="served_on">Served on</Label>
              <Input id="served_on" name="served_on" type="date" max={today()} defaultValue={today()} required />
            </div>
          </div>
          {method === "natural" ? (
            <div>
              <Label htmlFor="sire_id">Sire</Label>
              <Select id="sire_id" name="sire_id" defaultValue="">
                <option value="">Not on this farm</option>
                {sires.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.label}
                  </option>
                ))}
              </Select>
              <FieldError>{error?.fieldError("sire_id")}</FieldError>
            </div>
          ) : null}
          <div>
            <Label htmlFor="sire_note">{method === "ai" ? "Straw / bull" : "Sire (if off-farm)"}</Label>
            <Input id="sire_note" name="sire_note" maxLength={150} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function BirthDialog({ farmId, breeding, onClose, onDone }: Base & { breeding: Breeding }) {
  const [count, setCount] = useState(1);
  return (
    <FormDialog
      title={`Birth — ${breeding.dam?.animal_code}`}
      description="Each newborn is registered with its dam and sire."
      submitLabel="Record birth"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/animal-breedings/{breeding}/birth", {
          params: { path: { farm: farmId, breeding: breeding.id! } },
          body: {
            born_on: String(f.get("born_on")),
            offspring: Array.from({ length: count }, (_, i) => ({ sex: String(f.get(`sex${i}`)) as "female" | "male", name: text(f, `name${i}`), tag_number: text(f, `tag${i}`) })),
            note: text(f, "note"),
          },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="born_on">Born on</Label>
              <Input id="born_on" name="born_on" type="date" max={today()} defaultValue={today()} required />
            </div>
            <div>
              <Label htmlFor="count">Number born alive</Label>
              <Input id="count" type="number" min="1" max="20" value={count} onChange={(e) => setCount(Math.max(1, Math.min(20, Number(e.target.value) || 1)))} />
            </div>
          </div>
          {Array.from({ length: count }, (_, i) => (
            <div key={i} className="grid grid-cols-[7rem_1fr_1fr] gap-2">
              <Select aria-label={`Sex of newborn ${i + 1}`} name={`sex${i}`} defaultValue="female">
                <option value="female">Female</option>
                <option value="male">Male</option>
              </Select>
              <Input aria-label={`Name of newborn ${i + 1}`} name={`name${i}`} placeholder="Name" maxLength={80} />
              <Input aria-label={`Tag of newborn ${i + 1}`} name={`tag${i}`} placeholder="Ear tag" maxLength={40} />
            </div>
          ))}
          <FieldError>{error?.fieldError("offspring.0.tag_number")}</FieldError>
          <div>
            <Label htmlFor="note">Note</Label>
            <Textarea id="note" name="note" rows={2} placeholder="e.g. Easy calving" />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function ExitDialog({ farmId, animal, onClose, onDone }: Base & { animal: Animal }) {
  return (
    <FormDialog
      title={`${animal.label} leaves the herd`}
      description="Closes its traceability record. To sell, use a sale request instead."
      submitLabel="Record"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/animals/{animal}/exit", {
          params: { path: { farm: farmId, animal: animal.id! } },
          body: { status: String(f.get("status")) as "dead", date: String(f.get("date")), reason: String(f.get("reason")) },
        });
        onDone();
      }}
    >
      {() => (
        <>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="status">What happened</Label>
              <Select id="status" name="status" defaultValue="dead">
                <option value="dead">Died</option>
                <option value="culled">Culled / slaughtered for home use</option>
                <option value="transferred">Transferred to another farm</option>
              </Select>
            </div>
            <div>
              <Label htmlFor="date">Date</Label>
              <Input id="date" name="date" type="date" max={today()} defaultValue={today()} required />
            </div>
          </div>
          <div>
            <Label htmlFor="reason">Cause / details</Label>
            <Textarea id="reason" name="reason" rows={2} required maxLength={500} />
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function SaleRequestDialog({ farmId, animal, seesMoney, onClose, onDone }: Base & { animal?: Animal; seesMoney: boolean }) {
  return (
    <FormDialog
      title="Request a sale"
      description="The owner approves the sale before it happens."
      submitLabel="Send request"
      onClose={onClose}
      onSubmit={async (f) => {
        await api.POST("/farms/{farm}/animal-sales", {
          params: { path: { farm: farmId } },
          body: { animal_id: String(f.get("animal_id")), reason: text(f, "reason"), buyer: text(f, "buyer"), expected_price: seesMoney ? num(f, "expected_price") : undefined },
        });
        onDone();
      }}
    >
      {(error) => (
        <>
          <SubjectPicker farmId={farmId} animal={animal} allowGroup={false} error={error?.fieldError("animal_id")} />
          <div>
            <Label htmlFor="reason">Why sell</Label>
            <Input id="reason" name="reason" maxLength={500} placeholder="e.g. Finished weight reached" />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="buyer">Buyer (if known)</Label>
              <Input id="buyer" name="buyer" maxLength={150} />
            </div>
            {seesMoney ? (
              <div>
                <Label htmlFor="expected_price">Expected price</Label>
                <Input id="expected_price" name="expected_price" type="number" min="0" step="1000" />
              </div>
            ) : null}
          </div>
        </>
      )}
    </FormDialog>
  );
}

export function CompleteSaleDialog({ farmId, sale, seesMoney, onClose, onDone }: Base & { sale: SaleRequest; seesMoney: boolean }) {
  const [blocked, setBlocked] = useState<string | null>(null);
  return (
    <FormDialog
      title={`Record sale ${sale.code}`}
      description={sale.animal?.label}
      submitLabel="Record sale"
      onClose={onClose}
      onSubmit={async (f) => {
        try {
          await api.POST("/farms/{farm}/animal-sales/{sale}/complete", {
            params: { path: { farm: farmId, sale: sale.id! } },
            body: { sold_on: String(f.get("sold_on")), sale_price: seesMoney ? num(f, "sale_price") : null, buyer: text(f, "buyer"), withdrawal_override_reason: text(f, "withdrawal_override_reason") },
          });
          onDone();
        } catch (err) {
          if (err instanceof ApiError && err.code === "withdrawal_period") setBlocked(err.problem.title);
          throw err;
        }
      }}
    >
      {() => (
        <>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="sold_on">Sold on</Label>
              <Input id="sold_on" name="sold_on" type="date" max={today()} defaultValue={today()} required />
            </div>
            {seesMoney ? (
              <div>
                <Label htmlFor="sale_price">Price</Label>
                <Input id="sale_price" name="sale_price" type="number" min="0" step="1000" />
              </div>
            ) : null}
          </div>
          <div>
            <Label htmlFor="buyer">Buyer</Label>
            <Input id="buyer" name="buyer" maxLength={150} defaultValue={sale.buyer ?? ""} />
          </div>
          {blocked ? (
            <div className="rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
              {blocked}
              <Label htmlFor="withdrawal_override_reason" className="mt-2 block">
                Override reason
              </Label>
              <Textarea id="withdrawal_override_reason" name="withdrawal_override_reason" rows={2} maxLength={500} />
            </div>
          ) : null}
        </>
      )}
    </FormDialog>
  );
}
