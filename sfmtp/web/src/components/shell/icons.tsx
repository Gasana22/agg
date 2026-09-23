import { LayoutDashboard, type LucideIcon, Route, ScrollText, Settings, ShieldCheck, Users } from "lucide-react";

export const NAV_ICONS: Record<string, LucideIcon> = {
  dashboard: LayoutDashboard,
  trace: Route,
  members: Users,
  roles: ShieldCheck,
  audit: ScrollText,
  settings: Settings,
};
