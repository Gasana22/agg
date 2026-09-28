import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { WeatherView } from "./weather";

describe("WeatherView", () => {
  it("shows the current weather, the days ahead and the advisories", () => {
    render(
      <WeatherView
        weather={{
          available: true,
          provider: "openweather",
          current: { temp_c: 24.3, humidity_pct: 70, wind_kmh: 10.8, condition: "partly_cloudy", description: "Scattered clouds" },
          daily: [
            { date: "2026-09-28", min_c: 17, max_c: 28, rain_mm: 24.5, rain_chance_pct: 90, condition: "rain" },
            { date: "2026-09-29", min_c: 16, max_c: 26, rain_mm: 0, rain_chance_pct: 10, condition: "clear" },
          ],
          advisories: [{ kind: "heavy_rain", severity: "warning", message: "Heavy rain expected on 2026-09-28 (24.5 mm): check drainage and avoid spraying." }],
        }}
      />,
    );
    expect(screen.getByText("24 °C")).toBeInTheDocument();
    expect(screen.getByText(/Scattered clouds · humidity 70% · wind 11 km\/h/)).toBeInTheDocument();
    expect(screen.getByRole("list", { name: "Forecast by day" }).children).toHaveLength(2);
    expect(screen.getByText("90% · 24.5 mm")).toBeInTheDocument();
    expect(screen.getByText(/Heavy rain expected/)).toBeInTheDocument();
    expect(screen.getByText("Forecast by OpenWeather")).toBeInTheDocument();
  });

  it("explains why there is no forecast", () => {
    const { rerender } = render(<WeatherView weather={{ available: false, reason: "no_location" }} />);
    expect(screen.getByText(/Map a block, plot or location/)).toBeInTheDocument();
    rerender(<WeatherView weather={{ available: false, reason: "not_configured" }} />);
    expect(screen.getByText("No weather provider is set up yet.")).toBeInTheDocument();
  });
});
