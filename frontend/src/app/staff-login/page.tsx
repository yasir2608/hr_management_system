import type { Metadata } from "next";

/**
 * The staff console's sign-in: the shared sign-in page (it adapts to the admin address) with the console's own
 * tab title and icon. Served at admin.../login by src/proxy.ts.
 */
export const metadata: Metadata = {
  title: "Staff sign-in · M.Y HRMS",
  icons: { icon: "/icon-staff.svg" },
};

export { default } from "../login/page";
