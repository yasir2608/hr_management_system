"use client";

import { SetupChecklist } from "@/components/company/SetupChecklist";
import { EmployeeDashboard } from "@/components/dashboard/EmployeeDashboard";
import { HrDashboard } from "@/components/dashboard/HrDashboard";
import { ManagerDashboard } from "@/components/dashboard/ManagerDashboard";
import { PayrollDashboard } from "@/components/dashboard/PayrollDashboard";
import { useAuth } from "@/lib/auth";

function greeting(hour: number) {
  return hour < 12 ? "Good morning" : hour < 18 ? "Good afternoon" : "Good evening";
}

/**
 * Dashboard from the visual design: greeting and date, then the dashboard for the viewer's role.
 * HR (people who manage employee records) get the owner / HR dashboard, managers the team dashboard, and people
 * with payroll access also get the payroll dashboard. Everyone else gets their own "My HR" dashboard.
 */
export default function DashboardPage() {
  const { user, company, can, access } = useAuth();
  const isHr = can("employees.manage");
  const isManager = !isHr && (access?.roles.includes("manager") ?? false);
  const isPayroll = can("payroll.view", "payroll.run", "payroll.settings.manage");
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
      {isHr && <HrDashboard />}
      {isManager && <ManagerDashboard />}
      {isPayroll && <PayrollDashboard heading={isHr || isManager} />}
      {!isHr && !isManager && !isPayroll && can("self.profile.view") && <EmployeeDashboard />}
    </>
  );
}
