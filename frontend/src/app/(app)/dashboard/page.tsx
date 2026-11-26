"use client";

import { PageHead, Panel } from "@/components/ui";
import { useAuth } from "@/lib/auth";

export default function DashboardPage() {
  const { user, company } = useAuth();
  const firstName = user?.name.split(" ")[0] ?? "";

  return (
    <>
      <PageHead eyebrow="Overview" title={`Welcome back, ${firstName}`} subtitle={`Here is what needs attention at ${company?.name} today.`} />
      <Panel title="Getting started">
        <p className="muted" style={{ margin: 0 }}>Your workspace is ready. More areas appear in the menu as they are set up.</p>
      </Panel>
    </>
  );
}
