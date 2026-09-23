import { CheckCircle2, Circle } from "lucide-react";
import Link from "next/link";

type Item = { key: string; label: string; done: boolean; href?: string | null };

export function ChecklistWidget({ data }: { data: { items?: Item[] } }) {
  const items = data.items ?? [];
  const done = items.filter((i) => i.done).length;

  return (
    <div>
      <div className="mb-3 h-1.5 overflow-hidden rounded-full bg-surface-muted" role="progressbar" aria-valuenow={done} aria-valuemin={0} aria-valuemax={items.length}>
        <div className="h-full rounded-full bg-primary transition-all" style={{ width: `${items.length ? (done / items.length) * 100 : 0}%` }} />
      </div>
      <ul className="space-y-2">
        {items.map((item) => {
          const Icon = item.done ? CheckCircle2 : Circle;
          const label = <span className={item.done ? "text-muted line-through" : ""}>{item.label}</span>;
          return (
            <li key={item.key} className="flex items-center gap-2.5 text-sm">
              <Icon className={item.done ? "size-4 text-primary" : "size-4 text-muted"} aria-hidden />
              {item.href && !item.done ? <Link href={item.href} className="hover:underline">{label}</Link> : label}
              <span className="sr-only">{item.done ? "(done)" : "(to do)"}</span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
