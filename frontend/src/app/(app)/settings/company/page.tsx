"use client";

import { useState } from "react";
import { PayrollCountriesTab } from "@/components/company/PayrollCountriesTab";
import { ProfileTab } from "@/components/company/ProfileTab";
import { PageHead } from "@/components/ui";

const tabs = [
  { key: "profile", label: "Company profile", render: () => <ProfileTab /> },
  { key: "countries", label: "Payroll countries", render: () => <PayrollCountriesTab /> },
];

export default function CompanySetupPage() {
  const [active, setActive] = useState(tabs[0].key);
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
