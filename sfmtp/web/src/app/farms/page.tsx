"use client";

import { useQuery } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import Link from "next/link";

import { Logo } from "@/components/brand/logo";
import { UserMenu } from "@/components/shell/user-menu";
import { ThemeToggle } from "@/components/shell/theme-toggle";
import { Badge } from "@/components/ui/badge";
import { buttonVariants } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { EmptyState, ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { StatusBadge } from "@/components/ui/status";
import { api } from "@/lib/api/client";
import { formatHa } from "@/lib/structure";

/** "My farms": one card per farm the user belongs to, with what they may see there. */
export default function MyFarmsPage() {
  const farms = useQuery({
    queryKey: ["my-farms"],
    queryFn: async () => (await api.GET("/me/farms/overview")).data!.data!,
  });

  return (
    <div className="min-h-dvh bg-background">
      <header className="flex h-16 items-center justify-between border-b border-border bg-surface px-4 lg:px-8">
        <Link href="/" aria-label="Home">
          <Logo />
        </Link>
        <div className="flex items-center gap-2">
          <ThemeToggle />
          <UserMenu />
        </div>
      </header>
      <main className="mx-auto max-w-6xl px-4 py-8 lg:px-8">
        <PageHeader
          title="My farms"
          description="Every farm you work on."
          actions={
            <Link href="/onboarding" className={buttonVariants({ variant: "secondary" })}>
              <Plus /> New farm
            </Link>
          }
        />
        {farms.isLoading ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-44" />
            ))}
          </div>
        ) : farms.error ? (
          <ErrorNotice error={farms.error} />
        ) : farms.data!.length === 0 ? (
          <EmptyState title="You are not on any farm yet">Create a farm, or open the invitation link a farm sent you.</EmptyState>
        ) : (
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {farms.data!.map((f) => {
              const m = f.metrics ?? {};
              const stats: [string, string][] = [];
              if (m.size_ha !== undefined) stats.push(["Size", formatHa(m.size_ha)]);
              if (m.plots !== undefined) stats.push(["Plots", String(m.plots)]);
              if (m.mapped_area_ha !== undefined) stats.push(["Mapped", formatHa(m.mapped_area_ha)]);
              if (m.members !== undefined) stats.push(["Members", String(m.members)]);
              if (m.open_batches !== undefined) stats.push(["Open batches", String(m.open_batches)]);
              const href = f.home_dashboard ? `/farms/${f.id}/dashboard/${f.home_dashboard}` : `/farms/${f.id}`;

              return (
                <li key={f.id}>
                  <Link href={href} className="block h-full rounded-xl focus-visible:outline-2 focus-visible:outline-ring">
                    <Card className="h-full transition-colors hover:border-primary/50">
                      <CardContent className="space-y-3">
                        <div className="flex items-start justify-between gap-2">
                          <div className="min-w-0">
                            <h2 className="truncate font-semibold">{f.name}</h2>
                            <p className="text-xs text-muted">
                              {f.code}
                              {f.district ? ` · ${f.district}` : ""}
                            </p>
                          </div>
                          {f.status !== "active" ? <StatusBadge status={f.status} /> : null}
                        </div>
                        <div className="flex flex-wrap gap-1">
                          {(f.roles ?? []).map((r) => (
                            <Badge key={r} tone={f.is_owner ? "primary" : "neutral"}>
                              {r}
                            </Badge>
                          ))}
                        </div>
                        {stats.length > 0 ? (
                          <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                            {stats.map(([k, v]) => (
                              <div key={k}>
                                <dt className="text-xs text-muted">{k}</dt>
                                <dd className="font-medium">{v}</dd>
                              </div>
                            ))}
                          </dl>
                        ) : null}
                      </CardContent>
                    </Card>
                  </Link>
                </li>
              );
            })}
          </ul>
        )}
      </main>
    </div>
  );
}
