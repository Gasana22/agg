import { Badge } from "./badge";

type Tone = "neutral" | "primary" | "warning" | "danger";

const TONES: Record<string, Tone> = {
  // farms
  pending: "warning",
  active: "primary",
  suspended: "danger",
  closed: "neutral",
  // subscriptions
  trialing: "warning",
  grace: "warning",
  cancelled: "neutral",
  // tickets
  open: "primary",
  resolved: "neutral",
  // payments, backups, users
  succeeded: "primary",
  success: "primary",
  failed: "danger",
  disabled: "danger",
  ok: "primary",
  down: "danger",
};

export function StatusBadge({ status }: { status: string | null | undefined }) {
  if (!status) return null;
  return <Badge tone={TONES[status] ?? "neutral"}>{status.replaceAll("_", " ")}</Badge>;
}
