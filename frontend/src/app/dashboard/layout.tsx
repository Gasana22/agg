"use client";

import * as React from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
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
  Bell,
  FileText,
  QrCode,
  LogOut,
} from "lucide-react";

import { useAuth } from "@/lib/auth-context";
import { useFarm } from "@/lib/farm-context";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { formatRole } from "@/lib/utils";

const NAV_ITEMS = [
  { href: "/dashboard", label: "Overview", icon: LayoutDashboard },
  { href: "/dashboard/farms", label: "Farm Structure", icon: Map },
  { href: "/dashboard/crops", label: "Crop Management", icon: Sprout },
  { href: "/dashboard/livestock", label: "Livestock", icon: Beef },
  { href: "/dashboard/workers", label: "Workers", icon: Users },
  { href: "/dashboard/activities", label: "Activity Tracking", icon: ClipboardList },
  { href: "/dashboard/finance", label: "Finance", icon: Wallet },
  { href: "/dashboard/procurement", label: "Procurement", icon: Truck },
  { href: "/dashboard/inventory", label: "Inventory", icon: Boxes },
  { href: "/dashboard/assets", label: "Assets", icon: Wrench },
  { href: "/dashboard/reports", label: "Reports & Analytics", icon: BarChart3 },
  { href: "/dashboard/traceability", label: "Traceability", icon: QrCode },
  { href: "/dashboard/notifications", label: "Notifications", icon: Bell },
  { href: "/dashboard/documents", label: "Media & Documents", icon: FileText },
];

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const { user, platformRoles, loading, logout } = useAuth();
  const { farms, currentFarmId, setCurrentFarmId } = useFarm();
  const router = useRouter();

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

  return (
    <div className="flex flex-1">
      <aside className="hidden w-64 shrink-0 flex-col border-r bg-card md:flex">
        <div className="border-b px-4 py-4">
          <p className="text-sm font-semibold">SFMTP</p>
          <p className="text-xs text-muted-foreground">Farm Management & Traceability</p>
        </div>
        {farms.length > 0 && (
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
          {NAV_ITEMS.map(({ href, label, icon: Icon }) => (
            <Link
              key={href}
              href={href}
              className="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-foreground/80 hover:bg-accent hover:text-accent-foreground"
            >
              <Icon className="size-4" />
              {label}
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
          <Button
            variant="ghost"
            size="sm"
            className="mt-2 w-full justify-start gap-2"
            onClick={() => logout()}
          >
            <LogOut className="size-4" />
            Log out
          </Button>
        </div>
      </aside>
      <main className="flex-1 overflow-y-auto p-6">{children}</main>
    </div>
  );
}
