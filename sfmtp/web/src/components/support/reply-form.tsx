"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Checkbox, FieldError, Textarea } from "@/components/ui/input";
import { ApiError } from "@/lib/api/errors";

export function ReplyForm({ onSend, allowInternal = false }: { onSend: (body: string, internal: boolean) => Promise<unknown>; allowInternal?: boolean }) {
  const [error, setError] = useState<ApiError | null>(null);
  const [pending, setPending] = useState(false);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = e.currentTarget;
    const f = new FormData(form);
    setPending(true);
    setError(null);
    try {
      await onSend(String(f.get("body")), f.get("internal") === "on");
      form.reset();
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    } finally {
      setPending(false);
    }
  }

  return (
    <form onSubmit={onSubmit} className="space-y-3">
      <Textarea name="body" aria-label="Reply" placeholder="Write a reply…" required maxLength={10000} />
      <div className="flex flex-wrap items-center justify-between gap-3">
        {allowInternal ? <Checkbox name="internal" label="Internal note (not visible to the farm)" /> : <span />}
        <Button type="submit" disabled={pending}>Send</Button>
      </div>
      {error ? <FieldError>{error.problem.title}</FieldError> : null}
    </form>
  );
}
