"use client";

import { Monitor, Moon, Sun } from "lucide-react";

import { useTheme } from "@/components/theme/theme-provider";
import { Button } from "@/components/ui/button";

const NEXT = { light: "dark", dark: "system", system: "light" } as const;
const LABEL = { light: "Light theme", dark: "Dark theme", system: "System theme" } as const;

export function ThemeToggle() {
  const { theme, setTheme } = useTheme();
  const Icon = theme === "light" ? Sun : theme === "dark" ? Moon : Monitor;

  return (
    <Button variant="ghost" size="icon" onClick={() => setTheme(NEXT[theme])} aria-label={`${LABEL[theme]} (click to change)`} title={LABEL[theme]}>
      <Icon />
    </Button>
  );
}
