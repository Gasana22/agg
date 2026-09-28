"use client";

import { useQuery } from "@tanstack/react-query";
import { CheckCircle2, Clock, XCircle } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";

import { AuthCard } from "@/components/auth/auth-card";
import { buttonVariants } from "@/components/ui/button";
import { ErrorNotice, Skeleton } from "@/components/ui/misc";
import { api } from "@/lib/api/client";
import { formatMoney } from "@/lib/inventory";

/** Waiting a minute at most for the gateway to confirm, then leave it to the webhook. */
const POLL_MS = 2500;
const GIVE_UP_MS = 60_000;

/**
 * Where the payment gateway sends the payer back (ADR-0018). The redirect
 * itself proves nothing: the page asks the API, which confirms the payment
 * with the gateway and records it.
 */
export default function PaymentReturnPage() {
  return (
    <Suspense>
      <PaymentReturn />
    </Suspense>
  );
}

function PaymentReturn() {
  const reference = useSearchParams().get("reference") ?? "";
  const [started] = useState(() => Date.now());
  const [gaveUp, setGaveUp] = useState(false);
  const payment = useQuery({
    queryKey: ["online-payment", reference],
    enabled: /^SFMTP-[A-Z0-9]{14}$/.test(reference),
    queryFn: async () => (await api.GET("/online-payments/{reference}", { params: { path: { reference } } })).data!.data!,
    refetchInterval: (q) => (q.state.data?.status === "pending" && !gaveUp ? POLL_MS : false),
  });

  useEffect(() => {
    const t = setTimeout(() => setGaveUp(true), GIVE_UP_MS - (Date.now() - started));
    return () => clearTimeout(t);
  }, [started]);

  const p = payment.data;
  return (
    <AuthCard title="Payment" subtitle={p ? `${p.description} · ${formatMoney(p.amount, p.currency)}` : "Checking your payment…"}>
      {!/^SFMTP-[A-Z0-9]{14}$/.test(reference) ? (
        <p className="text-sm text-danger">This link does not name a payment.</p>
      ) : payment.isLoading ? (
        <Skeleton className="h-24 w-full" />
      ) : payment.error ? (
        <ErrorNotice error={payment.error} />
      ) : p ? (
        <div className="space-y-4 text-sm" role="status">
          {p.status === "succeeded" ? (
            <p className="flex items-start gap-2 text-success">
              <CheckCircle2 className="mt-0.5 size-5 shrink-0" aria-hidden /> Paid. Thank you: it is recorded{p.purpose === "subscription" ? " and your subscription is renewed" : " against the invoice"}.
            </p>
          ) : p.status === "failed" || p.status === "cancelled" ? (
            <p className="flex items-start gap-2 text-danger">
              <XCircle className="mt-0.5 size-5 shrink-0" aria-hidden /> Not paid{p.failure_reason ? `: ${p.failure_reason}` : "."}
            </p>
          ) : (
            <p className="flex items-start gap-2 text-muted">
              <Clock className="mt-0.5 size-5 shrink-0" aria-hidden />
              {gaveUp
                ? "The payment service has not confirmed yet. You can leave this page: the payment is recorded as soon as it is confirmed."
                : "Waiting for the payment service to confirm. With mobile money, approve the prompt on your phone."}
            </p>
          )}
          <p className="text-xs text-muted">Reference {p.reference}</p>
          <Link href={p.return_path ?? "/"} className={buttonVariants({ variant: p.status === "succeeded" ? "primary" : "secondary" })}>
            {p.status === "failed" ? "Back to try again" : "Continue"}
          </Link>
        </div>
      ) : null}
    </AuthCard>
  );
}
