"use client";

import { useParams, useRouter } from "next/navigation";
import { useEffect } from "react";

import { AppShell } from "@/components/shell/app-shell";
import { ErrorNotice, Skeleton } from "@/components/ui/misc";
import { useFarmWorkspace } from "@/lib/api/hooks";
import { visibleNav } from "@/lib/permissions";

export default function FarmLayout({ children }: { children: React.ReactNode }) {
  const { farmId } = useParams<{ farmId: string }>();
  const router = useRouter();
  const { workspace, data, error, isLoading } = useFarmWorkspace(farmId);

  useEffect(() => {
    if (data?.mfaRequired && !data.mfaEnabled) router.replace("/mfa/setup");
  }, [data, router]);

  if (isLoading) {
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-10 w-64" />
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }
  if (error) return <div className="p-6"><ErrorNotice error={error} /></div>;
  if (!workspace) {
    return (
      <div className="grid min-h-dvh place-items-center p-6 text-center">
        <div>
          <p className="text-lg font-medium">Farm not found</p>
          <p className="mt-1 text-sm text-muted">It may not exist, or you may not be a member.</p>
        </div>
      </div>
    );
  }

  const nav = visibleNav(workspace).map((item) => ({ key: item.key, label: item.label, href: item.href(farmId), icon: item.icon }));
  const banner =
    workspace.status === "pending" ? (
      <div className="border-b border-accent/30 bg-accent/10 px-6 py-2 text-sm">
        This farm is awaiting approval by SFMTP. You can set it up in the meantime.
      </div>
    ) : null;

  return (
    <AppShell workspaceId={farmId} nav={nav} banner={banner}>
      {children}
    </AppShell>
  );
}
