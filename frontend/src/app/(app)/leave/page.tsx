"use client";

import { Guard } from "@/components/Guard";
import { BalancesTab } from "@/components/leave/BalancesTab";
import { LeaveTypesTab } from "@/components/leave/LeaveTypesTab";
import { PoliciesTab } from "@/components/leave/PoliciesTab";
import { PageHead } from "@/components/ui";
import { useAuth } from "@/lib/auth";
import { useTab } from "@/lib/useTab";

type TabDef = { key: string; label: string; permissions: string[]; render: () => React.ReactNode };

const tabs: TabDef[] = [
  { key: "types", label: "Leave types", permissions: ["leave.policies.manage", "leave.requests.view", "self.leave.request"], render: () => <LeaveTypesTab /> },
  { key: "balances", label: "Balances", permissions: ["leave.requests.view", "leave.balances.manage"], render: () => <BalancesTab /> },
  { key: "policies", label: "Policies", permissions: ["leave.policies.manage"], render: () => <PoliciesTab /> },
];

function LeavePage() {
  const { can } = useAuth();
  const visible = tabs.filter((tab) => can(...tab.permissions));
  const [active, setActive] = useTab(visible.map((tab) => tab.key));
  const current = visible.find((tab) => tab.key === active) ?? visible[0];

  return (
    <>
      <PageHead eyebrow="Time off" title="Leave" subtitle="Manage company leave policies, employee balances, requests, and decisions." />
      <div className="tabs" role="tablist">
        {visible.map((tab) => (
          <button key={tab.key} role="tab" aria-selected={tab.key === current?.key} className={`tab${tab.key === current?.key ? " on" : ""}`} onClick={() => setActive(tab.key)}>{tab.label}</button>
        ))}
      </div>
      {current?.render()}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["leave.policies.manage", "leave.requests.view", "leave.approve", "self.leave.request"]}>
      <LeavePage />
    </Guard>
  );
}
