"use client";

import { useQuery } from "@tanstack/react-query";

import { DashboardView } from "@/components/dashboard/dashboard-view";
import { ErrorNotice, PageHeader, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";

export default function AdminDashboardPage() {
  const { data, error, isLoading } = useQuery({
    queryKey: ["admin-dashboard"],
    queryFn: async () => (await api.GET("/admin/dashboard")).data!.data!,
  });

  return (
    <>
      <PageHeader title="Platform" description="SaaS administration. Farm operational data is never shown here." />
      {isLoading ? <Skeleton className="h-40 w-full" /> : error ? <ErrorNotice error={error} /> : data ? <DashboardView dashboard={data} /> : null}
    </>
  );
}
