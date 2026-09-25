"use client";

import { useParams } from "next/navigation";

import { CompanyPage } from "@/components/portal/company";

export default function SupplierCompanyPage() {
  const { partyId } = useParams<{ partyId: string }>();
  return <CompanyPage partyId={partyId} />;
}
