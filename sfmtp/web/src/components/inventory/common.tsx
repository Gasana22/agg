"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";

import { cn } from "@/lib/utils";

/** Tabs kept in the URL (`?tab=`), the first one being the default. */
export function TabBar({ base, tabs, active, label }: { base: string; tabs: readonly { key: string; label: string }[]; active: string; label: string }) {
  const router = useRouter();
  return (
    <div role="tablist" aria-label={label} className="mb-4 flex gap-1 overflow-x-auto border-b border-border">
      {tabs.map((t, i) => (
        <button
          key={t.key}
          role="tab"
          type="button"
          aria-selected={active === t.key}
          onClick={() => router.replace(i === 0 ? base : `${base}?tab=${t.key}`)}
          className={cn("-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm", active === t.key ? "border-primary font-medium text-foreground" : "border-transparent text-muted hover:text-foreground")}
        >
          {t.label}
        </button>
      ))}
    </div>
  );
}

/** Runs one-click actions (approve, send …), keeping the last error to show and which one is busy. */
export function useActions(after: () => Promise<unknown>) {
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState<string | null>(null);
  async function run(key: string, action: () => Promise<unknown>) {
    setError(null);
    setBusy(key);
    try {
      await action();
      await after();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(null);
    }
  }
  return { error, busy, run, setError };
}
