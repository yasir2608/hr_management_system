"use client";

import { Guard } from "@/components/Guard";
import { StartersTab } from "@/components/onboarding/StartersTab";
import { TemplatesTab } from "@/components/onboarding/TemplatesTab";
import { PageHead } from "@/components/ui";
import { useTab } from "@/lib/useTab";

const tabs = [
  { key: "starters", label: "New starters", render: () => <StartersTab /> },
  { key: "templates", label: "Checklists", render: () => <TemplatesTab /> },
];

function OnboardingPage() {
  const [active, setActive] = useTab(tabs.map((tab) => tab.key));
  const current = tabs.find((tab) => tab.key === active) ?? tabs[0];

  return (
    <>
      <PageHead eyebrow="People" title="Onboarding" subtitle="Company checklists for new starters, and each starter's progress." />
      <div className="tabs" role="tablist">
        {tabs.map((tab) => (
          <button key={tab.key} role="tab" aria-selected={tab.key === active} className={`tab${tab.key === active ? " on" : ""}`} onClick={() => setActive(tab.key)}>{tab.label}</button>
        ))}
      </div>
      {current.render()}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["onboarding.manage"]}>
      <OnboardingPage />
    </Guard>
  );
}
