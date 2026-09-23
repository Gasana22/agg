import { cn } from "@/lib/utils";

/** SFMTP wordmark with a sprout mark (inline SVG, inherits colour). */
export function Logo({ className, compact = false }: { className?: string; compact?: boolean }) {
  return (
    <span className={cn("inline-flex items-center gap-2 font-semibold tracking-tight", className)}>
      <svg viewBox="0 0 32 32" aria-hidden className="size-7 text-primary">
        <path fill="currentColor" d="M16 29c-.6 0-1-.4-1-1V17.6C10.3 17.2 6 13.6 6 8V6.5c0-.3.2-.5.5-.5H8c4.6 0 8.4 3.1 9.6 7.3C18.9 9 22.6 6 27 6h.5c.3 0 .5.2.5.5V8c0 5.5-4.2 9.9-9.5 10.4-.3 0-.5.3-.5.6V28c0 .6-.4 1-1 1Z" />
      </svg>
      {compact ? null : <span className="text-lg">SFMTP</span>}
    </span>
  );
}
