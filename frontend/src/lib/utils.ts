import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";
import { isAxiosError } from "axios";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

/** True when `error` is an Axios 403 -- the user is authenticated but not
 * authorized for this resource, as opposed to a network failure or a 401
 * (session expired, handled separately by the API client's interceptor). */
export function isForbidden(error: unknown): boolean {
  return isAxiosError(error) && error.response?.status === 403;
}

/** "system_administrator" -> "System Administrator" */
export function formatRole(role: string | null | undefined): string {
  if (!role) return "";
  return role
    .split("_")
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ");
}
