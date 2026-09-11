"use client";

import * as React from "react";
import Link from "next/link";
import { AnimatePresence, motion } from "framer-motion";
import { ArrowRight, QrCode, ShieldCheck, Sprout } from "lucide-react";
import type { LucideIcon } from "lucide-react";

type Slide = {
  icon: LucideIcon;
  badge: string;
  headline: string;
  body: string;
  quote: string;
  author: string;
};

const SLIDES: Slide[] = [
  {
    icon: Sprout,
    badge: "Multi-farm management",
    headline: "Run every farm from a single dashboard.",
    body: "Crops, livestock, workers, and finances for every farm you manage, organized in one place.",
    quote: "Farmsap cut our weekly reporting time in half.",
    author: "AGG Farm — Operations",
  },
  {
    icon: ShieldCheck,
    badge: "Role-based access",
    headline: "Give every worker exactly the access they need.",
    body: "Agronomists, accountants, and field workers each see only the tools their role requires.",
    quote: "Onboarding a new farm manager now takes minutes, not days.",
    author: "AGG Farm — Human Resources",
  },
  {
    icon: QrCode,
    badge: "Full traceability",
    headline: "Track produce from the field to the sale.",
    body: "Every harvest, delivery, and sale is logged and traceable back to its plot of origin.",
    quote: "We can answer any buyer's traceability question in seconds.",
    author: "AGG Farm — Quality Assurance",
  },
];

export function AuthShowcasePanel({
  ctaHref,
  ctaLabel,
}: {
  ctaHref: string;
  ctaLabel: string;
}) {
  const [index, setIndex] = React.useState(0);

  React.useEffect(() => {
    const timer = window.setInterval(() => {
      setIndex((i) => (i + 1) % SLIDES.length);
    }, 5000);
    return () => window.clearInterval(timer);
  }, []);

  const slide = SLIDES[index];
  const Icon = slide.icon;

  return (
    <div className="relative flex h-full flex-col justify-between overflow-hidden bg-gradient-to-br from-blue-600 via-blue-800 to-slate-950 p-8 sm:p-10">
      <div
        aria-hidden
        className="pointer-events-none absolute -top-24 -right-24 size-72 rounded-full bg-blue-400/20 blur-3xl"
      />
      <div
        aria-hidden
        className="pointer-events-none absolute -bottom-32 -left-16 size-80 rounded-full bg-blue-300/10 blur-3xl"
      />

      <div className="relative flex items-center gap-2">
        {SLIDES.map((s, i) => (
          <button
            key={s.badge}
            type="button"
            onClick={() => setIndex(i)}
            aria-label={`Show slide ${i + 1}: ${s.badge}`}
            aria-current={i === index}
            className={`h-1.5 rounded-full transition-all ${
              i === index ? "w-6 bg-white" : "w-1.5 bg-white/30 hover:bg-white/50"
            }`}
          />
        ))}
      </div>

      <div className="relative">
        <AnimatePresence mode="wait">
          <motion.div
            key={index}
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -8 }}
            transition={{ duration: 0.3 }}
          >
            <span className="inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-3 py-1 text-xs font-medium text-white">
              <Icon className="size-3.5" />
              {slide.badge}
            </span>
            <h2 className="mt-5 text-3xl font-semibold text-white">{slide.headline}</h2>
            <p className="mt-3 max-w-sm text-sm text-blue-100/80">{slide.body}</p>
            <Link
              href={ctaHref}
              className="mt-6 inline-flex items-center gap-1.5 rounded-md border border-white/30 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-white/10"
            >
              {ctaLabel}
              <ArrowRight className="size-4" />
            </Link>
          </motion.div>
        </AnimatePresence>
      </div>

      <div className="relative border-t border-white/10 pt-6">
        <AnimatePresence mode="wait">
          <motion.div
            key={index}
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.3 }}
          >
            <p className="text-sm text-blue-100/70 italic">&ldquo;{slide.quote}&rdquo;</p>
            <p className="mt-2 text-xs font-medium text-blue-200/60">{slide.author}</p>
          </motion.div>
        </AnimatePresence>
      </div>
    </div>
  );
}
