"use client";

import { PortalLayout } from "@/components/portal/portal-layout";

export default function SupplierLayout({ children }: { children: React.ReactNode }) {
  return <PortalLayout kind="supplier">{children}</PortalLayout>;
}
