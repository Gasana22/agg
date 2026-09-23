import Link from "next/link";

import { EmptyState } from "@/components/ui/misc";
import { formatRelative } from "@/lib/format";

export type ActionListItem = { id: string; title: string; subtitle?: string; at?: string; href?: string | null };

export function ActionListWidget({ data }: { data: { items?: ActionListItem[]; total?: number } }) {
  const items = data.items ?? [];
  if (items.length === 0) return <EmptyState title="Nothing here yet" />;

  return (
    <ul className="divide-y divide-border">
      {items.map((item) => {
        const body = (
          <div className="flex items-start justify-between gap-4 py-2.5">
            <div className="min-w-0">
              <p className="truncate text-sm font-medium">{item.title}</p>
              {item.subtitle ? <p className="truncate text-xs text-muted">{item.subtitle}</p> : null}
            </div>
            {item.at ? (
              <time dateTime={item.at} className="shrink-0 text-xs text-muted">
                {formatRelative(item.at)}
              </time>
            ) : null}
          </div>
        );
        return <li key={item.id}>{item.href ? <Link href={item.href} className="block hover:bg-surface-muted/60">{body}</Link> : body}</li>;
      })}
    </ul>
  );
}
