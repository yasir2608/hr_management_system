import { Suspense } from "react";
import { AppShell } from "@/components/AppShell";

export default function AppLayout({ children }: { children: React.ReactNode }) {
  // The shell reads the URL (current page, record ids), which is only known per request.
  return (
    <Suspense fallback={<div className="loadingpage">Loading your workspace…</div>}>
      <AppShell>{children}</AppShell>
    </Suspense>
  );
}
