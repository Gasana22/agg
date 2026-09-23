"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";

import { Logo } from "@/components/brand/logo";
import { ErrorNotice } from "@/components/ui/misc";
import { useWorkspaces } from "@/lib/api/hooks";
import { homePath } from "@/lib/permissions";

/** Sends the user to their highest-precedence workspace and dashboard. */
export default function Home() {
  const router = useRouter();
  const { data, error } = useWorkspaces();

  useEffect(() => {
    if (data) router.replace(homePath(data.workspaces, data));
  }, [data, router]);

  return (
    <main className="grid min-h-dvh place-items-center p-6">
      {error ? <ErrorNotice error={error} /> : <Logo className="animate-pulse" />}
    </main>
  );
}
