import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";
import { ADMIN_URL, APP_URL, COMPANY_ONLY_PATHS, STAFF_PATHS, matchesPath, portalForHost } from "@/lib/portal";

/**
 * Splits one app into two addresses by hostname.
 *
 * Staff console (admin.*): "/" opens the overview; /companies, /users ... are served from app/platform;
 * old /platform/... links redirect to the clean path; company sign-up pages go to the company address.
 * Other company pages stay reachable there only so staff can view a company during approved, read-only support access.
 *
 * Company HRMS (any other host): staff console paths redirect to the admin address.
 */
export function proxy(request: NextRequest) {
  const { pathname, search } = request.nextUrl;
  // The Host header is the address the browser asked for; nextUrl reflects the server's own hostname in development.
  const host = (request.headers.get("x-forwarded-host") ?? request.headers.get("host") ?? request.nextUrl.host).split(",")[0].trim();
  const portal = portalForHost(host.replace(/:\d+$/, ""));

  if (portal === "staff") {
    if (pathname === "/") {
      return NextResponse.redirect(new URL(`/overview${search}`, request.url));
    }
    if (pathname === "/platform" || pathname.startsWith("/platform/")) {
      return NextResponse.redirect(new URL(`${pathname.replace(/^\/platform/, "") || "/overview"}${search}`, request.url));
    }
    if (matchesPath(pathname, COMPANY_ONLY_PATHS)) {
      return toOtherAddress(`${APP_URL}${pathname}${search}`, request);
    }
    if (matchesPath(pathname, STAFF_PATHS)) {
      return NextResponse.rewrite(new URL(`/platform${pathname}${search}`, request.url));
    }

    return NextResponse.next();
  }

  if (pathname === "/platform" || pathname.startsWith("/platform/")) {
    return toOtherAddress(`${ADMIN_URL}${pathname.replace(/^\/platform/, "") || "/overview"}${search}`, request);
  }

  return NextResponse.next();
}

/**
 * Redirect to the other address. Next shortens any redirect aimed at its own host (localhost:3000 in development)
 * to a relative path, which would keep the browser on the admin address, so that case gets a tiny page that moves on.
 */
function toOtherAddress(url: string, request: NextRequest) {
  if (new URL(url).host !== request.nextUrl.host) {
    return NextResponse.redirect(url);
  }
  const safe = url.replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;");
  return new NextResponse(
    `<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=${safe}"><title>Redirecting…</title><p>Continue to <a href="${safe}">${safe}</a></p>`,
    { status: 200, headers: { "Content-Type": "text/html; charset=utf-8", "Cache-Control": "no-store" } },
  );
}

export const config = {
  // Pages only: skip Next internals, the API and files with an extension (images, favicon ...).
  matcher: ["/((?!_next/|api/|.*\\.[^/]+$).*)"],
};
