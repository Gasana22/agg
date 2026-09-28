/** The settings each provider adapter reads (ADR-0018), shown as a hint in the admin form. */
export const PROVIDER_SETTINGS: Record<string, string> = {
  africas_talking: "username=…\napi_key=…\nsender_id=SFMTP",
  twilio: "account_sid=AC…\nauth_token=…\nfrom=+1…   (or messaging_service_sid=MG…)",
  smtp: "host=smtp.example.com\nport=587\nusername=…\npassword=…\nfrom_address=noreply@example.com\nfrom_name=SFMTP",
  sendgrid: "api_key=SG.…\nfrom_address=noreply@example.com\nfrom_name=SFMTP",
  openweather: "api_key=…   (One Call API 3.0)",
  tomorrow_io: "api_key=…",
  mapbox: "access_token=pk.…   (a public token restricted to your domains)\nstyle=mapbox/satellite-streets-v12",
  google: "api_key=…   (Map Tiles API, restricted to your domains)\nmap_type=satellite\nregion=UG",
  flutterwave: "secret_key=FLWSECK-…\nwebhook_hash=…   (the secret hash on the Flutterwave dashboard)\npayment_options=mobilemoneyuganda,card",
  manual: "(no settings: payments are recorded by hand)",
  fcm: "service_account={…}   (the Firebase service account JSON on one line)",
  quickbooks: "(not connected yet: export the Journal report and import it)",
  xero: "(not connected yet: export the Journal report and import it)",
};

export const HEALTH_TONE: Record<string, "success" | "warning" | "danger" | "neutral"> = { ok: "success", degraded: "warning", down: "danger", unknown: "neutral" };

/** The webhook URL a payment gateway must call. */
export function webhookUrl(apiBase: string, provider: string): string {
  return `${apiBase.replace(/\/$/, "")}/api/v1/webhooks/payments/${provider}`;
}
