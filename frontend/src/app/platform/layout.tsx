import type { Metadata } from "next";
import { Suspense } from "react";
import { PlatformShell } from "@/components/PlatformShell";

/** Its own tab title and icon, so the staff console never looks like the company HRMS. */
export const metadata: Metadata = {
  title: "Staff console · M.Y HRMS",
  icons: { icon: "/icon-staff.svg" },
};

export default function PlatformLayout({ children }: { children: React.ReactNode }) {
  // The shell reads the URL (current page, record ids), which is only known per request.
  return (
    <Suspense fallback={<div className="loadingpage">Loading the staff console…</div>}>
      <PlatformShell>{children}</PlatformShell>
    </Suspense>
  );
}
