"use client";

import * as React from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import {
  LayoutDashboard,
  Sprout,
  Beef,
  Users,
  ClipboardList,
  Wallet,
  Truck,
  Boxes,
  Wrench,
  BarChart3,
  Map,
  MapPinned,
  Bell,
  FileText,
  QrCode,
  LogOut,
  Menu,
} from "lucide-react";

import { useAuth } from "@/lib/auth-context";
import { useFarm } from "@/lib/farm-context";
import { ConfirmProvider } from "@/components/confirm-provider";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from "@/components/ui/sheet";
import { getUnreadNotificationCount } from "@/lib/modules/notifications";
import { formatRole } from "@/lib/utils";

// `roles` restricts a farm-scoped nav item to the listed farm roles.
// `farmScoped: false` marks the few items that are about the *system*
// (or personal to the user) rather than a specific farm's operations —
// those are the only ones system_administrator sees, since admin
// manages the platform, not any one farm's day-to-day (see
// User::isSystemAdministrator()'s doc comment on the backend: it no
// longer bypasses canViewFarm()/canManageFarm(), only Farm-record
// access itself does). Everything else defaults to farmScoped: true.
//
// Within farm-scoped items, most are viewAny = canViewFarm() on the
// backend — any farm member can see them, even if only specific roles
// can create/edit/delete within them — except these two, which the
// backend gates even for viewing:
// - WorkerProfilePolicy::viewAny requires canManageFarm() || canManageFinance()
//   — listing every worker's pay rate is manager territory, plus the
//   accountant, who needs it to pick who to run payroll for.
// - ExpensePolicy::viewAny requires canManageFinance() — bookkeeping data
//   is farm_owner/farm_manager/accountant territory, not any member's.
const NAV_ITEMS = [
  { href: "/dashboard", label: "Overview", icon: LayoutDashboard, farmScoped: false },
  { href: "/dashboard/farms", label: "Farm Structure", icon: Map, farmScoped: false },
  { href: "/dashboard/crops", label: "Crop Management", icon: Sprout },
  { href: "/dashboard/livestock", label: "Livestock", icon: Beef },
  { href: "/dashboard/workers", label: "Workers", icon: Users, roles: ["farm_owner", "farm_manager", "accountant"] },
  { href: "/dashboard/activities", label: "Activity Tracking", icon: ClipboardList },
  { href: "/dashboard/finance", label: "Finance", icon: Wallet, roles: ["farm_owner", "farm_manager", "accountant"] },
  { href: "/dashboard/procurement", label: "Procurement", icon: Truck },
  { href: "/dashboard/inventory", label: "Inventory", icon: Boxes },
  { href: "/dashboard/assets", label: "Assets", icon: Wrench },
  { href: "/dashboard/map", label: "Maps & GIS", icon: MapPinned },
  { href: "/dashboard/reports", label: "Reports & Analytics", icon: BarChart3, farmScoped: false },
  { href: "/dashboard/traceability", label: "Traceability", icon: QrCode },
  { href: "/dashboard/notifications", label: "Notifications", icon: Bell, farmScoped: false },
  { href: "/dashboard/documents", label: "Media & Documents", icon: FileText },
] satisfies { href: string; label: string; icon: React.ElementType; roles?: string[]; farmScoped?: boolean }[];

type NavContentProps = {
  isSystemAdministrator: boolean;
  farms: ReturnType<typeof useFarm>["farms"];
  currentFarmId: number | null;
  setCurrentFarmId: (id: number) => void;
  visibleNavItems: typeof NAV_ITEMS;
  unreadCount: number | undefined;
  user: NonNullable<ReturnType<typeof useAuth>["user"]>;
  platformRoles: string[];
  onLogout: () => void;
  /** Called after a nav link is clicked -- lets the mobile Sheet close itself. */
  onNavigate?: () => void;
};

function NavContent({
  isSystemAdministrator,
  farms,
  currentFarmId,
  setCurrentFarmId,
  visibleNavItems,
  unreadCount,
  user,
  platformRoles,
  onLogout,
  onNavigate,
}: NavContentProps) {
  return (
    <div className="flex h-full flex-col">
      <div className="border-b px-4 py-4">
        <p className="text-sm font-semibold">Farmsap</p>
        <p className="text-xs text-muted-foreground">Farm Management & Traceability</p>
      </div>
      {!isSystemAdministrator && farms.length > 0 && (
        <div className="border-b px-3 py-3">
          <p className="mb-1.5 px-1 text-xs font-medium text-muted-foreground">Current farm</p>
          <Select
            value={currentFarmId ? String(currentFarmId) : undefined}
            onValueChange={(v) => setCurrentFarmId(Number(v))}
          >
            <SelectTrigger className="h-9 w-full">
              <SelectValue placeholder="Select a farm" />
            </SelectTrigger>
            <SelectContent>
              {farms.map((farm) => (
                <SelectItem key={farm.id} value={String(farm.id)}>
                  {farm.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}
      <nav className="flex-1 space-y-1 overflow-y-auto p-2">
        {visibleNavItems.map(({ href, label, icon: Icon }) => (
          <Link
            key={href}
            href={href}
            onClick={onNavigate}
            className="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-foreground/80 hover:bg-accent hover:text-accent-foreground"
          >
            <Icon className="size-4" />
            {label}
            {href === "/dashboard/notifications" && !!unreadCount && unreadCount > 0 && (
              <span
                aria-hidden="true"
                className="ml-auto flex size-5 items-center justify-center rounded-full bg-primary text-xs text-primary-foreground"
              >
                {unreadCount > 9 ? "9+" : unreadCount}
              </span>
            )}
          </Link>
        ))}
      </nav>
      <div className="border-t p-3">
        <p className="truncate text-sm font-medium">{user.name}</p>
        {platformRoles.length > 0 ? (
          <p className="truncate text-xs text-muted-foreground">
            {platformRoles.map(formatRole).join(", ")} (platform)
          </p>
        ) : user.farms && user.farms.length > 0 ? (
          <div className="mt-0.5 flex flex-col gap-0.5">
            {user.farms.map((farm) => (
              <p key={farm.id} className="truncate text-xs text-muted-foreground">
                {formatRole(farm.pivot.role_on_farm)} · {farm.name}
              </p>
            ))}
          </div>
        ) : (
          <p className="truncate text-xs text-muted-foreground">No role assigned</p>
        )}
        <Button variant="ghost" size="sm" className="mt-2 w-full justify-start gap-2" onClick={onLogout}>
          <LogOut className="size-4" />
          Log out
        </Button>
      </div>
    </div>
  );
}

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const { user, platformRoles, loading, logout } = useAuth();
  const { farms, currentFarmId, currentFarm, setCurrentFarmId } = useFarm();
  const router = useRouter();
  const [mobileNavOpen, setMobileNavOpen] = React.useState(false);
  const isSystemAdministrator = platformRoles.includes("system_administrator");
  const myRole = currentFarm?.my_role ?? null;
  const visibleNavItems = isSystemAdministrator
    ? NAV_ITEMS.filter((item) => item.farmScoped === false)
    : NAV_ITEMS.filter((item) => !item.roles || (myRole !== null && item.roles.includes(myRole)));

  const { data: unreadCount } = useQuery({
    queryKey: ["notifications-unread-count"],
    queryFn: () => getUnreadNotificationCount(),
    enabled: !!user,
  });

  React.useEffect(() => {
    if (!loading && !user) {
      router.replace("/login");
    }
  }, [loading, user, router]);

  if (loading || !user) {
    return (
      <div className="flex flex-1 items-center justify-center">
        <p className="text-sm text-muted-foreground">Loading…</p>
      </div>
    );
  }

  const navContentProps = {
    isSystemAdministrator,
    farms,
    currentFarmId,
    setCurrentFarmId,
    visibleNavItems,
    unreadCount,
    user,
    platformRoles,
    onLogout: () => logout(),
  };

  return (
    <ConfirmProvider>
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-primary-foreground"
      >
        Skip to content
      </a>
      <div className="flex flex-1 flex-col md:flex-row">
        <header className="flex items-center gap-3 border-b bg-card px-4 py-3 md:hidden">
          <Sheet open={mobileNavOpen} onOpenChange={setMobileNavOpen}>
            <SheetTrigger asChild>
              <Button variant="ghost" size="icon" aria-label="Open navigation menu">
                <Menu className="size-5" />
              </Button>
            </SheetTrigger>
            <SheetContent>
              <SheetTitle className="sr-only">Navigation</SheetTitle>
              <NavContent {...navContentProps} onNavigate={() => setMobileNavOpen(false)} />
            </SheetContent>
          </Sheet>
          <p className="text-sm font-semibold">Farmsap</p>
        </header>
        <aside className="hidden w-64 shrink-0 flex-col border-r bg-card md:flex">
          <NavContent {...navContentProps} />
        </aside>
        <main id="main-content" tabIndex={-1} className="flex-1 overflow-y-auto p-4 md:p-6 focus:outline-none">
          {children}
        </main>
      </div>
    </ConfirmProvider>
  );
}
