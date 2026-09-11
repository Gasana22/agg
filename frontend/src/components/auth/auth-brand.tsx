import { Sprout } from "lucide-react";

import { cn } from "@/lib/utils";

export function AuthBrandMark({ className }: { className?: string }) {
  return (
    <div className={cn("inline-flex items-center gap-2.5", className)}>
      <span className="flex size-9 shrink-0 items-center justify-center rounded-xl border border-blue-400/30 bg-blue-500/15 text-blue-400">
        <Sprout className="size-5" />
      </span>
      <span className="flex flex-col leading-tight">
        <span className="text-sm font-semibold text-white">Farmsap</span>
        <span className="text-[11px] text-slate-400">Farm Management Platform</span>
      </span>
    </div>
  );
}
