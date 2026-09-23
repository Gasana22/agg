import { Lock } from "lucide-react";

import type { components } from "@/lib/api/schema";
import { formatDateTime } from "@/lib/format";
import { cn } from "@/lib/utils";

type Ticket = components["schemas"]["Ticket"];

export function Thread({ ticket }: { ticket: Ticket }) {
  return (
    <ol className="space-y-4">
      {(ticket.messages ?? []).map((m) => (
        <li
          key={m.id}
          className={cn(
            "rounded-xl border p-4",
            m.is_internal ? "border-accent/40 bg-accent/10" : m.author?.is_staff ? "border-primary/20 bg-primary-soft/60" : "border-border bg-surface",
          )}
        >
          <div className="mb-1 flex flex-wrap items-center gap-2 text-sm">
            <span className="font-medium">{m.author?.name}</span>
            {m.author?.is_staff ? <span className="text-xs text-primary">SFMTP support</span> : null}
            {m.is_internal ? (
              <span className="inline-flex items-center gap-1 text-xs text-warning"><Lock className="size-3" aria-hidden /> internal note</span>
            ) : null}
            <time className="text-xs text-muted" dateTime={m.created_at}>{formatDateTime(m.created_at)}</time>
          </div>
          <p className="whitespace-pre-wrap text-sm">{m.body}</p>
        </li>
      ))}
    </ol>
  );
}
