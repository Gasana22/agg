"use client";

import { useAuth } from "@/lib/auth-context";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

export default function DashboardPage() {
  const { user, roles } = useAuth();

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold">Welcome, {user?.name}</h1>
        <p className="text-sm text-muted-foreground">
          Signed in as {roles.join(", ") || "no role assigned"}.
        </p>
      </div>
      <Card>
        <CardHeader>
          <CardTitle>Platform skeleton</CardTitle>
        </CardHeader>
        <CardContent className="pb-6 text-sm text-muted-foreground">
          Authentication and RBAC are wired end-to-end. Each module in the sidebar
          is a placeholder route ready for its own dashboard, forms, and API
          integration as it is built out.
        </CardContent>
      </Card>
    </div>
  );
}
