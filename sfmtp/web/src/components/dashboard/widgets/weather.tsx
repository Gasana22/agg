"use client";

import { useQuery } from "@tanstack/react-query";
import { Cloud, CloudDrizzle, CloudFog, CloudLightning, CloudRain, CloudSnow, CloudSun, type LucideIcon, Sun } from "lucide-react";

import { ErrorNotice, Skeleton } from "@/components/ui/misc";
import { fetchHref } from "@/lib/api/client";
import type { components } from "@/lib/api/schema";
import { cn } from "@/lib/utils";

type Weather = components["schemas"]["Weather"];

export const CONDITION_ICON: Record<string, LucideIcon> = {
  clear: Sun,
  partly_cloudy: CloudSun,
  cloudy: Cloud,
  fog: CloudFog,
  drizzle: CloudDrizzle,
  rain: CloudRain,
  storm: CloudLightning,
  snow: CloudSnow,
};

const REASON: Record<string, string> = {
  no_location: "Map a block, plot or location to see the forecast at the farm.",
  not_configured: "No weather provider is set up yet.",
  unavailable: "The weather service is not answering. Try again later.",
};

const weekday = (date: string) => new Date(`${date}T12:00:00`).toLocaleDateString("en-GB", { weekday: "short" });

/** The forecast at the farm, with advisories (ADR-0018). Loaded separately: it may call a provider. */
export function WeatherWidget({ href }: { href: string }) {
  const { data, error, isLoading } = useQuery({
    queryKey: ["widget", href],
    queryFn: async () => (await fetchHref<{ data: Weather }>(href)).data,
    staleTime: 10 * 60_000,
  });
  if (isLoading) return <Skeleton className="h-40 w-full" />;
  if (error || !data) return <ErrorNotice error={error} />;
  return <WeatherView weather={data} />;
}

export function WeatherView({ weather: w }: { weather: Weather }) {
  if (!w.available) return <p className="py-6 text-center text-sm text-muted">{REASON[w.reason ?? "unavailable"]}</p>;
  const Now = CONDITION_ICON[w.current?.condition ?? "cloudy"] ?? Cloud;

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <Now className="size-10 text-accent" aria-hidden />
        <div>
          <p className="text-2xl font-semibold tabular-nums">{Math.round(w.current?.temp_c ?? 0)} °C</p>
          <p className="text-sm text-muted">
            {w.current?.description} · humidity {w.current?.humidity_pct}% · wind {Math.round(w.current?.wind_kmh ?? 0)} km/h
          </p>
        </div>
      </div>
      <ul className="grid grid-cols-4 gap-2 text-center text-xs sm:grid-cols-7" aria-label="Forecast by day">
        {(w.daily ?? []).map((d) => {
          const Icon = CONDITION_ICON[d.condition ?? "cloudy"] ?? Cloud;
          return (
            <li key={d.date} className="rounded-lg bg-surface-muted/60 p-2">
              <p className="font-medium">{weekday(d.date!)}</p>
              <Icon className="mx-auto my-1 size-5 text-muted" aria-label={d.condition} />
              <p className="tabular-nums">
                {Math.round(d.max_c ?? 0)}° <span className="text-muted">{Math.round(d.min_c ?? 0)}°</span>
              </p>
              <p className={cn("tabular-nums", (d.rain_chance_pct ?? 0) >= 60 ? "font-medium text-primary" : "text-muted")}>{d.rain_chance_pct}%{(d.rain_mm ?? 0) > 0 ? ` · ${d.rain_mm} mm` : ""}</p>
            </li>
          );
        })}
      </ul>
      {(w.advisories ?? []).length > 0 ? (
        <ul className="space-y-1 text-sm">
          {w.advisories!.map((a, i) => (
            <li key={i} className={cn("rounded-md px-3 py-2", a.severity === "warning" ? "border-l-4 border-warning bg-warning/10" : "border-l-4 border-primary bg-primary-soft")}>
              {a.message}
            </li>
          ))}
        </ul>
      ) : null}
      <p className="text-xs text-muted">Forecast by {w.provider === "tomorrow_io" ? "Tomorrow.io" : "OpenWeather"}</p>
    </div>
  );
}
