/**
 * Integration config is edited as KEY=value lines. Empty values become null
 * (which removes the key server-side); masked secrets (••••1234) are sent back
 * unchanged, and the API keeps the stored value.
 */
export function parseConfig(text: string): Record<string, string | null> {
  const out: Record<string, string | null> = {};
  for (const line of text.split("\n")) {
    const i = line.indexOf("=");
    if (i > 0) out[line.slice(0, i).trim()] = line.slice(i + 1).trim() || null;
  }
  return out;
}

export function configText(config: Record<string, string> | undefined): string {
  return Object.entries(config ?? {})
    .map(([k, v]) => `${k}=${v}`)
    .join("\n");
}
