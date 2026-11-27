"use client";

import { Guard } from "@/components/Guard";
import { NotificationsTab } from "@/components/company/NotificationsTab";
import { PayrollCountriesTab } from "@/components/company/PayrollCountriesTab";
import { PreferencesTab } from "@/components/company/PreferencesTab";
import { ProfileTab } from "@/components/company/ProfileTab";
import { RegionalTab } from "@/components/company/RegionalTab";
import { SetupChecklist } from "@/components/company/SetupChecklist";
import { StructureTab } from "@/components/company/StructureTab";
import { PageHead } from "@/components/ui";
import { useTab } from "@/lib/useTab";

const tabs = [
  { key: "checklist", label: "Setup checklist", render: () => <SetupChecklist /> },
  { key: "profile", label: "Company profile", render: () => <ProfileTab /> },
  { key: "countries", label: "Payroll countries", render: () => <PayrollCountriesTab /> },
  { key: "structure", label: "Locations & teams", render: () => <StructureTab /> },
  { key: "regional", label: "Regional settings", render: () => <RegionalTab /> },
  { key: "preferences", label: "Leave & attendance", render: () => <PreferencesTab /> },
  { key: "notifications", label: "Notifications", render: () => <NotificationsTab /> },
];

function CompanySetupPage() {
  const [active, setActive] = useTab(tabs.map((tab) => tab.key));
  const current = tabs.find((tab) => tab.key === active) ?? tabs[0];

  return (
    <>
      <PageHead eyebrow="Settings" title="Company setup" subtitle="Configure company details, work structure, regional settings and payroll countries." />
      <div className="tabs" role="tablist">
        {tabs.map((tab) => (
          <button key={tab.key} role="tab" aria-selected={tab.key === active} className={`tab${tab.key === active ? " on" : ""}`} onClick={() => setActive(tab.key)}>
            {tab.label}
          </button>
        ))}
      </div>
      {current.render()}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["company.settings.view", "company.settings.manage"]}>
      <CompanySetupPage />
    </Guard>
  );
}
