"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";

import { AppShell } from "@/components/shell/app-shell";
import { useWorkspaces } from "@/lib/api/hooks";

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { data } = useWorkspaces();

  useEffect(() => {
    if (!data) return;
    if (data.mfaRequired && !data.mfaEnabled) router.replace("/mfa/setup");
    else if (!data.workspaces.some((w) => w.type === "platform")) router.replace("/");
  }, [data, router]);

  return (
    <AppShell workspaceId="platform" nav={[{ key: "dashboard", label: "Dashboard", href: "/admin", icon: "dashboard" }]}>
      {children}
    </AppShell>
  );
}
