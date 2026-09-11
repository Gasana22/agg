import * as React from "react";
import type { LucideIcon } from "lucide-react";

import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

const IconInput = React.forwardRef<
  HTMLInputElement,
  React.ComponentProps<"input"> & { icon: LucideIcon }
>(({ className, icon: Icon, ...props }, ref) => (
  <div className="relative">
    <Icon className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-500" />
    <Input
      ref={ref}
      className={cn(
        "border-slate-700 bg-slate-900/60 pl-9 text-slate-100 placeholder:text-slate-500 focus-visible:border-blue-500 focus-visible:ring-blue-500/50",
        className
      )}
      {...props}
    />
  </div>
));
IconInput.displayName = "IconInput";

export { IconInput };
