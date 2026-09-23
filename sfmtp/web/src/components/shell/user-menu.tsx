"use client";

import { useQueryClient } from "@tanstack/react-query";
import { LogOut } from "lucide-react";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { useMe } from "@/lib/api/hooks";

export function UserMenu() {
  const { data } = useMe();
  const queryClient = useQueryClient();
  const [pending, setPending] = useState(false);

  async function signOut() {
    setPending(true);
    await fetch("/api/auth/logout", { method: "POST" }).catch(() => undefined);
    queryClient.clear();
    // Full reload on purpose: nothing from the signed-out session survives in memory.
    // eslint-disable-next-line @next/next/no-location-assign-relative-destination
    window.location.assign("/login");
  }

  const name = data?.data?.name ?? "";
  const initials = name
    .split(" ")
    .map((p) => p[0])
    .slice(0, 2)
    .join("")
    .toUpperCase();

  return (
    <div className="flex items-center gap-2">
      <span className="hidden text-right text-sm sm:block">
        <span className="block font-medium leading-tight">{name}</span>
        <span className="block text-xs text-muted">{data?.data?.email}</span>
      </span>
      <span className="grid size-9 place-items-center rounded-full bg-primary-soft text-sm font-semibold text-primary" aria-hidden>
        {initials || "·"}
      </span>
      <Button variant="ghost" size="icon" onClick={signOut} disabled={pending} aria-label="Sign out" title="Sign out">
        <LogOut />
      </Button>
    </div>
  );
}
