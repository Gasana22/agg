"use client";

import Link from "next/link";
import { useParams, usePathname, useRouter } from "next/navigation";
import { useEffect } from "react";

import { AppShell } from "@/components/shell/app-shell";
import { buttonVariants } from "@/components/ui/button";
import { ErrorNotice, Skeleton } from "@/components/ui/misc";
import { useFarmWorkspace, type Workspace } from "@/lib/api/hooks";
import { formatDateTime } from "@/lib/format";
import { visibleNav } from "@/lib/permissions";

function Banner({ tone, children }: { tone: "warning" | "info"; children: React.ReactNode }) {
  const cls = tone === "warning" ? "border-accent/30 bg-accent/10" : "border-primary/20 bg-primary-soft";
  return <div className={`border-b px-6 py-2 text-sm ${cls}`}>{children}</div>;
}

function banners(workspace: Workspace, farmId: string): React.ReactNode[] {
  const out: React.ReactNode[] = [];
  const sub = workspace.subscription;

  if (workspace.type === "support" && workspace.support_access) {
    out.push(
      <Banner key="support" tone="info">
        Read-only support access, granted by the farm owner, until {formatDateTime(workspace.support_access.expires_at)}. Every page you open is logged.
      </Banner>,
    );
  }
  if (workspace.status === "pending") {
    out.push(<Banner key="pending" tone="warning">This farm is awaiting approval by SFMTP. You can set it up in the meantime.</Banner>);
  }
  if (sub?.status === "grace") {
    out.push(
      <Banner key="grace" tone="warning">
        The subscription ended on {sub.current_period_end}. Farms stay open until {sub.grace_until}.{" "}
        {workspace.is_owner ? <Link href={`/farms/${farmId}/billing`} className="font-medium underline">Renew now</Link> : "Ask the farm owner to renew."}
      </Banner>,
    );
  }
  if (workspace.is_owner && workspace.support_access) {
    out.push(
      <Banner key="support-owner" tone="info">
        SFMTP support can view this farm (read-only) until {formatDateTime(workspace.support_access.expires_at)}.{" "}
        <Link href={`/farms/${farmId}/support`} className="font-medium underline">Manage access</Link>
      </Banner>,
    );
  }
  return out;
}

export default function FarmLayout({ children }: { children: React.ReactNode }) {
  const { farmId } = useParams<{ farmId: string }>();
  const pathname = usePathname();
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
  const blocked = workspace.status === "suspended" || workspace.subscription?.status === "suspended" || workspace.subscription?.status === "cancelled";
  const allowedWhileBlocked = pathname.endsWith("/billing") || pathname.includes("/support");

  return (
    <AppShell workspaceId={farmId} nav={blocked ? nav.filter((n) => n.key === "billing" || n.key === "support") : nav} banner={banners(workspace, farmId)}>
      {blocked && !allowedWhileBlocked ? <Blocked workspace={workspace} farmId={farmId} /> : children}
    </AppShell>
  );
}

function Blocked({ workspace, farmId }: { workspace: Workspace; farmId: string }) {
  const bySubscription = workspace.status !== "suspended";
  return (
    <div className="mx-auto max-w-lg rounded-2xl border border-border bg-surface p-8 text-center">
      <h1 className="text-xl font-semibold">{bySubscription ? "This farm is paused" : "This farm is suspended"}</h1>
      <p className="mt-2 text-sm text-muted">
        {bySubscription
          ? "The subscription is not active. Your data is safe and returns as soon as it is renewed."
          : "SFMTP has suspended access to this farm. Your data is safe. Contact support to resolve it."}
      </p>
      <div className="mt-6 flex justify-center gap-2">
        {bySubscription && workspace.is_owner ? (
          <Link href={`/farms/${farmId}/billing`} className={buttonVariants()}>
            Renew subscription
          </Link>
        ) : null}
        <Link href={`/farms/${farmId}/support`} className={buttonVariants({ variant: "secondary" })}>
          Contact support
        </Link>
      </div>
    </div>
  );
}
