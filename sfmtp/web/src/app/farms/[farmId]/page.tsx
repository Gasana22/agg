"use client";

import { useParams, useRouter } from "next/navigation";
import { useEffect } from "react";

import { EmptyState } from "@/components/ui/misc";
import { useFarmWorkspace } from "@/lib/api/hooks";

/** Opens the member's highest-precedence dashboard. */
export default function FarmHome() {
  const { farmId } = useParams<{ farmId: string }>();
  const router = useRouter();
  const { workspace } = useFarmWorkspace(farmId);
  const first = workspace?.dashboards[0];

  useEffect(() => {
    if (first) router.replace(`/farms/${farmId}/dashboard/${first}`);
  }, [first, farmId, router]);

  return workspace && !first ? <EmptyState title="No dashboard for your role yet" /> : null;
}
