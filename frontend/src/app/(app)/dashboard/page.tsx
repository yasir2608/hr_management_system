"use client";

import { SetupChecklist } from "@/components/company/SetupChecklist";
import { HrDashboard } from "@/components/dashboard/HrDashboard";
import { useAuth } from "@/lib/auth";

function greeting(hour: number) {
  return hour < 12 ? "Good morning" : hour < 18 ? "Good afternoon" : "Good evening";
}

/**
 * Dashboard from the visual design: greeting and date, then the dashboard for the viewer's role.
 * People with company-wide people access get the owner / HR dashboard.
 */
export default function DashboardPage() {
  const { user, company, can } = useAuth();
  const firstName = user?.name.split(" ")[0] ?? "";
  const now = new Date();

  return (
    <>
      <div className="pagehead">
        <div>
          <h1>{greeting(now.getHours())}, {firstName} 👋</h1>
          <p className="subtitle">Here’s what’s happening at {company?.name} today.</p>
        </div>
        <span className="tiny" style={{ paddingTop: 6 }}>{now.toLocaleDateString(undefined, { weekday: "long", month: "long", day: "numeric" })}</span>
      </div>
      <SetupChecklist compact />
      {can("employees.view", "employees.manage") && <HrDashboard />}
    </>
  );
}
