import type { components } from "@/lib/api/schema";

export type Worker = components["schemas"]["Worker"];
export type Activity = components["schemas"]["Activity"];
export type Task = components["schemas"]["Task"];
export type TaskLogEntry = components["schemas"]["TaskLogEntry"];
export type Attendance = components["schemas"]["Attendance"];
export type Leave = components["schemas"]["Leave"];
export type MyDay = components["schemas"]["MyDay"];

type Tone = "primary" | "warning" | "neutral" | "danger" | "success" | "info";

export const EMPLOYMENT_TYPES = ["permanent", "casual", "contract", "seasonal"] as const;
export const LEAVE_KINDS = ["annual", "sick", "compassionate", "unpaid", "other"] as const;
export const SUBJECT_TYPES = ["general", "crop_cycle", "plot", "location", "animal", "animal_group"] as const;

export const TASK_STATUS: Record<string, { label: string; tone: Tone }> = {
  assigned: { label: "To do", tone: "neutral" },
  in_progress: { label: "In progress", tone: "info" },
  paused: { label: "Paused", tone: "warning" },
  submitted: { label: "To verify", tone: "warning" },
  verified: { label: "Verified", tone: "success" },
  rejected: { label: "Redo", tone: "danger" },
  cancelled: { label: "Cancelled", tone: "neutral" },
};

export const LEAVE_TONE: Record<string, Tone> = { requested: "warning", approved: "success", rejected: "danger", cancelled: "neutral" };

export const EVENT_LABEL: Record<string, string> = {
  start: "Started",
  pause: "Paused",
  resume: "Resumed",
  submit: "Submitted",
  verify: "Verified",
  reject: "Sent back",
  cancel: "Cancelled",
  note: "Note",
};

/** The steps a worker can take on their own task, in the order they appear. */
export function workerActions(status: string): ("start" | "pause" | "resume" | "submit")[] {
  switch (status) {
    case "assigned":
    case "rejected":
      return ["start"];
    case "in_progress":
      return ["pause", "submit"];
    case "paused":
      return ["resume", "submit"];
    default:
      return [];
  }
}

/** "45 min", "2 h 5 min", "3 h". */
export function formatMinutes(minutes: number | null | undefined): string {
  if (minutes === null || minutes === undefined) return "—";
  if (minutes < 60) return `${minutes} min`;
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m ? `${h} h ${m} min` : `${h} h`;
}

/** Local time of an ISO instant, e.g. "07:05". */
export function formatClock(iso: string | null | undefined): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", hour12: false });
}

/** The browser's position, or null when it is unavailable or refused. */
export function currentPosition(timeoutMs = 8000): Promise<{ lat: number; lng: number; accuracy_m: number } | null> {
  if (typeof navigator === "undefined" || !navigator.geolocation) return Promise.resolve(null);
  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: round(p.coords.latitude, 6), lng: round(p.coords.longitude, 6), accuracy_m: round(p.coords.accuracy, 1) }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: timeoutMs, maximumAge: 60_000 },
    );
  });
}

function round(n: number, digits: number) {
  const f = 10 ** digits;
  return Math.round(n * f) / f;
}
