"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";

import { AppShell } from "@/components/shell/app-shell";
import { usePlatformWorkspace } from "@/lib/api/hooks";
import { visibleAdminNav } from "@/lib/permissions";

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { data, workspace } = usePlatformWorkspace();

  useEffect(() => {
    if (!data) return;
    if (data.mfaRequired && !data.mfaEnabled) router.replace("/mfa/setup");
    else if (!workspace) router.replace("/");
  }, [data, workspace, router]);

  const nav = visibleAdminNav(workspace?.permissions).map(({ key, label, href, icon }) => ({ key, label, href, icon }));

  return (
    <AppShell workspaceId="platform" nav={nav}>
      {children}
    </AppShell>
  );
}
