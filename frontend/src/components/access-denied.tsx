import Link from "next/link";
import { ShieldAlert } from "lucide-react";

import { Card, CardContent } from "@/components/ui/card";

/**
 * Renders in place of a page's content when its queries 403 -- the sidebar
 * already hides links a role can't use, but that doesn't guard the route
 * itself, so a bookmark, shared link, or stale nav state can still land
 * someone here. Without this the page was stuck on "Loading..." forever
 * (see isForbidden() in lib/utils.ts and the retry:false wiring in
 * app/providers.tsx that stops it from retrying a 403 for ~20s first).
 */
export function AccessDenied({ message }: { message?: string }) {
  return (
    <Card>
      <CardContent className="flex flex-col items-center gap-3 py-16 text-center">
        <ShieldAlert className="size-8 text-muted-foreground" />
        <div className="flex flex-col gap-1">
          <p className="text-sm font-medium">You don&apos;t have access to this page</p>
          <p className="text-sm text-muted-foreground">
            {message ?? "Your role on this farm doesn't include this section."}
          </p>
        </div>
        <Link href="/dashboard" className="text-sm text-primary underline-offset-4 hover:underline">
          Back to Overview
        </Link>
      </CardContent>
    </Card>
  );
}
