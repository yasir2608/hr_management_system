"use client";

import { SetupChecklist } from "@/components/company/SetupChecklist";
import { PageHead } from "@/components/ui";
import { useAuth } from "@/lib/auth";

export default function DashboardPage() {
  const { user, company } = useAuth();
  const firstName = user?.name.split(" ")[0] ?? "";

  return (
    <>
      <PageHead eyebrow="Overview" title={`Welcome back, ${firstName}`} subtitle={`Here is what needs attention at ${company?.name} today.`} />
      <SetupChecklist compact />
    </>
  );
}
