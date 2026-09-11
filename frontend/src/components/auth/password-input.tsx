"use client";

import * as React from "react";
import { Eye, EyeOff, Lock } from "lucide-react";

import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

const PasswordInput = React.forwardRef<HTMLInputElement, React.ComponentProps<"input">>(
  ({ className, ...props }, ref) => {
    const [visible, setVisible] = React.useState(false);

    return (
      <div className="relative">
        <Lock className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-500" />
        <Input
          ref={ref}
          type={visible ? "text" : "password"}
          className={cn(
            "border-slate-700 bg-slate-900/60 pr-9 pl-9 text-slate-100 placeholder:text-slate-500 focus-visible:border-blue-500 focus-visible:ring-blue-500/50",
            className
          )}
          {...props}
        />
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          aria-label={visible ? "Hide password" : "Show password"}
          className="absolute top-1/2 right-3 -translate-y-1/2 text-slate-500 transition-colors hover:text-slate-300"
        >
          {visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
        </button>
      </div>
    );
  }
);
PasswordInput.displayName = "PasswordInput";

export { PasswordInput };
