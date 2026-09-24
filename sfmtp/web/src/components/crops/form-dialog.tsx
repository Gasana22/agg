"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Dialog } from "@/components/ui/dialog";
import { ApiError } from "@/lib/api/errors";

/**
 * A dialog around a form: submits FormData to `onSubmit`, shows the API's
 * problem title for errors that aren't about one field, and hands field
 * errors to the form through `error`.
 */
export function FormDialog({
  title,
  description,
  submitLabel,
  onClose,
  onSubmit,
  children,
}: {
  title: string;
  description?: string;
  submitLabel: string;
  onClose: () => void;
  onSubmit: (form: FormData) => Promise<void>;
  children: (error: ApiError | null) => React.ReactNode;
}) {
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    try {
      await onSubmit(new FormData(e.currentTarget));
    } catch (err) {
      setError(err instanceof ApiError ? err : null);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open onClose={onClose} title={title} description={description}>
      <form onSubmit={submit} className="max-h-[70vh] space-y-3 overflow-y-auto pr-1">
        {children(error)}
        {error && !error.problem.errors ? (
          <p className="text-sm text-danger" role="alert">
            {error.problem.title}
          </p>
        ) : null}
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" disabled={busy}>
            {busy ? "Saving…" : submitLabel}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

/** FormData helpers: trimmed text or null, numbers or null. */
export function text(f: FormData, key: string): string | null {
  const v = String(f.get(key) ?? "").trim();
  return v === "" ? null : v;
}

export function num(f: FormData, key: string): number | null {
  const v = text(f, key);
  return v === null ? null : Number(v);
}
