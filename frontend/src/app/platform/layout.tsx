import { Suspense } from "react";
import { PlatformShell } from "@/components/PlatformShell";

export default function PlatformLayout({ children }: { children: React.ReactNode }) {
  // The shell reads the URL (current page, record ids), which is only known per request.
  return (
    <Suspense fallback={<div className="loadingpage">Loading the staff console…</div>}>
      <PlatformShell>{children}</PlatformShell>
    </Suspense>
  );
}
