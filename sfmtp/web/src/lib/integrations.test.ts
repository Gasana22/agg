import { describe, expect, it } from "vitest";

import { PROVIDER_SETTINGS, webhookUrl } from "./integrations";

describe("integration helpers", () => {
  it("builds the payment webhook URL", () => {
    expect(webhookUrl("https://api.sfmtp.app/", "flutterwave")).toBe("https://api.sfmtp.app/api/v1/webhooks/payments/flutterwave");
  });

  it("documents the settings of every provider the admin can add", () => {
    for (const provider of ["africas_talking", "twilio", "smtp", "sendgrid", "openweather", "tomorrow_io", "mapbox", "google", "flutterwave", "fcm"]) {
      expect(PROVIDER_SETTINGS[provider]).toBeTruthy();
    }
    expect(PROVIDER_SETTINGS.mapbox).toContain("pk.");
  });
});
