import { Logo } from "@/components/brand/logo";

export function AuthCard({ title, subtitle, children }: { title: string; subtitle?: string; children: React.ReactNode }) {
  return (
    <main className="grid min-h-dvh place-items-center bg-[radial-gradient(ellipse_at_top,var(--primary-soft),transparent_60%)] px-4 py-10">
      <div className="w-full max-w-sm">
        <Logo className="mb-8" />
        <div className="rounded-2xl border border-border bg-surface p-6 shadow-sm sm:p-8">
          <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
          {subtitle ? <p className="mt-1 text-sm text-muted">{subtitle}</p> : null}
          <div className="mt-6">{children}</div>
        </div>
        <p className="mt-6 text-center text-xs text-muted">Track every seed, every worker, every harvest, every sale.</p>
      </div>
    </main>
  );
}
