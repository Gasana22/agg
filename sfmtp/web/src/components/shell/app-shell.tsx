"use client";

import { Menu, X } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useState } from "react";

import { Logo } from "@/components/brand/logo";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

import { NAV_ICONS } from "./icons";
import { ThemeToggle } from "./theme-toggle";
import { UserMenu } from "./user-menu";
import { WorkspaceSwitcher } from "./workspace-switcher";

export type ShellNavItem = { key: string; label: string; href: string; icon: string };

export function AppShell({
  workspaceId,
  nav,
  banner,
  children,
}: {
  workspaceId: string;
  nav: ShellNavItem[];
  banner?: React.ReactNode;
  children: React.ReactNode;
}) {
  const pathname = usePathname();
  const [open, setOpen] = useState(false);

  // A section is active on its own page and its sub-pages. Dashboards are
  // workspace roots ("/admin", "/farms/{id}"), so they only match exactly
  // or on a /dashboard/ page.
  const isActive = (item: ShellNavItem) =>
    item.key === "dashboard"
      ? pathname === item.href || pathname.includes("/dashboard/")
      : pathname === item.href || pathname.startsWith(item.href + "/");

  const links = (
    <nav aria-label="Main" className="space-y-1">
      {nav.map((item) => {
        const Icon = NAV_ICONS[item.icon];
        const active = isActive(item);
        return (
          <Link
            key={item.key}
            href={item.href}
            onClick={() => setOpen(false)}
            aria-current={active ? "page" : undefined}
            className={cn(
              "flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors",
              active ? "bg-primary-soft text-primary" : "text-muted hover:bg-surface-muted hover:text-foreground",
            )}
          >
            {Icon ? <Icon className="size-4" aria-hidden /> : null}
            {item.label}
          </Link>
        );
      })}
    </nav>
  );

  return (
    <div className="min-h-dvh lg:grid lg:grid-cols-[15rem_1fr]">
      <aside className="hidden border-r border-border bg-surface lg:block">
        <div className="sticky top-0 flex h-dvh flex-col gap-6 p-4">
          <Link href="/" className="px-2 pt-1">
            <Logo />
          </Link>
          {links}
        </div>
      </aside>

      {open ? (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation">
          <div className="absolute inset-0 bg-black/40" onClick={() => setOpen(false)} />
          <div className="absolute inset-y-0 left-0 w-72 max-w-[85vw] space-y-6 bg-surface p-4 shadow-xl">
            <div className="flex items-center justify-between">
              <Logo />
              <Button variant="ghost" size="icon" onClick={() => setOpen(false)} aria-label="Close menu">
                <X />
              </Button>
            </div>
            {links}
          </div>
        </div>
      ) : null}

      <div className="flex min-w-0 flex-col">
        <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-border bg-surface/90 px-4 backdrop-blur sm:px-6">
          <Button variant="ghost" size="icon" className="lg:hidden" onClick={() => setOpen(true)} aria-label="Open menu">
            <Menu />
          </Button>
          <div className="min-w-0 flex-1">
            <WorkspaceSwitcher current={workspaceId} />
          </div>
          <ThemeToggle />
          <UserMenu />
        </header>
        {banner}
        <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:py-8">{children}</main>
      </div>
    </div>
  );
}
