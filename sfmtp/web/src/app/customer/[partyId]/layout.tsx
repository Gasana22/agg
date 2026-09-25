"use client";

import { PortalLayout } from "@/components/portal/portal-layout";

export default function CustomerLayout({ children }: { children: React.ReactNode }) {
  return <PortalLayout kind="customer">{children}</PortalLayout>;
}
