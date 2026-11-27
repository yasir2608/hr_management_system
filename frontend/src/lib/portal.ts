/**
 * The product is served on two addresses from one app:
 * - the staff console (M.Y HRMS staff only), e.g. http://admin.localhost:3000 or https://admin.myhrms.com
 * - the company HRMS (customer companies), e.g. http://localhost:3000 or https://app.myhrms.com
 *
 * The staff console is recognised by its hostname starting with ADMIN_HOST_PREFIX.
 */
export type Portal = "staff" | "company";

export const ADMIN_HOST_PREFIX = "admin.";

export const ADMIN_URL = (process.env.NEXT_PUBLIC_ADMIN_URL ?? "http://admin.localhost:3000").replace(/\/$/, "");

export const APP_URL = (process.env.NEXT_PUBLIC_APP_URL ?? "http://localhost:3000").replace(/\/$/, "");

/** Staff console pages, served at these paths on the admin address (they live under app/platform). */
export const STAFF_PATHS = ["/overview", "/companies", "/users", "/support", "/activity", "/health", "/billing"];

/** Company sign-up and joining pages that only make sense on the company address. */
export const COMPANY_ONLY_PATHS = ["/register", "/accept-invitation", "/select-company"];

export function portalForHost(hostname: string): Portal {
  return hostname.startsWith(ADMIN_HOST_PREFIX) ? "staff" : "company";
}

/** In the browser: which address are we on? */
export function currentPortal(): Portal {
  return typeof window === "undefined" ? "company" : portalForHost(window.location.hostname);
}

export function matchesPath(pathname: string, paths: string[]) {
  return paths.some((path) => pathname === path || pathname.startsWith(`${path}/`));
}
