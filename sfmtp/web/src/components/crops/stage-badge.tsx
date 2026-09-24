import { Badge } from "@/components/ui/badge";
import { STAGE_TONE } from "@/lib/crops";

export function StageBadge({ stage }: { stage?: string }) {
  return <Badge tone={STAGE_TONE[stage ?? ""] ?? "neutral"}>{stage ?? "—"}</Badge>;
}
