import type { components } from "@/lib/api/schema";

export type Animal = components["schemas"]["Animal"];
export type AnimalGroup = components["schemas"]["AnimalGroup"];
export type AnimalRecord = components["schemas"]["AnimalRecord"];
export type Breeding = components["schemas"]["Breeding"];
export type SaleRequest = components["schemas"]["AnimalSaleRequest"];

export const HEALTH_KINDS = ["treatment", "vaccination", "deworming", "checkup", "injury", "other"] as const;
export const PRODUCTS = ["milk", "eggs", "wool", "other"] as const;
export const GROUP_PURPOSES = ["dairy", "beef", "meat", "layers", "broilers", "breeding", "mixed", "other"] as const;

export const STATUS_TONE: Record<string, "primary" | "warning" | "neutral" | "danger"> = {
  active: "primary",
  sold: "neutral",
  dead: "danger",
  culled: "warning",
  transferred: "neutral",
};

export const SALE_TONE: Record<string, "primary" | "warning" | "neutral" | "danger"> = {
  requested: "warning",
  approved: "primary",
  rejected: "danger",
  completed: "neutral",
  cancelled: "neutral",
};

/** "3 y 2 m", "7 m", "12 d" from a birth date. */
export function formatAge(birthDate: string | null | undefined, today: Date = new Date()): string {
  if (!birthDate) return "—";
  const [y, m, d] = birthDate.split("-").map(Number);
  const born = new Date(y, m - 1, d);
  let months = (today.getFullYear() - born.getFullYear()) * 12 + (today.getMonth() - born.getMonth());
  if (today.getDate() < born.getDate()) months -= 1;
  if (months < 1) return `${Math.max(0, Math.round((today.getTime() - born.getTime()) / 86_400_000))} d`;
  const years = Math.floor(months / 12);
  const rest = months % 12;
  return years > 0 ? `${years} y${rest ? ` ${rest} m` : ""}` : `${rest} m`;
}

/** Short summary of a record for the timeline. */
export function describeRecord(kind: string, r: Record<string, unknown>): { title: string; detail: string } {
  const n = (v: unknown) => (v === null || v === undefined ? "" : String(v));
  switch (kind) {
    case "health": {
      const w = [r.meat_withdrawal_days ? `meat ${n(r.meat_withdrawal_days)} d` : "", r.milk_withdrawal_days ? `milk ${n(r.milk_withdrawal_days)} d` : ""].filter(Boolean).join(", ");
      return {
        title: `${capitalize(n(r.kind))}${r.product_name ? `: ${n(r.product_name)}` : ""}`,
        detail: [r.diagnosis, r.dose ? `${n(r.dose)} ${n(r.dose_unit)}` : "", w ? `withdrawal ${w}` : "", r.next_due_on ? `next due ${n(r.next_due_on)}` : "", r.given_by].filter(Boolean).join(" · "),
      };
    }
    case "feeding":
      return { title: `Fed ${n(r.feed_name)}`, detail: `${n(r.quantity)} ${n(r.unit)}` };
    case "weight":
      return { title: `Weighed ${n(r.weight_kg)} kg`, detail: n(r.method) };
    case "production":
      return {
        title: `${capitalize(n(r.product))}: ${n(r.quantity)} ${n(r.unit)}${r.session ? ` (${n(r.session)})` : ""}`,
        detail: r.discarded ? "Discarded (withdrawal)" : "",
      };
    case "movement":
      return { title: "Moved", detail: n(r.reason) };
    case "breeding":
      return { title: `Served (${r.method === "ai" ? "AI" : "natural"}) — ${n(r.status).replace("_", " ")}`, detail: r.expected_due_on ? `due ${n(r.expected_due_on)}` : "" };
    case "sale":
      return { title: `Sale request ${n(r.code)} — ${n(r.status)}`, detail: n(r.reason) };
    default:
      return { title: capitalize(kind), detail: "" };
  }
}

function capitalize(s: string): string {
  return s ? s[0].toUpperCase() + s.slice(1) : s;
}
