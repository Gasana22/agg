"use client";

import { useParams } from "next/navigation";

import { CompanyPage } from "@/components/portal/company";

export default function CustomerCompanyPage() {
  const { partyId } = useParams<{ partyId: string }>();
  return <CompanyPage partyId={partyId} />;
}
