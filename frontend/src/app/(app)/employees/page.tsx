"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Banner, EmptyState, PageHead, Panel } from "@/components/ui";
import { useAuth } from "@/lib/auth";

function EmployeesPage() {
  const { can } = useAuth();
  const [flash, setFlash] = useState<string | null>(null);

  useEffect(() => {
    try {
      const message = window.sessionStorage.getItem("myhrms.flash");
      if (message) {
        window.sessionStorage.removeItem("myhrms.flash");
        // eslint-disable-next-line react-hooks/set-state-in-effect -- read once from browser storage
        setFlash(message);
      }
    } catch {
      /* storage unavailable */
    }
  }, []);

  return (
    <>
      <PageHead
        eyebrow="People"
        title="Employees"
        subtitle="Keep employee details, employment history and payroll readiness in one place."
        actions={can("employees.manage") && <Link className="btn primary" href="/employees/new">Add employee</Link>}
      />
      {flash && <Banner tone="success">{flash}</Banner>}
      <Panel title="Directory">
        <EmptyState title="The employee directory is coming next" />
      </Panel>
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["employees.view", "employees.manage"]}>
      <EmployeesPage />
    </Guard>
  );
}
