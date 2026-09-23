"use client";

import { useQuery } from "@tanstack/react-query";
import { useParams } from "next/navigation";

import { Badge } from "@/components/ui/badge";
import { ErrorNotice, PageHeader, Skeleton, Table, Td, Th } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatDateTime } from "@/lib/format";

export default function MembersPage() {
  const { farmId } = useParams<{ farmId: string }>();
  const { data, error, isLoading } = useQuery({
    queryKey: ["members", farmId],
    queryFn: async () => (await api.GET("/farms/{farm}/members", { params: { path: { farm: farmId }, query: { per_page: 100 } } })).data!.data!,
  });

  return (
    <>
      <PageHeader title="Members" description="People with access to this farm and the roles they hold." />
      {isLoading ? (
        <Skeleton className="h-48 w-full" />
      ) : error ? (
        <ErrorNotice error={error} />
      ) : (
        <Table>
          <thead>
            <tr>
              <Th>Name</Th>
              <Th>Roles</Th>
              <Th>Status</Th>
              <Th>Joined</Th>
            </tr>
          </thead>
          <tbody>
            {data!.map((m) => (
              <tr key={m.id}>
                <Td>
                  <p className="font-medium">{m.user?.name}</p>
                  <p className="text-xs text-muted">{m.user?.email}</p>
                </Td>
                <Td>
                  <div className="flex flex-wrap gap-1">
                    {m.roles?.map((r) => (
                      <Badge key={r.key} tone={r.key === "owner" ? "primary" : "neutral"}>
                        {r.name}
                      </Badge>
                    ))}
                  </div>
                </Td>
                <Td className="capitalize">{m.status}</Td>
                <Td className="text-muted">{formatDateTime(m.joined_at)}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </>
  );
}
